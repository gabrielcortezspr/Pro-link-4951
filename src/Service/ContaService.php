<?php

declare(strict_types=1);

namespace ProLink\Service;

use PDO;
use ProLink\Repository\SessaoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Support\Auditoria;
use ProLink\Support\Database;

/**
 * Gestão de contas pelo administrador (Anexo I, item 3: "gerir perfis").
 *
 * ## Por que existe, se a moderação já bloqueava
 *
 * Bloquear já era possível, mas **só como providência de uma denúncia**. Conta que precisa ser
 * suspensa sem que ninguém a tenha denunciado não tinha caminho, e a lista de contas não existia:
 * a administração não conseguia responder "quem existe na plataforma" sem abrir o banco.
 *
 * ## O bloqueio é a mesma operação atômica da E6
 *
 * Status, sessões revogadas e trilha, tudo ou nada, na mesma transação. A ordem importa e é a que
 * a trilha mostra: o status é a verdade que persiste, a revogação é a consequência.
 *
 * ## O que esta classe recusa fazer
 *
 * Não bloqueia administrador, não bloqueia quem está executando a ação, e **não desbloqueia conta
 * excluída pelo titular**. As duas primeiras protegem o acesso ao painel; a terceira é a mesma
 * regra da D62: exclusão a pedido do titular não se desfaz por ato administrativo, e desbloquear
 * seria o mesmo efeito por outro nome.
 */
final class ContaService
{
    public const POR_PAGINA = 25;

    private const MOTIVO_MINIMO = 10;

    private const MOTIVO_MAXIMO = 500;

    public function __construct(
        private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
    ) {
    }

    /**
     * Uma página da lista de contas, com os filtros já aplicados.
     *
     * @param  array{termo?: string, perfil?: string, situacao?: string} $filtros
     * @return array{contas: list<array<string, mixed>>, total: int, pagina: int, paginas: int}
     */
    public function listar(array $filtros, int $pagina): array
    {
        $total   = $this->usuarios->contar($filtros);
        $paginas = max(1, (int) ceil($total / self::POR_PAGINA));
        $pagina  = max(1, min($pagina, $paginas));

        return [
            'contas'  => $this->usuarios->listar($filtros, self::POR_PAGINA, ($pagina - 1) * self::POR_PAGINA),
            'total'   => $total,
            'pagina'  => $pagina,
            'paginas' => $paginas,
        ];
    }

    /**
     * Bloqueia uma conta, revoga as sessões dela e registra, numa transação só.
     *
     * @return array{nome: string, sessoes_derrubadas: int}
     * @throws ValidacaoException
     */
    public function bloquear(int $contaId, int $administradorId, string $motivo): array
    {
        $motivo = $this->exigirMotivo($motivo);
        $alvo   = $this->exigirAlvoAdministravel($contaId, $administradorId);

        if ($alvo['usu_status'] === STATUS_INATIVO) {
            throw new ValidacaoException('Esta conta já está bloqueada.');
        }

        if ($alvo['usu_status'] === STATUS_EXCLUIDO) {
            throw new ValidacaoException(
                'Conta excluída pelo titular não é bloqueada: ela já não entra. Ver a lixeira.'
            );
        }

        $derrubadas = Database::transacao(
            function (PDO $pdo) use ($contaId, $administradorId, $motivo, $alvo): int {
                (new UsuarioRepository($pdo))->alterarStatus($contaId, STATUS_INATIVO);
                $derrubadas = (new SessaoRepository($pdo))->revogarTodasDoUsuario($contaId);

                Auditoria::registrar(
                    Auditoria::BLOQUEAR,
                    'sis_usuarios',
                    $contaId,
                    'usu_status',
                    (string) $alvo['usu_status'],
                    STATUS_INATIVO,
                    $administradorId,
                    $pdo,
                );

                Auditoria::registrar(
                    Auditoria::REVOGAR,
                    'sis_sessoes',
                    $contaId,
                    null,
                    null,
                    [
                        'sessoes_revogadas' => $derrubadas,
                        'motivo'            => $motivo,
                        'origem'            => 'gestao_de_contas',
                    ],
                    $administradorId,
                    $pdo,
                );

                return $derrubadas;
            }
        );

        return ['nome' => (string) $alvo['usu_nome'], 'sessoes_derrubadas' => $derrubadas];
    }

    /**
     * Devolve uma conta bloqueada à operação.
     *
     * Não revoga nem restaura sessão: as que existiam morreram no bloqueio, e a pessoa entra de
     * novo pelo login, que é o caminho que prova que ela ainda tem a credencial.
     *
     * @return array{nome: string}
     * @throws ValidacaoException
     */
    public function desbloquear(int $contaId, int $administradorId, string $motivo): array
    {
        $motivo = $this->exigirMotivo($motivo);
        $alvo   = $this->exigirAlvoAdministravel($contaId, $administradorId);

        if ($alvo['usu_status'] === STATUS_EXCLUIDO) {
            throw new ValidacaoException(
                'Conta excluída a pedido do titular não volta por ato administrativo. '
                . 'É a mesma regra da lixeira, e o motivo está na Política de Privacidade.'
            );
        }

        if ($alvo['usu_status'] !== STATUS_INATIVO) {
            throw new ValidacaoException('Esta conta não está bloqueada.');
        }

        Database::transacao(function (PDO $pdo) use ($contaId, $administradorId, $motivo): void {
            (new UsuarioRepository($pdo))->alterarStatus($contaId, STATUS_ATIVO);

            Auditoria::registrar(
                Auditoria::EDITAR,
                'sis_usuarios',
                $contaId,
                'usu_status',
                STATUS_INATIVO,
                ['status' => STATUS_ATIVO, 'motivo' => $motivo, 'origem' => 'gestao_de_contas'],
                $administradorId,
                $pdo,
            );
        });

        return ['nome' => (string) $alvo['usu_nome']];
    }

    /**
     * @return array<string, mixed>
     * @throws ValidacaoException
     */
    private function exigirAlvoAdministravel(int $contaId, int $administradorId): array
    {
        // Em qualquer situação, e não só ativa: o alvo desta classe é justamente a conta que saiu
        // da operação. `porId()` filtra por ativa, e usá-lo aqui tornava o desbloqueio impossível.
        $alvo = $this->usuarios->porIdEmQualquerSituacao($contaId);

        if ($alvo === null) {
            throw new ValidacaoException('Conta não encontrada.');
        }

        // As duas guardas da E6, pelo mesmo motivo: sem elas um administrador bloqueia o outro, ou
        // a si mesmo, e o acesso ao painel se perde no meio da demonstração.
        if ($alvo['per_codigo'] === PERFIL_ADMIN) {
            throw new ValidacaoException('Conta de administração não é gerida por aqui.');
        }

        if ((int) $alvo['usu_id'] === $administradorId) {
            throw new ValidacaoException('Não dá para agir sobre a própria conta.');
        }

        return $alvo;
    }

    /** @throws ValidacaoException */
    private function exigirMotivo(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw new ValidacaoException(
                'Escreva o motivo, com pelo menos ' . self::MOTIVO_MINIMO . ' caracteres. '
                . 'Ele fica na trilha de auditoria.',
                ['motivo' => 'Motivo curto demais.'],
            );
        }

        if (mb_strlen($motivo) > self::MOTIVO_MAXIMO) {
            throw new ValidacaoException(
                'O motivo passa de ' . self::MOTIVO_MAXIMO . ' caracteres.',
                ['motivo' => 'Motivo longo demais.'],
            );
        }

        return $motivo;
    }
}
