<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\AuditoriaRepository;
use ProLink\Repository\ConsentimentoRepository;
use ProLink\Repository\SessaoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Crypto;
use ProLink\Support\Database;
use ProLink\Support\Documento;
use ProLink\Support\Requisicao;
use ProLink\Support\Validacao;

/**
 * Direitos do titular (edital 11.3): revogação de consentimento, portabilidade e descarte.
 *
 * Sobre o descarte, que é a parte que a banca costuma apertar: o item 8.6j proíbe exclusão física,
 * e o 11.3 exige descarte. A leitura adotada — decisão D05 em docs/decisoes.md — é que para o
 * titular "excluir a conta" significa revogação efetiva e imediata: sai de toda consulta
 * operacional, sessões caem na hora, perfil fora do pool e da busca. Remoção física é ato
 * administrativo com trilha, não efeito de um clique.
 *
 * A exportação devolve o CPF/CNPJ em claro de propósito: portabilidade é o titular recebendo o
 * próprio dado. Em qualquer outro lugar do sistema o documento aparece mascarado.
 */
final class PrivacidadeService
{
    /**
     * Finalidades que o titular controla na tela. O aceite de termos não está aqui de propósito:
     * revogar o aceite é encerrar a conta, e isso tem botão próprio.
     */
    public const FINALIDADES_REVOGAVEIS = [
        FINALIDADE_CONSULTA_API    => 'Consultar a API oficial do CREA para validar meu registro e importar ARTs',
        FINALIDADE_EXIBICAO_PERFIL => 'Exibir meu perfil para demandantes e na busca pública',
        FINALIDADE_NOTIFICACOES    => 'Receber notificações por e-mail sobre demandas e manifestações',
    ];

    public function __construct(
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
        private readonly ConsentimentoRepository $consentimentos = new ConsentimentoRepository(),
        private readonly SessaoRepository $sessoes = new SessaoRepository(),
        private readonly AuditoriaRepository $auditoria = new AuditoriaRepository(),
    ) {
    }

    /** @return array<string, mixed> dados do painel de privacidade */
    public function painel(int $usuarioId): array
    {
        $usuario = $this->usuarios->porId($usuarioId);

        if ($usuario === null) {
            throw new ValidacaoException('Conta não encontrada.');
        }

        $estado = [];

        foreach (array_keys(self::FINALIDADES_REVOGAVEIS) as $finalidade) {
            $registro = $this->consentimentos->porFinalidade($usuarioId, $finalidade);

            $estado[$finalidade] = [
                'concedido'  => $registro !== null && (int) $registro['con_concedido'] === 1,
                'concessao'  => $registro['con_dt_concessao'] ?? null,
                'revogacao'  => $registro['con_dt_revogacao'] ?? null,
            ];
        }

        return [
            'usuario' => [
                'nome'      => $usuario['usu_nome'],
                'email'     => $usuario['usu_email'],
                'perfil'    => $usuario['per_codigo'],
                'documento' => Documento::mascarar($this->documentoEmClaro($usuario)),
                'telefone'  => $usuario['usu_telefone'],
                'desde'     => $usuario['usu_dt_registro'],
            ],
            'consentimentos' => $estado,
            'termos'         => $this->consentimentos->doUsuario($usuarioId),
            'sessoes'        => $this->sessoes->ativasDoUsuario($usuarioId),
        ];
    }

    /** Concede ou revoga uma finalidade, com registro em auditoria do antes e do depois. */
    public function definirConsentimento(int $usuarioId, string $finalidade, bool $concedido): void
    {
        $v = new Validacao();
        $v->entre('finalidade', $finalidade, array_keys(self::FINALIDADES_REVOGAVEIS),
            'Finalidade desconhecida.');
        $v->lancarSeInvalido();

        $antes = $this->consentimentos->concedido($usuarioId, $finalidade);

        if ($antes === $concedido) {
            return;
        }

        Database::transacao(function (PDO $pdo) use ($usuarioId, $finalidade, $concedido, $antes): void {
            (new ConsentimentoRepository($pdo))->definir($usuarioId, $finalidade, $concedido, Requisicao::ip());

            Auditoria::registrar(
                $concedido ? Auditoria::CONSENTIR : Auditoria::REVOGAR,
                'sis_consentimentos',
                $usuarioId,
                $finalidade,
                $antes ? 'concedido' : 'revogado',
                $concedido ? 'concedido' : 'revogado',
                $usuarioId,
                $pdo,
            );
        });
    }

    /**
     * Portabilidade: tudo o que a plataforma tem sobre o titular, em estrutura legível.
     *
     * @return array<string, mixed>
     */
    public function exportar(int $usuarioId): array
    {
        $usuario = $this->usuarios->porId($usuarioId);

        if ($usuario === null) {
            throw new ValidacaoException('Conta não encontrada.');
        }

        $exportacao = [
            'gerado_em'  => date('c'),
            'plataforma' => 'Pro-Link — Desafio CREA Pro-Link, II CENATEC 2026',
            'aviso'      => 'Exportação dos dados do titular, conforme item 11.3 do edital. '
                          . 'Os dados de ARTs e CATs têm origem na API oficial do CREA-AM e são fictícios.',
            'conta' => [
                'nome'             => $usuario['usu_nome'],
                'email'            => $usuario['usu_email'],
                'perfil'           => $usuario['per_codigo'],
                'tipo_pessoa'      => $usuario['usu_tipo_pessoa'],
                'documento'        => Documento::formatar($this->documentoEmClaro($usuario)),
                'telefone'         => $usuario['usu_telefone'],
                'email_verificado' => (bool) $usuario['usu_email_verificado'],
                'criada_em'        => $usuario['usu_dt_registro'],
                'ultimo_login'     => $usuario['usu_dt_ultimo_login'],
            ],
            'consentimentos' => $this->consentimentos->doUsuario($usuarioId),
            'sessoes_ativas' => $this->sessoes->ativasDoUsuario($usuarioId),
            'minhas_acoes'   => $this->auditoria->doUsuario($usuarioId),
        ];

        Auditoria::registrar(Auditoria::EXPORTAR_DADOS, 'sis_usuarios', $usuarioId, null, null,
            ['formato' => 'json'], $usuarioId);

        return $exportacao;
    }

    /**
     * Exclusão a pedido do titular: status 'X', toda sessão revogada, consentimentos revogados.
     *
     * O registro em sis_auditoria permanece — é o que prova que a plataforma atendeu o pedido, e
     * a tabela é imutável por trigger (D04).
     */
    public function excluirConta(int $usuarioId): void
    {
        $usuario = $this->usuarios->porId($usuarioId);

        if ($usuario === null) {
            throw new ValidacaoException('Conta não encontrada.');
        }

        // Administrador não se autoexclui por aqui. O painel de privacidade é do titular comum; se
        // o único administrador se excluísse, a plataforma perderia moderação e auditoria até
        // alguém rodar scripts/criar-admin.php no servidor. Remoção de administrador é ato
        // administrativo, com outro administrador no comando (E6).
        if ($usuario['per_codigo'] === PERFIL_ADMIN) {
            throw new ValidacaoException(
                'Contas de administração não são excluídas por aqui. Peça a outro administrador.',
            );
        }

        Database::transacao(function (PDO $pdo) use ($usuarioId): void {
            $consentimentos = new ConsentimentoRepository($pdo);

            foreach (array_keys(self::FINALIDADES_REVOGAVEIS) as $finalidade) {
                $consentimentos->definir($usuarioId, $finalidade, false, Requisicao::ip());
            }

            (new UsuarioRepository($pdo))->marcarExcluido($usuarioId);
            $derrubadas = (new SessaoRepository($pdo))->revogarTodasDoUsuario($usuarioId);

            Auditoria::registrar(Auditoria::EXCLUIR, 'sis_usuarios', $usuarioId, 'usu_status',
                STATUS_ATIVO, STATUS_EXCLUIDO, $usuarioId, $pdo);
            Auditoria::registrar(Auditoria::REVOGAR, 'sis_sessoes', $usuarioId, null, null,
                ['sessoes_revogadas' => $derrubadas, 'motivo' => 'exclusao_de_conta'], $usuarioId, $pdo);
        });
    }

    /** Decifra o documento. Usado só para o próprio titular ver o dado dele. */
    private function documentoEmClaro(array $usuario): string
    {
        $cifrado = $usuario['usu_documento_cif'] ?? null;

        if ($cifrado === null || $cifrado === '') {
            return '';
        }

        // Stream quando o driver devolve o VARBINARY como recurso.
        if (is_resource($cifrado)) {
            $cifrado = (string) stream_get_contents($cifrado);
        }

        return Crypto::decifrar((string) $cifrado);
    }
}
