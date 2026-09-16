<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\MensagemRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;
use ProLink\Support\Validacao;

/**
 * O outro lado da manifestação: o demandante lê quem se interessou e as duas partes conversam.
 *
 * Separado de `ManifestacaoService` porque o ato de manifestar e o de acompanhar são coisas
 * diferentes — um é a operação atômica 3, escreve em três tabelas e é irreversível; o outro é
 * leitura e um insert simples. Juntá-los daria um serviço com duas razões para mudar.
 *
 * ## Quem pode ver uma manifestação
 *
 * Duas pessoas, e ninguém mais: quem manifestou e o dono da demanda. A checagem é uma só, em
 * `exigirParte()`, e devolve a mesma mensagem para "não existe" e "não é sua" — distinguir
 * contaria a quem tentou que aquele id existe (OWASP A01), do mesmo jeito que
 * `DemandaService::exigirPropria()` já faz.
 *
 * O administrador **não** entra. A D06 recusou superusuário implícito, e conversa entre duas
 * partes não é conteúdo publicado: moderá-la exigiria denúncia, que é o caminho da E6.
 *
 * ## O perfil vem do snapshot, nunca do cadastro
 *
 * A promessa da proposta é que "alterações posteriores não afetam o que a empresa já viu". Ler o
 * perfil vivo aqui seria quebrá-la silenciosamente — e ninguém notaria, porque na maioria das
 * vezes os dois são iguais.
 */
final class InteressadoService
{
    public function __construct(
        private readonly ManifestacaoRepository $manifestacoes = new ManifestacaoRepository(),
        private readonly MensagemRepository $mensagens = new MensagemRepository(),
        private readonly DemandaRepository $demandas = new DemandaRepository(),
    ) {
    }

    /**
     * Os interessados de uma demanda do próprio demandante.
     *
     * @throws ValidacaoException a demanda não é dele
     */
    public function daDemanda(int $usuarioId, int $demandaId): array
    {
        $demanda = $this->demandas->porId($demandaId);

        if ($demanda === null
            || (int) $demanda['dem_usu_id'] !== $usuarioId
            || $demanda['dem_status'] !== STATUS_ATIVO
        ) {
            Auditoria::registrar(
                Auditoria::ACESSO_NEGADO, 'pro_demandas', $demandaId, null, null,
                ['tentou' => $usuarioId, 'em' => 'interessados'], $usuarioId,
            );

            throw new ValidacaoException('Demanda não encontrada no seu painel.');
        }

        $lista = $this->manifestacoes->daDemanda($demandaId);

        $naoLidas = $this->mensagens->naoLidasEmLote(
            array_map('intval', array_column($lista, 'man_id')),
            $usuarioId,
        );

        foreach ($lista as $i => $item) {
            $lista[$i]['nao_lidas'] = $naoLidas[(int) $item['man_id']] ?? 0;
        }

        return ['demanda' => $demanda, 'interessados' => $lista];
    }

    /**
     * Uma manifestação aberta por uma das partes: o perfil congelado e a conversa.
     *
     * Marcar como visualizada só acontece quando quem abre é o **demandante**: a data serve para
     * dizer a quem manifestou que foi visto, e carimbá-la quando o próprio autor reabre a própria
     * manifestação tornaria o dado uma mentira.
     *
     * @throws ValidacaoException não existe, ou quem pede não é parte
     */
    public function abrir(int $usuarioId, int $manifestacaoId): array
    {
        $manifestacao = $this->exigirParte($usuarioId, $manifestacaoId);

        $ehDemandante = (int) $manifestacao['dem_usu_id'] === $usuarioId;

        if ($ehDemandante) {
            $this->manifestacoes->marcarVisualizada($manifestacaoId);
        }

        $this->mensagens->marcarLidas($manifestacaoId, $usuarioId);

        $snapshot = json_decode((string) $manifestacao['man_snapshot'], true);

        return [
            'manifestacao'  => $manifestacao,
            'eh_demandante' => $ehDemandante,
            'perfil'        => is_array($snapshot) ? $snapshot : null,
            // Confere na leitura, e não só na gravação: um snapshot que não bate com o próprio
            // hash é alteração no banco, e a tela precisa poder dizer isso em vez de exibir
            // conteúdo adulterado como se fosse o original.
            'integro'       => hash('sha256', (string) $manifestacao['man_snapshot'])
                               === $manifestacao['man_snapshot_hash'],
            'mensagens'     => $this->mensagens->daManifestacao($manifestacaoId),
        ];
    }

    /**
     * Escreve na conversa.
     *
     * @throws ValidacaoException corpo vazio ou longo demais, ou quem escreve não é parte
     */
    public function responder(int $usuarioId, int $manifestacaoId, string $corpo): int
    {
        $corpo = trim($corpo);

        (new Validacao())
            ->obrigatorio('corpo', $corpo, 'Escreva a mensagem.')
            ->tamanhoMaximo('corpo', $corpo, 2000, 'No máximo 2000 caracteres.')
            ->lancarSeInvalido();

        $manifestacao = $this->exigirParte($usuarioId, $manifestacaoId);

        return Database::transacao(function (PDO $pdo) use ($usuarioId, $manifestacaoId, $corpo, $manifestacao): int {
            $id = (new MensagemRepository($pdo))->criar($manifestacaoId, $usuarioId, $corpo);

            // A primeira resposta move a manifestação: quem manifestou passa a saber que houve
            // conversa, e não só que foi vista.
            if ($manifestacao['man_situacao'] !== 'RESPONDIDA') {
                $pdo->prepare(
                    'UPDATE pro_manifestacoes SET man_situacao = :situacao WHERE man_id = :id'
                )->execute([':situacao' => 'RESPONDIDA', ':id' => $manifestacaoId]);
            }

            // O corpo não entra na trilha: é conteúdo de conversa entre duas partes, e a
            // auditoria precisa saber que houve mensagem, não o que foi dito.
            Auditoria::registrar(
                Auditoria::CRIAR, 'pro_mensagens', $id, null, null,
                ['manifestacao' => $manifestacaoId, 'caracteres' => mb_strlen($corpo)],
                $usuarioId, $pdo,
            );

            return $id;
        });
    }

    /** As manifestações que o próprio usuário enviou, para acompanhar. */
    public function minhas(int $usuarioId): array
    {
        $lista = $this->manifestacoes->doUsuario($usuarioId);

        $naoLidas = $this->mensagens->naoLidasEmLote(
            array_map('intval', array_column($lista, 'man_id')),
            $usuarioId,
        );

        foreach ($lista as $i => $item) {
            $lista[$i]['nao_lidas'] = $naoLidas[(int) $item['man_id']] ?? 0;
        }

        return $lista;
    }

    /**
     * A manifestação, se quem pede for uma das duas partes.
     *
     * @return array<string, mixed>
     * @throws ValidacaoException
     */
    private function exigirParte(int $usuarioId, int $manifestacaoId): array
    {
        $manifestacao = $this->manifestacoes->porId($manifestacaoId);

        $ehParte = $manifestacao !== null
            && ((int) $manifestacao['man_usu_id'] === $usuarioId
                || (int) $manifestacao['dem_usu_id'] === $usuarioId);

        if ($ehParte) {
            return $manifestacao;
        }

        Auditoria::registrar(
            Auditoria::ACESSO_NEGADO, 'pro_manifestacoes', $manifestacaoId, null, null,
            ['tentou' => $usuarioId], $usuarioId,
        );

        throw new ValidacaoException('Manifestação não encontrada.');
    }
}
