<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Tokens de recuperação de senha (sis_recuperacoes).
 *
 * O banco guarda só o hash do token (Crypto::hashToken); o token em claro existe uma vez, dentro
 * do e-mail. Quem lê o banco não consegue redefinir a senha de ninguém.
 *
 * Um token é usável uma vez (`rec_dt_uso`) e expira por tempo. Pedido novo invalida os anteriores,
 * para que um link antigo vazado não continue valendo.
 */
final class RecuperacaoRepository extends Repositorio
{
    public function criar(int $usuarioId, string $tokenHash, int $validadeMinutos, ?string $ip): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sis_recuperacoes
                (rec_usu_id, rec_token_hash, rec_dt_expiracao, rec_ip_solicitante, rec_status)
             VALUES
                (:usuario, :hash, DATE_ADD(NOW(), INTERVAL :minutos MINUTE), :ip, :ativo)'
        );

        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':hash', $tokenHash);
        $stmt->bindValue(':minutos', $validadeMinutos, \PDO::PARAM_INT);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** Não usado, não expirado, status ativo, e de usuário que ainda está ativo. */
    public function utilizavel(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.rec_id, r.rec_usu_id, u.usu_email, u.usu_nome
               FROM sis_recuperacoes r
               JOIN sis_usuarios u ON u.usu_id = r.rec_usu_id
              WHERE r.rec_token_hash = :hash
                AND r.rec_status = :ativo
                AND r.rec_dt_uso IS NULL
                AND r.rec_dt_expiracao > NOW()
                AND u.usu_status = :ativo_usuario'
        );
        $stmt->execute([':hash' => $tokenHash, ':ativo' => STATUS_ATIVO, ':ativo_usuario' => STATUS_ATIVO]);

        return $stmt->fetch() ?: null;
    }

    public function marcarUsado(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE sis_recuperacoes SET rec_dt_uso = NOW() WHERE rec_id = :id');
        $stmt->execute([':id' => $id]);
    }

    /** Invalida pendentes marcando-os como usados: pedido novo derruba link antigo. */
    public function invalidarPendentes(int $usuarioId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_recuperacoes
                SET rec_dt_uso = NOW()
              WHERE rec_usu_id = :usuario AND rec_dt_uso IS NULL'
        );
        $stmt->execute([':usuario' => $usuarioId]);
    }
}
