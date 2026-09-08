<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `crea_arts` e `crea_art_atividades`: o cache local da resposta real da API (RF02, edital 8.4).
 *
 * Não é base própria simulando a API, e a diferença está nas colunas: toda linha guarda
 * `art_dt_consulta` (quando a API respondeu aquilo) e `art_hash` (o selo daquele conteúdo).
 * Nada aqui é escrito à mão, e nada aqui serve de fonte para validar documento — validação
 * sempre volta a chamar a API.
 */
final class AcervoRepository extends Repositorio
{
    /**
     * A ART e suas atividades ativas, ou null se ainda não está no acervo.
     *
     * @return array{art: array<string, mixed>, atividades: list<array{tos_codigo: string, descricao: string|null}>}|null
     */
    public function porNumero(string $numero): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT art_id, art_numero, art_pro_rnp, art_tipo, art_forma_registro,
                    art_contratante_nome, art_objeto, art_local_uf, art_local_municipio,
                    art_situacao, art_hash, art_dt_consulta, art_status
               FROM crea_arts
              WHERE art_numero = :numero'
        );
        $stmt->execute([':numero' => $numero]);
        $art = $stmt->fetch();

        if ($art === false) {
            return null;
        }

        return ['art' => $art, 'atividades' => $this->atividades((int) $art['art_id'])];
    }

    /** @return list<array{tos_codigo: string, descricao: string|null}> */
    public function atividades(int $artId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ata_tos_codigo AS tos_codigo, ata_descricao AS descricao
               FROM crea_art_atividades
              WHERE ata_art_id = :art AND ata_status = :ativo
              ORDER BY ata_id'
        );
        $stmt->execute([':art' => $artId, ':ativo' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }

    /**
     * Grava a ART já mesclada e selada, e devolve o `art_id`.
     *
     * `ON DUPLICATE KEY UPDATE` e não um `UPDATE` condicional: a mescla já aconteceu em
     * `Support\Acervo`, aqui só se persiste o resultado. A cláusula existe para o caso de duas
     * importações concorrentes do mesmo profissional — `uq_art_numero` faria a segunda estourar,
     * e estourar no meio de uma importação de 290 ARTs seria pior do que reescrever a linha com
     * o mesmo conteúdo.
     *
     * @param array<string, string|null> $art
     */
    public function gravar(array $art, string $hash, string $dtConsulta): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO crea_arts
                (art_numero, art_pro_rnp, art_tipo, art_forma_registro, art_contratante_nome,
                 art_objeto, art_local_uf, art_local_municipio, art_situacao, art_hash,
                 art_dt_consulta, art_status)
             VALUES
                (:numero, :rnp, :tipo, :forma, :contratante, :objeto, :uf, :municipio,
                 :situacao, :hash, :consulta, :ativo)
             ON DUPLICATE KEY UPDATE
                art_pro_rnp          = VALUES(art_pro_rnp),
                art_tipo             = VALUES(art_tipo),
                art_forma_registro   = VALUES(art_forma_registro),
                art_contratante_nome = VALUES(art_contratante_nome),
                art_objeto           = VALUES(art_objeto),
                art_local_uf         = VALUES(art_local_uf),
                art_local_municipio  = VALUES(art_local_municipio),
                art_situacao         = VALUES(art_situacao),
                art_hash             = VALUES(art_hash),
                art_dt_consulta      = VALUES(art_dt_consulta),
                art_status           = VALUES(art_status)'
        );

        $stmt->execute([
            ':numero'      => $art['art_numero'],
            ':rnp'         => $art['art_pro_rnp'],
            ':tipo'        => $art['art_tipo'],
            ':forma'       => $art['art_forma_registro'],
            ':contratante' => $art['art_contratante_nome'],
            ':objeto'      => $art['art_objeto'],
            ':uf'          => $art['art_local_uf'],
            ':municipio'   => $art['art_local_municipio'],
            ':situacao'    => $art['art_situacao'],
            ':hash'        => $hash,
            ':consulta'    => $dtConsulta,
            ':ativo'       => STATUS_ATIVO,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        // lastInsertId devolve 0 quando o ON DUPLICATE só atualizou: nesse caso o id já existe.
        return $id > 0 ? $id : (int) $this->idPorNumero((string) $art['art_numero']);
    }

    /**
     * Sincroniza as atividades da ART com o que a API devolveu.
     *
     * Nada é apagado (item 8.6j): as que sumiram da resposta viram `ata_status = 'X'` e as que
     * voltarem são reativadas. Fica registrado que a API já disse outra coisa um dia, o que num
     * índice de evidência é informação, não sujeira.
     *
     * @param list<array{tos_codigo: string, descricao: string|null}> $atividades
     * @return array{ativas: int, encerradas: int}
     */
    public function sincronizarAtividades(int $artId, array $atividades): array
    {
        $encerrar = $this->pdo->prepare(
            'UPDATE crea_art_atividades SET ata_status = :excluido
              WHERE ata_art_id = :art AND ata_status = :ativo'
        );
        $encerrar->execute([':excluido' => STATUS_EXCLUIDO, ':art' => $artId, ':ativo' => STATUS_ATIVO]);
        $encerradas = $encerrar->rowCount();

        $inserir = $this->pdo->prepare(
            'INSERT INTO crea_art_atividades (ata_art_id, ata_tos_codigo, ata_descricao, ata_status)
             VALUES (:art, :codigo, :descricao, :ativo)
             ON DUPLICATE KEY UPDATE
                ata_descricao = VALUES(ata_descricao),
                ata_status    = VALUES(ata_status)'
        );

        foreach ($atividades as $atividade) {
            $inserir->execute([
                ':art'       => $artId,
                ':codigo'    => $atividade['tos_codigo'],
                ':descricao' => $atividade['descricao'],
                ':ativo'     => STATUS_ATIVO,
            ]);
        }

        return ['ativas' => count($atividades), 'encerradas' => max(0, $encerradas - count($atividades))];
    }

    /** Quantas ARTs ativas o profissional tem no acervo. Alimenta `prf_em_construcao`. */
    public function contarPorRnp(string $rnp): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM crea_arts WHERE art_pro_rnp = :rnp AND art_status = :ativo'
        );
        $stmt->execute([':rnp' => $rnp, ':ativo' => STATUS_ATIVO]);

        return (int) $stmt->fetchColumn();
    }

    private function idPorNumero(string $numero): int
    {
        $stmt = $this->pdo->prepare('SELECT art_id FROM crea_arts WHERE art_numero = :numero');
        $stmt->execute([':numero' => $numero]);

        return (int) $stmt->fetchColumn();
    }
}
