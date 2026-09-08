<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Registro das sessões no servidor (sis_sessoes).
 *
 * A sessão do PHP sozinha não dá revogação: derrubar quem já está logado exigiria apagar arquivo
 * de sessão no disco. Com a linha aqui, o front controller confere a cada requisição se a sessão
 * ainda vale, e o bloqueio de usuário pelo administrador (E6, operação atômica 5) tem efeito
 * imediato.
 *
 * Guarda só o hash do identificador de sessão (Crypto::hashToken). Dump do banco não entrega
 * sessão de ninguém.
 */
final class SessaoRepository extends Repositorio
{
    public function registrar(
        int $usuarioId,
        string $tokenHash,
        ?string $ip,
        ?string $userAgent,
        int $minutosDeVida,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sis_sessoes
                (ses_usu_id, ses_token_hash, ses_ip, ses_user_agent, ses_dt_expiracao, ses_status)
             VALUES
                (:usuario, :hash, :ip, :agente, DATE_ADD(NOW(), INTERVAL :minutos MINUTE), :ativo)'
        );

        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':hash', $tokenHash);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':agente', $userAgent);
        $stmt->bindValue(':minutos', $minutosDeVida, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** Válida = não revogada, não expirada, status ativo. */
    public function ativa(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ses_id, ses_usu_id, ses_dt_expiracao
               FROM sis_sessoes
              WHERE ses_token_hash = :hash
                AND ses_status = :ativo
                AND ses_dt_revogacao IS NULL
                AND ses_dt_expiracao > NOW()'
        );
        $stmt->execute([':hash' => $tokenHash, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetch() ?: null;
    }

    public function revogar(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_sessoes
                SET ses_dt_revogacao = NOW()
              WHERE ses_token_hash = :hash AND ses_dt_revogacao IS NULL'
        );
        $stmt->execute([':hash' => $tokenHash]);
    }

    /**
     * Derruba todas as sessões do usuário. Usada na troca de senha, na exclusão da conta e no
     * bloqueio administrativo — os três casos em que deixar sessão viva seria falha de segurança.
     *
     * @return int quantas sessões caíram
     */
    public function revogarTodasDoUsuario(int $usuarioId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_sessoes
                SET ses_dt_revogacao = NOW()
              WHERE ses_usu_id = :usuario AND ses_dt_revogacao IS NULL'
        );
        $stmt->execute([':usuario' => $usuarioId]);

        return $stmt->rowCount();
    }

    /** Para o painel de privacidade: onde a conta está aberta agora. */
    public function ativasDoUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ses_id, ses_ip, ses_user_agent, ses_dt_registro, ses_dt_expiracao
               FROM sis_sessoes
              WHERE ses_usu_id = :usuario
                AND ses_status = :ativo
                AND ses_dt_revogacao IS NULL
                AND ses_dt_expiracao > NOW()
              ORDER BY ses_dt_registro DESC'
        );
        $stmt->execute([':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }
}
