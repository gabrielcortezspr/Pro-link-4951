<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Denúncias (RF06, edital 8.5g).
 *
 * Todo o SQL de pro_denuncias mora aqui. A denúncia é sempre vinculada a um alvo — nunca
 * genérica —, e o filtro por den_status = 'A' está em toda leitura porque exclusão é lógica
 * (item 8.6j): o que a lixeira guarda não pode voltar na fila do moderador.
 */
final class DenunciaRepository extends Repositorio
{
    public function criar(
        int $usuarioId,
        string $entidade,
        int $entidadeId,
        string $tipo,
        string $descricao,
        ?string $evidencia,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_denuncias
                (den_usu_id, den_entidade, den_entidade_id, den_tipo, den_descricao,
                 den_evidencia, den_situacao, den_status)
             VALUES
                (:usuario, :entidade, :entidade_id, :tipo, :descricao,
                 :evidencia, :situacao, :ativo)'
        );

        $stmt->bindValue(':usuario', $usuarioId, \PDO::PARAM_INT);
        $stmt->bindValue(':entidade', $entidade);
        $stmt->bindValue(':entidade_id', $entidadeId, \PDO::PARAM_INT);
        $stmt->bindValue(':tipo', $tipo);
        $stmt->bindValue(':descricao', $descricao);
        $stmt->bindValue(':evidencia', $evidencia);
        $stmt->bindValue(':situacao', 'PENDENTE');
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.*, u.usu_nome AS autor_nome,
                    alvo.usu_nome AS alvo_nome, alvo.usu_status AS alvo_status
               FROM pro_denuncias d
               JOIN sis_usuarios u ON u.usu_id = d.den_usu_id
               LEFT JOIN sis_usuarios alvo
                      ON alvo.usu_id = d.den_entidade_id
                     AND d.den_entidade = \'USUARIO\'
              WHERE d.den_id = :id
                AND d.den_status = :ativo'
        );

        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    /**
     * Fila do moderador, da mais recente para a mais antiga.
     *
     * Os filtros entram como fragmento literal, nunca o valor: só o nome da coluna é
     * concatenado, e o dado continua em placeholder. ATTR_EMULATE_PREPARES está desligado
     * (Database.php), então cada :nome aparece uma vez só na query.
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Denúncias ainda abertas com uma destas descrições exatas, com o e-mail de quem denunciou.
     *
     * Serve ao `scripts/limpar-rastro-de-verificacao.php`, que trata como improcedentes as
     * denúncias que os verificadores abrem a cada execução. Descrição exata, e não "contém": a
     * limpeza não pode alcançar uma denúncia real que por acaso cite a palavra "verificação".
     *
     * @param list<string> $descricoes
     * @return list<array{den_id: int, den_descricao: string, usu_email: string}>
     */
    public function abertasComDescricao(array $descricoes): array
    {
        if ($descricoes === []) {
            return [];
        }

        $marcas = [];
        $params = [':ativo' => STATUS_ATIVO, ':resolvida' => 'RESOLVIDA'];

        foreach (array_values($descricoes) as $i => $texto) {
            $marcas[]         = ":d{$i}";
            $params[":d{$i}"] = $texto;
        }

        $stmt = $this->pdo->prepare(
            'SELECT d.den_id, d.den_descricao, u.usu_email
               FROM pro_denuncias d
               JOIN sis_usuarios u ON u.usu_id = d.den_usu_id
              WHERE d.den_status = :ativo
                AND d.den_situacao <> :resolvida
                AND d.den_descricao IN (' . implode(', ', $marcas) . ')
              ORDER BY d.den_id'
        );
        $stmt->execute($params);

        return array_map(static fn (array $l): array => [
            'den_id'        => (int) $l['den_id'],
            'den_descricao' => (string) $l['den_descricao'],
            'usu_email'     => (string) $l['usu_email'],
        ], $stmt->fetchAll());
    }

    public function fila(?string $situacao, ?string $tipo, int $limite = 50): array
    {
        // O alvo entra por LEFT JOIN condicionado à entidade: quando é conta, a fila mostra o
        // nome, porque "USUARIO #10" não diz ao moderador o que está sendo denunciado. Para as
        // outras entidades o join não casa e alvo_nome vem nulo, que o template trata.
        $sql = 'SELECT d.den_id, d.den_entidade, d.den_entidade_id, d.den_tipo, d.den_situacao,
                       d.den_providencia, d.den_dt_registro, u.usu_nome AS autor_nome,
                       alvo.usu_nome AS alvo_nome
                  FROM pro_denuncias d
                  JOIN sis_usuarios u ON u.usu_id = d.den_usu_id
                  LEFT JOIN sis_usuarios alvo
                         ON alvo.usu_id = d.den_entidade_id
                        AND d.den_entidade = \'USUARIO\'
                 WHERE d.den_status = :ativo';

        if ($situacao !== null) {
            $sql .= ' AND d.den_situacao = :situacao';
        }

        if ($tipo !== null) {
            $sql .= ' AND d.den_tipo = :tipo';
        }

        $sql .= ' ORDER BY d.den_dt_registro DESC LIMIT :limite';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':ativo', STATUS_ATIVO);

        if ($situacao !== null) {
            $stmt->bindValue(':situacao', $situacao);
        }

        if ($tipo !== null) {
            $stmt->bindValue(':tipo', $tipo);
        }

        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /** @return list<array<string, mixed>> uma linha por situação, com o total */
    public function contarPorSituacao(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT den_situacao, COUNT(*) AS total
               FROM pro_denuncias
              WHERE den_status = :ativo
              GROUP BY den_situacao'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function tratar(int $id, int $moderadorId, string $situacao, ?string $providencia): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pro_denuncias
                SET den_situacao      = :situacao,
                    den_providencia   = :providencia,
                    den_usu_moderador = :moderador,
                    den_dt_tratamento = NOW()
              WHERE den_id = :id
                AND den_status = :ativo'
        );

        $stmt->bindValue(':situacao', $situacao);
        $stmt->bindValue(':providencia', $providencia);
        $stmt->bindValue(':moderador', $moderadorId, \PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();
    }
}
