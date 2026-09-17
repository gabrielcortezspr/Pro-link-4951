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
    /**
     * A sessão viva, com **o perfil que o banco diz agora**.
     *
     * O perfil vem junto de propósito, no mesmo `SELECT` que já acontecia a cada requisição
     * autenticada. `$_SESSION` guarda uma cópia do perfil feita no momento do login, e enquanto
     * ninguém a reconferisse, mudança de papel só valia no login seguinte: uma conta rebaixada
     * para Terceiro por `PerfilCreaService` continuava alcançando rota de Profissional com a
     * sessão que já tinha. Medido com requisição forjada em 17/09, e é OWASP A01.
     *
     * Trazer o perfil aqui custa um `JOIN` numa consulta que já existe, e não uma consulta nova.
     */
    public function ativa(string $tokenHash): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.ses_id, s.ses_usu_id, s.ses_dt_expiracao, p.per_codigo, u.usu_nome
               FROM sis_sessoes s
               JOIN sis_usuarios u ON u.usu_id = s.ses_usu_id
               JOIN sis_perfis p ON p.per_id = u.usu_per_id
              WHERE s.ses_token_hash = :hash
                AND s.ses_status = :ativo
                AND s.ses_dt_revogacao IS NULL
                AND s.ses_dt_expiracao > NOW()'
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
