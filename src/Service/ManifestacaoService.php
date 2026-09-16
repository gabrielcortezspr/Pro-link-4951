<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\ParametroRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Validacao;

/**
 * Manifestação de interesse: a **operação atômica 3** da proposta (RF05, cenário 4 do Anexo I).
 *
 * ## O sentido do fluxo é candidato para demandante, e isso foi decidido
 *
 * Quem manifesta é quem quer o trabalho; o demandante recebe e responde. O mockup do feed chegou a
 * trazer o inverso, e a **D51** registra por que ele não foi seguido: o edital descreve o cenário
 * 4 nesta direção, o banco não tem onde guardar um convite, e empresa abordando candidatos
 * extraídos de um pool pontuado se parece com recrutamento ordenado — que é o que o item 10.1
 * veda.
 *
 * ## O snapshot congela o que o demandante podia ver, não o perfil inteiro
 *
 * `PerfilService::montar($candidato, $demandante)` é chamado **com o demandante como espectador**,
 * então o JSON gravado já passou pela `Visao`. Manifestar não abre campo que o titular tinha
 * fechado: o consentimento de manifestar é para *aquela demanda*, e tratá-lo como autorização
 * genérica sobreporia, sem aviso, a visibilidade campo a campo que a D22 estabeleceu.
 *
 * A contrapartida é honesta e obrigatória: quem tem quase tudo fechado manifesta e mostra pouco.
 * Por isso `previa()` existe — a tela pergunta antes de enviar, e quem quiser mostrar mais abre
 * no próprio perfil, conscientemente, em vez de descobrir depois que mostrou.
 *
 * ## Por que tudo numa transação
 *
 * Gravar a manifestação, mudar a situação da demanda, registrar na trilha e enfileirar o e-mail
 * são um fato só. Manifestação sem linha em `sis_auditoria` é registro sem procedência;
 * manifestação gravada com e-mail perdido é o candidato achando que avisou e o demandante nunca
 * tendo sabido. O envio em si acontece depois da resposta, fora daqui — enfileirar é o que
 * pertence à transação.
 */
final class ManifestacaoService
{
    /** Teto padrão por hora, quando `sis_parametros` não tem a chave. */
    private const LIMITE_HORA_PADRAO = 10;

    public function __construct(
        private readonly ManifestacaoRepository $manifestacoes = new ManifestacaoRepository(),
        private readonly DemandaRepository $demandas = new DemandaRepository(),
        private readonly PerfilService $perfis = new PerfilService(),
        private readonly PerfilEmpresaService $perfisEmpresa = new PerfilEmpresaService(),
        private readonly VisibilidadeService $visibilidade = new VisibilidadeService(),
        private readonly NotificacaoService $notificacoes = new NotificacaoService(),
        private readonly ParametroRepository $parametros = new ParametroRepository(),
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
    ) {
    }

    /**
     * O que o demandante veria se a manifestação fosse enviada agora.
     *
     * Serve à tela, antes do envio. Devolve o perfil já filtrado e uma contagem do que sobrou,
     * para a interface poder avisar quando o snapshot sairia praticamente vazio — o caso em que
     * a pessoa manifestaria interesse e o demandante receberia um nome e nada mais.
     *
     * @return array{perfil: array<string, mixed>|null, campos: int, arts: int, experiencias: int}
     */
    public function previa(int $candidatoId, int $demandaId): array
    {
        $demanda = $this->demandas->porId($demandaId);
        $perfil  = $demanda === null
            ? null
            : $this->montarSnapshot($candidatoId, (int) $demanda['dem_usu_id']);

        return [
            'perfil'       => $perfil,
            'campos'       => count(array_filter($perfil['campos'] ?? [], static fn ($v) => $v !== null)),
            'arts'         => count($perfil['arts'] ?? []),
            'experiencias' => count($perfil['experiencias'] ?? []),
        ];
    }

    /**
     * Grava a manifestação, move a demanda e enfileira o aviso.
     *
     * @throws ValidacaoException demanda inexistente, não publicada, própria, repetida, com o
     *                            perfil fechado, ou acima do limite por hora
     */
    public function manifestar(int $candidatoId, int $demandaId, string $mensagem = ''): int
    {
        $mensagem = trim($mensagem);

        (new Validacao())
            ->tamanhoMaximo('mensagem', $mensagem, 1000, 'No máximo 1000 caracteres.')
            ->lancarSeInvalido();

        $demanda = $this->demandas->porId($demandaId);

        if ($demanda === null || $demanda['dem_status'] !== STATUS_ATIVO) {
            throw new ValidacaoException('Demanda não encontrada.');
        }

        // Rascunho não existe para o resto da plataforma, e demanda encerrada não recebe mais
        // interessado. As duas recusas usam a mesma mensagem que a vitrine usaria.
        if ($demanda['dem_dt_publicacao'] === null || $demanda['dem_situacao'] === 'ENCERRADA') {
            throw new ValidacaoException('Esta demanda não está aberta a manifestações.');
        }

        if ((int) $demanda['dem_usu_id'] === $candidatoId) {
            throw new ValidacaoException('Você não pode manifestar interesse na própria demanda.');
        }

        if ($this->manifestacoes->jaManifestou($demandaId, $candidatoId)) {
            throw new ValidacaoException('Você já manifestou interesse nesta demanda.');
        }

        // O mesmo portão que decide o pool e a busca. Quem revogou a exibição não aparece em
        // lugar nenhum da plataforma, e manifestar seria uma porta lateral para aparecer.
        if (($this->visibilidade->perfisAbertos([$candidatoId])[$candidatoId] ?? false) !== true) {
            throw new ValidacaoException(
                'Seu perfil está fechado: com o consentimento de exibição revogado, ou com o '
                . 'registro irregular no CREA, o demandante não conseguiria ver nada do que você '
                . 'enviasse.'
            );
        }

        $this->exigirDentroDoLimite($candidatoId);

        $ehEmpresa = $this->usuarios->perfisAtivos([$candidatoId])[$candidatoId] === PERFIL_EMPRESA;
        $perfil    = $this->montarSnapshot($candidatoId, (int) $demanda['dem_usu_id']);

        if ($perfil === null) {
            throw new ValidacaoException('Não foi possível montar seu perfil para envio.');
        }

        $json = json_encode($perfil, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $destinatario = $this->usuarios->porId((int) $demanda['dem_usu_id']);

        return Database::transacao(function (PDO $pdo) use (
            $candidatoId, $demandaId, $demanda, $mensagem, $json, $ehEmpresa, $destinatario
        ): int {
            $id = (new ManifestacaoRepository($pdo))->criar([
                'demanda_id'     => $demandaId,
                'usuario_id'     => $candidatoId,
                'candidato_tipo' => $ehEmpresa ? 'E' : 'P',
                'mensagem'       => $mensagem === '' ? null : $mensagem,
                'snapshot'       => $json,
                // sha256 do mesmo JSON que foi gravado. Não protege contra quem tem o banco;
                // protege contra alteração acidental, que é o risco que existe de verdade.
                'hash'           => hash('sha256', $json),
            ]);

            // Só sobe de ABERTA: demanda já COM_INTERESSADOS não precisa ser reescrita a cada
            // novo interessado, e ENCERRADA nem chega aqui.
            if ($demanda['dem_situacao'] === 'ABERTA') {
                (new DemandaRepository($pdo))->mudarSituacao($demandaId, 'COM_INTERESSADOS');

                Auditoria::registrar(
                    Auditoria::EDITAR, 'pro_demandas', $demandaId, 'dem_situacao',
                    'ABERTA', 'COM_INTERESSADOS', $candidatoId, $pdo,
                );
            }

            Auditoria::registrar(
                Auditoria::CRIAR, 'pro_manifestacoes', $id, null, null,
                ['demanda' => $demandaId, 'snapshot_hash' => hash('sha256', $json)],
                $candidatoId, $pdo,
            );

            if ($destinatario !== null) {
                $this->notificacoes->enfileirar(
                    (int) $demanda['dem_usu_id'],
                    'MANIFESTACAO',
                    (string) $destinatario['usu_email'],
                    'Novo interessado em "' . $demanda['dem_titulo'] . '"',
                    'email/manifestacao.html.twig',
                    [
                        'demanda'      => $demanda['dem_titulo'],
                        'tem_mensagem' => $mensagem !== '',
                        'url'          => APP_URL . '/demandas/' . $demandaId . '/interessados',
                    ],
                );
            }

            return $id;
        });
    }

    /**
     * O limite por hora (proposta, A04).
     *
     * Existe para que a plataforma não vire disparo em massa: sem teto, um script manifesta em
     * toda demanda aberta em segundos, e o valor da manifestação — alguém leu a demanda e quis
     * aquela — vira ruído para todo demandante. Janela deslizante, e não hora cheia, senão quem
     * envia às 10h59 ganha cota nova um minuto depois.
     *
     * O teto mora em `sis_parametros` para o administrador ajustar sem deploy, como os pesos do
     * motor.
     *
     * @throws ValidacaoException
     */
    private function exigirDentroDoLimite(int $usuarioId): void
    {
        $limite = $this->parametros->inteiro('manifestacao.limite_hora', self::LIMITE_HORA_PADRAO);

        if ($limite <= 0) {
            return;
        }

        $desde    = (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
        $enviadas = $this->manifestacoes->quantasDesde($usuarioId, $desde);

        if ($enviadas >= $limite) {
            throw new ValidacaoException(sprintf(
                'Você já manifestou interesse em %d demandas na última hora, que é o limite. '
                . 'Tente de novo mais tarde.',
                $limite,
            ));
        }
    }

    /**
     * O perfil do candidato pelos olhos do demandante.
     *
     * Profissional e empresa têm serviços de montagem diferentes, e o snapshot precisa ser o que
     * a tela do demandante mostraria — por isso reusa a mesma montagem, e não uma serialização
     * própria que acabaria divergindo dela.
     *
     * @return array<string, mixed>|null
     */
    private function montarSnapshot(int $candidatoId, int $demandanteId): ?array
    {
        $ehEmpresa = ($this->usuarios->perfisAtivos([$candidatoId])[$candidatoId] ?? null)
            === PERFIL_EMPRESA;

        return $ehEmpresa
            ? $this->perfisEmpresa->montar($candidatoId, $demandanteId)
            : $this->perfis->montar($candidatoId, $demandanteId);
    }
}
