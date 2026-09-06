<?php

declare(strict_types=1);

namespace ProLink\Support;

use PDO;

/**
 * Trilha de auditoria (edital 8.5g, 11.3; OWASP A09).
 *
 * Único caminho de escrita em sis_auditoria. A tabela é insert-only por trigger, então o que
 * entra aqui não sai mais — nem pelo administrador. Todo serviço que muda estado chama
 * registrar(); quando a mudança acontece dentro de Database::transacao(), a linha de auditoria
 * entra na mesma transação e desfaz junto se algo falhar.
 *
 * Não engole exceção de propósito: se a auditoria não puder ser gravada, a operação não pode
 * ser considerada concluída.
 */
final class Auditoria
{
    // Ações padronizadas. Strings, não enum, para o SQL de consulta continuar legível.
    public const LOGIN           = 'LOGIN';
    public const LOGIN_FALHOU    = 'LOGIN_FALHOU';
    public const LOGOUT          = 'LOGOUT';
    public const BLOQUEIO_LOGIN  = 'BLOQUEIO_LOGIN';
    public const ACESSO_NEGADO   = 'ACESSO_NEGADO';
    public const CRIAR           = 'CRIAR';
    public const EDITAR          = 'EDITAR';
    public const EXCLUIR         = 'EXCLUIR';
    public const RESTAURAR       = 'RESTAURAR';
    public const CONSENTIR       = 'CONSENTIR';
    public const REVOGAR         = 'REVOGAR';
    public const EXPORTAR_DADOS  = 'EXPORTAR_DADOS';
    public const CONSULTA_API    = 'CONSULTA_API';
    public const VALIDAR_ART     = 'VALIDAR_ART';
    public const VALIDAR_CAT     = 'VALIDAR_CAT';
    public const SELO_DIVERGENTE = 'SELO_DIVERGENTE';
    public const BLOQUEAR        = 'BLOQUEAR';
    public const MODERAR         = 'MODERAR';

    /**
     * @param string      $acao       uma das constantes acima
     * @param string|null $entidade   nome da tabela ou conceito: 'sis_usuarios', 'pro_demandas'
     * @param int|null    $entidadeId chave do registro afetado
     * @param string|null $campo      campo alterado, quando é edição de um campo só
     * @param mixed       $antes      valor anterior; arrays viram JSON
     * @param mixed       $depois     valor novo; arrays viram JSON
     * @param int|null    $usuarioId  força o autor (CLI, sincronização); padrão é o da sessão
     */
    public static function registrar(
        string $acao,
        ?string $entidade = null,
        ?int $entidadeId = null,
        ?string $campo = null,
        mixed $antes = null,
        mixed $depois = null,
        ?int $usuarioId = null,
        ?PDO $pdo = null,
    ): void {
        $pdo ??= Database::conexao();

        $stmt = $pdo->prepare(
            'INSERT INTO sis_auditoria
                (aud_usu_id, aud_ip, aud_acao, aud_entidade, aud_entidade_id, aud_campo,
                 aud_valor_anterior, aud_valor_novo, aud_user_agent)
             VALUES
                (:usuario, :ip, :acao, :entidade, :entidade_id, :campo, :antes, :depois, :agente)'
        );

        $stmt->execute([
            ':usuario'     => $usuarioId ?? Sessao::usuarioId(),
            ':ip'          => Requisicao::ip(),
            ':acao'        => $acao,
            ':entidade'    => $entidade,
            ':entidade_id' => $entidadeId,
            ':campo'       => $campo,
            ':antes'       => self::serializar($antes),
            ':depois'      => self::serializar($depois),
            ':agente'      => Requisicao::userAgent(),
        ]);
    }

    private static function serializar(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        if (is_scalar($valor)) {
            return (string) $valor;
        }

        return json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }
}
