<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `pro_dispensas`: os perfis que a dona da demanda dispensou no feed de compatíveis (D87).
 *
 * Nada é apagado (item 8.6j): desfazer marca `dsp_status = 'X'`, e dispensar de novo reativa a
 * mesma linha. O `ON DUPLICATE KEY UPDATE` é seguro porque as duas colunas de `uq_dsp_dem_usu`
 * são NOT NULL (a armadilha do `CLAUDE.md`, D25 e D28).
 */
final class DispensaRepository extends Repositorio
{
    public function dispensar(int $demandaId, int $usuarioId, int $autorId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_dispensas (dsp_dem_id, dsp_usu_id, dsp_usu_autor, dsp_status)
             VALUES (:demanda, :usuario, :autor, :ativo)
             ON DUPLICATE KEY UPDATE
                dsp_usu_autor   = VALUES(dsp_usu_autor),
                dsp_status      = VALUES(dsp_status),
                dsp_dt_registro = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            ':demanda' => $demandaId, ':usuario' => $usuarioId,
            ':autor'   => $autorId,   ':ativo'   => STATUS_ATIVO,
        ]);
    }

    /** @return bool true se havia uma dispensa ativa para desfazer */
    public function desfazer(int $demandaId, int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pro_dispensas SET dsp_status = :excluido
              WHERE dsp_dem_id = :demanda AND dsp_usu_id = :usuario AND dsp_status = :ativo'
        );
        $stmt->execute([
            ':excluido' => STATUS_EXCLUIDO, ':demanda' => $demandaId,
            ':usuario'  => $usuarioId,      ':ativo'   => STATUS_ATIVO,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * As dispensas ativas da demanda, com o nome de cada perfil, a mais recente primeiro.
     *
     * @return list<array{usuario_id: int, nome: string, dt_registro: string}>
     */
    public function daDemanda(int $demandaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.dsp_usu_id, d.dsp_dt_registro,
                    COALESCE(p.prf_nome_api, e.emp_razao_social, u.usu_nome) AS nome
               FROM pro_dispensas d
               JOIN sis_usuarios u          ON u.usu_id = d.dsp_usu_id
               LEFT JOIN pro_profissionais p ON p.prf_usu_id = d.dsp_usu_id
               LEFT JOIN pro_empresas e      ON e.emp_usu_id = d.dsp_usu_id
              WHERE d.dsp_dem_id = :demanda AND d.dsp_status = :ativo
              ORDER BY d.dsp_dt_registro DESC, d.dsp_id DESC'
        );
        $stmt->execute([':demanda' => $demandaId, ':ativo' => STATUS_ATIVO]);

        return array_map(static fn (array $l): array => [
            'usuario_id'  => (int) $l['dsp_usu_id'],
            'nome'        => (string) $l['nome'],
            'dt_registro' => (string) $l['dsp_dt_registro'],
        ], $stmt->fetchAll());
    }
}
