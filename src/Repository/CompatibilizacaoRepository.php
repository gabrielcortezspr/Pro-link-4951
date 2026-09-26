<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Sessões do motor de compatibilização: `mat_sessoes` e `mat_sessao_pool`.
 *
 * Uma sessão é o registro completo de uma execução do motor: a demanda, quem executou, a semente,
 * o limiar e os pesos vigentes naquele instante, mais uma linha por candidato do pool com o score
 * e os critérios que o sustentaram. É o que torna a compatibilização auditável e reproduzível
 * (item 12.3), e o que permite responder "por que este perfil apareceu naquele dia".
 *
 * Os pesos são gravados na sessão, e não só lidos de `sis_parametros`, de propósito: o
 * administrador pode recalibrá-los amanhã, e a sessão de hoje precisa continuar explicável com os
 * números de hoje.
 */
final class CompatibilizacaoRepository extends Repositorio
{
    /**
     * Grava a sessão e o pool. Chamada de dentro da transação da operação atômica 2, nunca solta:
     * sessão sem pool, ou pool sem sessão, é registro de auditoria mentindo.
     *
     * @param  list<array{tipo: string, id: int, score: float, criterios: array<string, mixed>}> $pool
     * @return int  id da sessão
     */
    public function gravar(
        int $demandaId,
        int $usuarioId,
        string $semente,
        float $limiar,
        array $pesos,
        array $pool,
        ?string $ip,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO mat_sessoes
                (mts_dem_id, mts_usu_id, mts_semente, mts_limiar, mts_pesos, mts_total_pool, mts_ip)
             VALUES
                (:demanda, :usuario, :semente, :limiar, :pesos, :total, :ip)'
        );

        $stmt->execute([
            ':demanda' => $demandaId,
            ':usuario' => $usuarioId,
            ':semente' => $semente,
            ':limiar'  => $limiar,
            ':pesos'   => json_encode($pesos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ':total'   => count($pool),
            ':ip'      => $ip,
        ]);

        $sessaoId = (int) $this->pdo->lastInsertId();

        if ($pool === []) {
            return $sessaoId;
        }

        $linha = $this->pdo->prepare(
            'INSERT INTO mat_sessao_pool
                (msp_mts_id, msp_candidato_tipo, msp_candidato_id, msp_score, msp_criterios)
             VALUES
                (:sessao, :tipo, :id, :score, :criterios)'
        );

        foreach ($pool as $candidato) {
            $linha->execute([
                ':sessao'    => $sessaoId,
                ':tipo'      => $candidato['tipo'],
                ':id'        => $candidato['id'],
                ':score'     => $candidato['score'],
                ':criterios' => json_encode($candidato['criterios'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]);
        }

        return $sessaoId;
    }

    /** @return array<string, mixed>|null */
    public function porId(int $sessaoId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, d.dem_titulo, u.usu_nome
               FROM mat_sessoes s
               JOIN pro_demandas d ON d.dem_id = s.mts_dem_id
               LEFT JOIN sis_usuarios u ON u.usu_id = s.mts_usu_id
              WHERE s.mts_id = :id AND s.mts_status = :ativo'
        );
        $stmt->bindValue(':id', $sessaoId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        $sessao = $stmt->fetch();

        return $sessao === false ? null : $sessao;
    }

    /**
     * O pool de uma sessão, **na ordem de gravação**, que é a ordem sorteada pela semente.
     *
     * Não ordena por score, e isso não é descuido: ordenar por score aqui reintroduziria pela
     * porta dos fundos o ranking que o item 10.1 veda, mesmo com a semente correta gravada.
     *
     * @return list<array<string, mixed>>
     */
    public function pool(int $sessaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT msp_candidato_tipo, msp_candidato_id, msp_score, msp_criterios
               FROM mat_sessao_pool
              WHERE msp_mts_id = :sessao AND msp_status = :ativo
              ORDER BY msp_id'
        );
        $stmt->bindValue(':sessao', $sessaoId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * As sessões mais recentes da plataforma inteira, para o painel do administrador.
     *
     * Ordena por `mts_id DESC`, que é cronológico e não tem nada a ver com o pool: aqui o que se
     * lista são execuções do motor, não candidatos. Ordenar sessão por data é registro; o que o
     * item 10.1 proíbe é ordenar **pessoas** por mérito.
     *
     * @return list<array<string, mixed>>
     */
    public function recentes(int $limite = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.mts_id, s.mts_dt_registro, s.mts_semente, s.mts_limiar, s.mts_total_pool,
                    s.mts_dem_id, d.dem_titulo, u.usu_nome
               FROM mat_sessoes s
               JOIN pro_demandas d ON d.dem_id = s.mts_dem_id
               LEFT JOIN sis_usuarios u ON u.usu_id = s.mts_usu_id
              WHERE s.mts_status = :ativo
              ORDER BY s.mts_id DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':limite', max(1, min($limite, 200)), \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Sessões de uma demanda, mais recentes primeiro. Alimenta o painel do administrador.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Os titulares (usuário) do conjunto gravado na sessão mais recente da demanda, ou null se a
     * demanda ainda não teve sessão. Serve aos contadores de "a analisar" (D87), que leem o que
     * já foi calculado em vez de rodar o motor de novo a cada tela.
     *
     * @return list<int>|null
     */
    public function usuariosDoUltimoPool(int $demandaId): ?array
    {
        $ultima = $this->daDemanda($demandaId, 1)[0] ?? null;

        if ($ultima === null) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(p.prf_usu_id, e.emp_usu_id) AS usuario_id
               FROM mat_sessao_pool s
               LEFT JOIN pro_profissionais p
                      ON s.msp_candidato_tipo = :tipo_p AND p.prf_id = s.msp_candidato_id
               LEFT JOIN pro_empresas e
                      ON s.msp_candidato_tipo = :tipo_e AND e.emp_id = s.msp_candidato_id
              WHERE s.msp_mts_id = :sessao AND s.msp_status = :ativo'
        );
        $stmt->execute([
            ':tipo_p' => 'P', ':tipo_e' => 'E',
            ':sessao' => (int) $ultima['mts_id'], ':ativo' => STATUS_ATIVO,
        ]);

        return array_values(array_filter(array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN))));
    }

    public function daDemanda(int $demandaId, int $limite = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT mts_id, mts_dt_registro, mts_semente, mts_limiar, mts_total_pool
               FROM mat_sessoes
              WHERE mts_dem_id = :demanda AND mts_status = :ativo
              ORDER BY mts_id DESC
              LIMIT :limite'
        );
        $stmt->bindValue(':demanda', $demandaId, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
