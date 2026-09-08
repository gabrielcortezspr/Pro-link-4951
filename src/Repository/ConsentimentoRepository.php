<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Consentimentos por finalidade (sis_consentimentos; edital 11.3).
 *
 * Uma linha por (usuário, finalidade), atualizada no lugar quando o titular concede ou revoga.
 * O histórico de quem mudou o quê e quando mora em sis_auditoria, que é imutável — duplicar o
 * histórico aqui daria duas versões da verdade, e a versão mutável seria a menos confiável.
 */
final class ConsentimentoRepository extends Repositorio
{
    /**
     * Concede ou revoga, criando a linha se for a primeira vez.
     *
     * As duas datas são decididas aqui, não em CASE no SQL: com EMULATE_PREPARES desligado um
     * parâmetro nomeado não pode ser reusado, e a versão em SQL precisava do mesmo booleano três
     * vezes com três nomes diferentes. Em PHP a regra fica legível: concessão marca a data de
     * concessão e limpa a revogação; revogação faz o inverso e preserva quando foi concedido.
     */
    public function definir(
        int $usuarioId,
        string $finalidade,
        bool $concedido,
        ?string $ip = null,
        ?int $termoId = null,
    ): void {
        $agora     = date('Y-m-d H:i:s');
        $existente = $this->porFinalidade($usuarioId, $finalidade);

        if ($existente === null) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO sis_consentimentos
                    (con_usu_id, con_ter_id, con_finalidade, con_concedido, con_dt_concessao,
                     con_dt_revogacao, con_ip, con_status)
                 VALUES
                    (:usuario, :termo, :finalidade, :concedido, :concessao, :revogacao, :ip, :ativo)'
            );
            $stmt->execute([
                ':usuario'    => $usuarioId,
                ':termo'      => $termoId,
                ':finalidade' => $finalidade,
                ':concedido'  => $concedido ? 1 : 0,
                ':concessao'  => $concedido ? $agora : null,
                ':revogacao'  => $concedido ? null : $agora,
                ':ip'         => $ip,
                ':ativo'      => STATUS_ATIVO,
            ]);

            return;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE sis_consentimentos
                SET con_concedido    = :concedido,
                    con_dt_concessao = :concessao,
                    con_dt_revogacao = :revogacao,
                    con_ip           = :ip
              WHERE con_id = :id'
        );
        $stmt->execute([
            ':concedido' => $concedido ? 1 : 0,
            ':concessao' => $concedido ? $agora : $existente['con_dt_concessao'],
            ':revogacao' => $concedido ? null : $agora,
            ':ip'        => $ip,
            ':id'        => $existente['con_id'],
        ]);
    }

    public function porFinalidade(int $usuarioId, string $finalidade): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT con_id, con_ter_id, con_finalidade, con_concedido, con_dt_concessao, con_dt_revogacao
               FROM sis_consentimentos
              WHERE con_usu_id = :usuario AND con_finalidade = :finalidade AND con_status = :ativo'
        );
        $stmt->execute([':usuario' => $usuarioId, ':finalidade' => $finalidade, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetch() ?: null;
    }

    public function concedido(int $usuarioId, string $finalidade): bool
    {
        $consentimento = $this->porFinalidade($usuarioId, $finalidade);

        return $consentimento !== null && (int) $consentimento['con_concedido'] === 1;
    }

    /** @return list<array<string, mixed>> */
    public function doUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.con_finalidade, c.con_concedido, c.con_dt_concessao, c.con_dt_revogacao,
                    t.ter_tipo, t.ter_versao
               FROM sis_consentimentos c
          LEFT JOIN sis_termos t ON t.ter_id = c.con_ter_id
              WHERE c.con_usu_id = :usuario AND c.con_status = :ativo
              ORDER BY c.con_finalidade'
        );
        $stmt->execute([':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }
}
