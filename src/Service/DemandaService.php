<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\TosRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Preferencias;
use ProLink\Support\Validacao;

/**
 * Demandas técnicas: cadastro, publicação, acompanhamento e encerramento (RF04).
 *
 * ## Publicar é um ato separado de criar, e a data é que decide
 *
 * `dem_dt_publicacao` nula significa rascunho: a demanda existe, o dono a vê no painel e mais
 * ninguém. Não foi preciso uma situação `RASCUNHO` ao lado de `ABERTA` — a data já carrega o
 * fato, e duas fontes para o mesmo estado acabariam discordando. Publicar é irreversível na
 * prática: o que se faz depois é encerrar, que preserva o histórico.
 *
 * ## Sem código TOS não há publicação
 *
 * O motor enxerga a demanda **só** pelos códigos: nesta massa, `art_objeto` e `aat_descricao` não
 * discriminam ninguém. Uma demanda publicada sem código nenhum nunca encontraria candidato, e o
 * demandante concluiria que a plataforma está vazia. Por isso o código é exigido na publicação e
 * não na criação — dá para salvar o rascunho enquanto se procura o código certo.
 *
 * ## Os códigos são conferidos contra `crea_tos`
 *
 * Código vindo de formulário é entrada externa. Um código inexistente passaria pelo banco (não há
 * chave estrangeira para `crea_tos` em `pro_demanda_tos`, de propósito: a TOS é cache da API) e
 * viraria um critério que nunca casa com ninguém, sem nada na tela explicando o silêncio. Mesma
 * lição da D27 e da D29 — validar a forma do identificador não é validar que ele existe.
 */
final class DemandaService
{
    private const TITULO_MAXIMO = 190;
    private const ESCOPO_MAXIMO = 10000;

    /** P = profissional, E = empresa, A = ambos. Espelha o comentário de `dem_alvo`. */
    private const ALVOS = ['P', 'E', 'A'];

    /** Peso do código principal e do secundário, conforme `matching.md`. */
    public const PESO_PRINCIPAL  = 1.0;
    public const PESO_SECUNDARIO = 0.5;

    public function __construct(
        private readonly DemandaRepository $demandas = new DemandaRepository(),
        private readonly TosRepository $tos = new TosRepository(),
    ) {
    }

    /**
     * @param array<string, mixed> $entrada
     * @return int id da demanda criada
     */
    public function criar(int $usuarioId, array $entrada): int
    {
        $dados = $this->validar($entrada);

        return Database::transacao(function (PDO $pdo) use ($usuarioId, $dados): int {
            $id = $this->demandas->criar(['usuario_id' => $usuarioId, ...$dados]);

            Auditoria::registrar(
                Auditoria::CRIAR, 'pro_demandas', $id, null, null,
                ['titulo' => $dados['titulo']], $usuarioId, $pdo,
            );

            return $id;
        });
    }

    /** @param array<string, mixed> $entrada */
    public function editar(int $usuarioId, int $demandaId, array $entrada): void
    {
        $atual = $this->exigirPropria($usuarioId, $demandaId);
        $dados = $this->validar($entrada);

        Database::transacao(function (PDO $pdo) use ($usuarioId, $demandaId, $atual, $dados): void {
            $this->demandas->atualizar($demandaId, $dados);

            Auditoria::registrar(
                Auditoria::EDITAR, 'pro_demandas', $demandaId, null,
                ['titulo' => $atual['dem_titulo'], 'escopo' => $atual['dem_escopo']],
                $dados, $usuarioId, $pdo,
            );
        });
    }

    /**
     * Acrescenta, remove ou muda o peso de um código TOS da demanda.
     *
     * Um caminho de escrita só: a alteração é aplicada sobre o conjunto atual e gravada por
     * `sincronizarTos`, que já sabe encerrar o que saiu em vez de apagar. Duas rotas de escrita
     * para a mesma tabela acabariam divergindo sobre o que fazer com o código removido.
     *
     * @param string $acao adicionar | remover | principal | secundaria
     * @return array<string, float> o conjunto resultante
     */
    public function alterarTos(int $usuarioId, int $demandaId, string $codigo, string $acao): array
    {
        $this->exigirPropria($usuarioId, $demandaId);

        $codigo = trim($codigo);
        $atuais = [];

        foreach ($this->demandas->tos($demandaId) as $linha) {
            $atuais[(string) $linha['codigo']] = (float) $linha['peso'];
        }

        if ($acao === 'remover') {
            unset($atuais[$codigo]);
        } else {
            if ($this->tos->porCodigos([$codigo]) === []) {
                throw new ValidacaoException(
                    'Este código não existe na Tabela de Obras e Serviços.',
                    ['tos' => 'Código desconhecido: ' . $codigo],
                );
            }

            $atuais[$codigo] = $acao === 'secundaria' ? self::PESO_SECUNDARIO : self::PESO_PRINCIPAL;
        }

        self::exigirPrincipal($atuais, $acao);

        return Database::transacao(function (PDO $pdo) use ($usuarioId, $demandaId, $atuais, $codigo, $acao): array {
            $this->demandas->sincronizarTos($demandaId, $atuais);

            Auditoria::registrar(
                Auditoria::EDITAR, 'pro_demanda_tos', $demandaId, $codigo,
                null, $acao, $usuarioId, $pdo,
            );

            return $atuais;
        });
    }

    /**
     * Uma demanda com atividades tem sempre ao menos uma principal (D94).
     *
     * "Secundária" só quer dizer algo em relação a uma principal: o motor faz a média ponderada
     * pelos pesos, e com todas secundárias o peso some da conta e a marcação vira enfeite, que
     * diz à empresa uma coisa que não acontece. Vale para tornar secundária a única principal e
     * para remover a última principal deixando só secundárias. Conjunto vazio passa: é o
     * rascunho sem atividade, que a publicação já recusa.
     *
     * @param array<string, float> $pesos código => peso, já com a alteração aplicada
     * @throws ValidacaoException
     */
    public static function exigirPrincipal(array $pesos, string $acao = ''): void
    {
        if ($pesos === [] || max($pesos) >= self::PESO_PRINCIPAL) {
            return;
        }

        throw new ValidacaoException($acao === 'remover'
            ? 'Esta é a única atividade principal da demanda. Torne outra atividade principal antes de removê-la.'
            : 'A demanda precisa de ao menos uma atividade principal. Para tornar esta secundária, torne outra principal primeiro.');
    }

    /**
     * Publica a demanda: a partir daqui ela é visível e o motor a enxerga.
     *
     * @throws ValidacaoException sem código TOS não há o que compatibilizar
     */
    public function publicar(int $usuarioId, int $demandaId): void
    {
        $this->exigirPropria($usuarioId, $demandaId);

        if ($this->demandas->tos($demandaId) === []) {
            throw new ValidacaoException(
                'Escolha pelo menos um código da Tabela de Obras e Serviços antes de publicar. '
                . 'É por ele que a plataforma encontra quem já comprovou fazer este tipo de serviço.'
            );
        }

        Database::transacao(function (PDO $pdo) use ($usuarioId, $demandaId): void {
            $this->demandas->publicar($demandaId);

            Auditoria::registrar(
                Auditoria::EDITAR, 'pro_demandas', $demandaId, 'dem_dt_publicacao',
                null, 'publicada', $usuarioId, $pdo,
            );
        });
    }

    public function encerrar(int $usuarioId, int $demandaId): void
    {
        $atual = $this->exigirPropria($usuarioId, $demandaId);

        Database::transacao(function (PDO $pdo) use ($usuarioId, $demandaId, $atual): void {
            $this->demandas->mudarSituacao($demandaId, 'ENCERRADA');

            Auditoria::registrar(
                Auditoria::EDITAR, 'pro_demandas', $demandaId, 'dem_situacao',
                $atual['dem_situacao'], 'ENCERRADA', $usuarioId, $pdo,
            );
        });
    }

    /**
     * A demanda com os códigos e a contagem de interessados, para a tela do dono ou do visitante.
     *
     * @return array<string, mixed>|null
     */
    public function comTos(int $demandaId): ?array
    {
        $demanda = $this->demandas->porId($demandaId);

        if ($demanda === null || $demanda['dem_status'] !== STATUS_ATIVO) {
            return null;
        }

        $demanda['tos'] = array_map(
            static fn (array $t): array => $t + ['descricao' => TosRepository::descrever([
                'tos_grupo'        => $t['grupo'],
                'tos_subgrupo'     => $t['subgrupo'],
                'tos_obra_servico' => $t['obra_servico'],
                'tos_complementar' => $t['complementar'],
            ])],
            $this->demandas->tos($demandaId),
        );

        return $demanda;
    }

    /** @return array<string, mixed> a demanda, se for de quem está pedindo */
    public function exigirPropria(int $usuarioId, int $demandaId): array
    {
        $demanda = $this->demandas->porId($demandaId);

        $minha = $demanda !== null
            && (int) $demanda['dem_usu_id'] === $usuarioId
            && $demanda['dem_status'] === STATUS_ATIVO;

        if ($minha) {
            return $demanda;
        }

        // Mesma mensagem para "não existe" e "não é sua": distinguir contaria a quem tentou que
        // aquele id pertence a alguém (OWASP A01).
        Auditoria::registrar(
            Auditoria::ACESSO_NEGADO, 'pro_demandas', $demandaId, null, null,
            ['tentou' => $usuarioId], $usuarioId,
        );

        throw new ValidacaoException('Demanda não encontrada no seu painel.');
    }

    // ---------------------------------------------------------------- interno

    /**
     * @param array<string, mixed> $entrada
     * @return array{titulo: string, escopo: string, local_uf: ?string, local_municipio: ?string,
     *               tipo_contrato: ?string, inicio_ate: ?string, alvo: string}
     */
    private function validar(array $entrada): array
    {
        $titulo    = trim((string) ($entrada['titulo'] ?? ''));
        $escopo    = trim((string) ($entrada['escopo'] ?? ''));
        $uf        = mb_strtoupper(trim((string) ($entrada['local_uf'] ?? '')));
        $municipio = trim((string) ($entrada['local_municipio'] ?? ''));
        $contrato  = mb_strtoupper(trim((string) ($entrada['tipo_contrato'] ?? '')));
        $alvo      = trim((string) ($entrada['alvo'] ?? 'A'));
        // Até quando o trabalho precisa começar. Vazio é "prazo em aberto", escolha legítima; o
        // que se recusa é data que não existe ou que já passou (D78).
        $inicioAte = trim((string) ($entrada['inicio_ate'] ?? ''));

        $v = new Validacao();

        $v->obrigatorio('titulo', $titulo, 'Dê um título à demanda.')
          ->tamanhoMaximo('titulo', $titulo, self::TITULO_MAXIMO,
              sprintf('No máximo %d caracteres.', self::TITULO_MAXIMO));

        $v->obrigatorio('escopo', $escopo, 'Descreva o que precisa ser feito.')
          ->tamanhoMaximo('escopo', $escopo, self::ESCOPO_MAXIMO,
              sprintf('No máximo %d caracteres.', self::ESCOPO_MAXIMO));

        $v->exigir('local_uf', $uf === '' || in_array($uf, Preferencias::UFS, true),
            'UF desconhecida. Escolha uma da lista.');

        // Mesmo vocabulário do perfil, e não texto livre. Enquanto os dois lados eram livres, a
        // demanda dizia "obra certa" e o perfil dizia "OBRA_CERTA": a dimensão de contrato nunca
        // casava, e o demandante não tinha como saber por quê.
        $v->exigir('tipo_contrato', $contrato === '' || Preferencias::contratoValido($contrato),
            'Escolha um dos regimes da lista.');

        $v->entre('alvo', $alvo, self::ALVOS, 'Escolha quem pode atender esta demanda.');

        $v->data('inicio_ate', $inicioAte, 'Informe uma data válida, ou deixe o prazo em aberto.');
        $v->exigir('inicio_ate', $inicioAte === '' || $v->temErro('inicio_ate') || $inicioAte >= date('Y-m-d'),
            'O prazo de início não pode ser anterior a hoje.');

        $v->lancarSeInvalido();

        return [
            'titulo'          => $titulo,
            'escopo'          => $escopo,
            'local_uf'        => $uf === '' ? null : $uf,
            'local_municipio' => $municipio === '' ? null : $municipio,
            'tipo_contrato'   => $contrato === '' ? null : $contrato,
            'inicio_ate'      => $inicioAte === '' ? null : $inicioAte,
            'alvo'            => $alvo,
        ];
    }

}
