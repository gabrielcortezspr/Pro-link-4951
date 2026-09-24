<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `crea_cats` e `crea_cat_arts`: o cache local da Certidão de Acervo Técnico (D76).
 *
 * Mesma natureza de `AcervoRepository`: resposta real da API, datada (`cat_dt_consulta`) e com
 * selo (`cat_hash`), nunca dado escrito à mão, nunca fonte para validar documento. A validação
 * sempre volta a chamar a API.
 *
 * O vínculo com as ARTs é o que o motor usa: a view `crea_evidencias` junta `crea_cat_arts` a
 * cada ART, e a ART coberta por CAT vigente reforça a competência técnica
 * (`Support\Compatibilidade::REFORCO_CAT`).
 */
final class CatRepository extends Repositorio
{
    /**
     * A CAT e os números das ARTs que ela agrupa, ou null se ainda não está no acervo.
     *
     * @return array{cat: array<string, mixed>, arts: list<string>}|null
     */
    public function porNumero(string $numero): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cat_id, cat_numero, cat_pro_rnp, cat_tipo, cat_dt_emissao, cat_dt_validade,
                    cat_finalidade, cat_hash, cat_dt_consulta
               FROM crea_cats
              WHERE cat_numero = :numero AND cat_status = :ativo'
        );
        $stmt->execute([':numero' => $numero, ':ativo' => STATUS_ATIVO]);
        $cat = $stmt->fetch();

        if ($cat === false) {
            return null;
        }

        return ['cat' => $cat, 'arts' => $this->numerosDasArts((int) $cat['cat_id'])];
    }

    /**
     * Grava a certidão e devolve o id. Reimportar a mesma CAT atualiza a linha.
     *
     * `ON DUPLICATE KEY UPDATE` é seguro aqui porque o índice que o dispara, `uq_cat_numero`, é
     * de coluna NOT NULL (a armadilha do `CLAUDE.md`, D25 e D28).
     *
     * @param array<string, string|null> $cat
     */
    public function gravar(array $cat, string $hash, string $dtConsulta): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO crea_cats
                (cat_numero, cat_pro_rnp, cat_tipo, cat_dt_emissao, cat_dt_validade,
                 cat_finalidade, cat_hash, cat_dt_consulta, cat_status)
             VALUES
                (:numero, :rnp, :tipo, :emissao, :validade, :finalidade, :hash, :consulta, :ativo)
             ON DUPLICATE KEY UPDATE
                cat_pro_rnp     = VALUES(cat_pro_rnp),
                cat_tipo        = VALUES(cat_tipo),
                cat_dt_emissao  = VALUES(cat_dt_emissao),
                cat_dt_validade = VALUES(cat_dt_validade),
                cat_finalidade  = VALUES(cat_finalidade),
                cat_hash        = VALUES(cat_hash),
                cat_dt_consulta = VALUES(cat_dt_consulta),
                cat_status      = VALUES(cat_status)'
        );

        $stmt->execute([
            ':numero'     => $cat['cat_numero'],
            ':rnp'        => $cat['cat_pro_rnp'],
            ':tipo'       => $cat['cat_tipo'],
            ':emissao'    => $cat['cat_dt_emissao'],
            ':validade'   => $cat['cat_dt_validade'],
            ':finalidade' => $cat['cat_finalidade'],
            ':hash'       => $hash,
            ':consulta'   => $dtConsulta,
            ':ativo'      => STATUS_ATIVO,
        ]);

        $id = (int) $this->pdo->lastInsertId();

        // lastInsertId devolve 0 quando o ON DUPLICATE só atualizou: nesse caso o id já existe.
        return $id > 0 ? $id : $this->idPorNumero((string) $cat['cat_numero']);
    }

    /**
     * Deixa ativos exatamente os vínculos da lista, e marca os outros como excluídos.
     *
     * Nada é apagado (item 8.6j): o vínculo que a API deixou de devolver ganha `cta_status = 'X'`,
     * e volta a `'A'` se a API voltar a devolvê-lo. É o mesmo movimento de
     * `AcervoRepository::sincronizarAtividades`.
     *
     * @param list<int> $artIds
     * @return array{ativos: int}
     */
    public function sincronizarArts(int $catId, array $artIds): array
    {
        $encerrar = $this->pdo->prepare(
            'UPDATE crea_cat_arts SET cta_status = :excluido
              WHERE cta_cat_id = :cat AND cta_status = :ativo'
        );
        $encerrar->execute([':excluido' => STATUS_EXCLUIDO, ':cat' => $catId, ':ativo' => STATUS_ATIVO]);

        $inserir = $this->pdo->prepare(
            'INSERT INTO crea_cat_arts (cta_cat_id, cta_art_id, cta_status)
             VALUES (:cat, :art, :ativo)
             ON DUPLICATE KEY UPDATE cta_status = VALUES(cta_status)'
        );

        $unicos = array_values(array_unique($artIds));

        foreach ($unicos as $artId) {
            $inserir->execute([':cat' => $catId, ':art' => $artId, ':ativo' => STATUS_ATIVO]);
        }

        return ['ativos' => count($unicos)];
    }

    /**
     * As CATs do profissional, cada uma com os números das ARTs que agrupa.
     *
     * @return list<array<string, mixed>>
     */
    public function porRnp(string $rnp): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT cat_id, cat_numero, cat_tipo, cat_dt_emissao, cat_dt_validade, cat_finalidade,
                    cat_hash, cat_dt_consulta
               FROM crea_cats
              WHERE cat_pro_rnp = :rnp AND cat_status = :ativo
              ORDER BY cat_dt_emissao DESC, cat_numero'
        );
        $stmt->execute([':rnp' => $rnp, ':ativo' => STATUS_ATIVO]);

        $cats = [];

        foreach ($stmt->fetchAll() as $cat) {
            $cat['arts'] = $this->numerosDasArts((int) $cat['cat_id']);
            $cats[] = $cat;
        }

        return $cats;
    }

    /**
     * As certidões que cobrem cada ART, para as telas que listam ARTs (perfil do profissional e
     * da empresa).
     *
     * Uma ART pode estar em mais de uma CAT (o profissional renova a certidão, ou pede outra para
     * outra finalidade), e a aba Acervo agrupa por certidão, então todas voltam. A ordem é a de
     * validade mais longa primeiro e validade nula por último: é a que a tela usa, e a primeira
     * da lista é a que ainda vale por mais tempo. Cada CAT volta com os números de todas as ARTs
     * que agrupa, porque é sobre essa lista que o selo dela é reconferido.
     *
     * @param list<int> $artIds
     * @return array<int, list<array<string, mixed>>> art_id => CATs, cada uma com a chave `arts`
     */
    public function porArtIds(array $artIds): array
    {
        $artIds = array_values(array_unique(array_map('intval', $artIds)));

        if ($artIds === []) {
            return [];
        }

        [$marcas, $params] = $this->marcadores($artIds);

        $stmt = $this->pdo->prepare(
            'SELECT cta.cta_art_id, c.cat_id, c.cat_numero, c.cat_pro_rnp, c.cat_tipo,
                    c.cat_dt_emissao, c.cat_dt_validade, c.cat_finalidade, c.cat_hash,
                    c.cat_dt_consulta
               FROM crea_cat_arts cta
               JOIN crea_cats c ON c.cat_id = cta.cta_cat_id
              WHERE cta.cta_art_id IN (' . implode(', ', $marcas) . ')
                AND cta.cta_status = :ativo_cta
                AND c.cat_status = :ativo_cat
              ORDER BY c.cat_dt_validade IS NULL, c.cat_dt_validade DESC, c.cat_numero'
        );
        $stmt->execute($params + [':ativo_cta' => STATUS_ATIVO, ':ativo_cat' => STATUS_ATIVO]);

        $porArt = [];
        $arts   = [];

        foreach ($stmt->fetchAll() as $linha) {
            $artId = (int) $linha['cta_art_id'];
            $catId = (int) $linha['cat_id'];
            $arts[$catId] ??= $this->numerosDasArts($catId);

            unset($linha['cta_art_id']);
            $linha['arts'] = $arts[$catId];
            $porArt[$artId][] = $linha;
        }

        return $porArt;
    }

    /** Quantas CATs ativas o profissional tem no acervo local. */
    public function contarPorRnp(string $rnp): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM crea_cats WHERE cat_pro_rnp = :rnp AND cat_status = :ativo'
        );
        $stmt->execute([':rnp' => $rnp, ':ativo' => STATUS_ATIVO]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Profissionais cadastrados que ainda não têm nenhuma CAT importada, com o consentimento de
     * consulta à API concedido. É a fila do `scripts/importar-cats.php`, que traz as certidões
     * de quem se cadastrou antes da D76.
     *
     * "Sem CAT importada" não distingue "nunca consultado" de "consultado e não tem CAT": a
     * tabela não guarda a consulta vazia. Rodar o script de novo consulta outra vez quem não tem
     * certidão, e é por isso que ele tem limite obrigatório.
     *
     * @return list<array{usu_id: int, prf_rnp: string, usu_nome: string}>
     */
    public function profissionaisSemCat(int $limite, string $finalidade): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.usu_id, p.prf_rnp, u.usu_nome
               FROM pro_profissionais p
               JOIN sis_usuarios u        ON u.usu_id = p.prf_usu_id AND u.usu_status = :ativo_usu
               JOIN sis_consentimentos co ON co.con_usu_id = u.usu_id
                                         AND co.con_finalidade = :finalidade
                                         AND co.con_concedido = 1
                                         AND co.con_status = :ativo_con
              WHERE p.prf_status = :ativo_prf
                AND p.prf_rnp IS NOT NULL
                AND p.prf_dt_sincronizacao IS NOT NULL
                AND NOT EXISTS (
                      SELECT 1 FROM crea_cats c
                       WHERE c.cat_pro_rnp = p.prf_rnp AND c.cat_status = :ativo_cat
                    )
              GROUP BY u.usu_id, p.prf_rnp, u.usu_nome
              ORDER BY u.usu_id
              LIMIT ' . max(1, $limite)
        );
        $stmt->execute([
            ':ativo_usu'  => STATUS_ATIVO,
            ':finalidade' => $finalidade,
            ':ativo_con'  => STATUS_ATIVO,
            ':ativo_prf'  => STATUS_ATIVO,
            ':ativo_cat'  => STATUS_ATIVO,
        ]);

        return array_map(static fn (array $l): array => [
            'usu_id'   => (int) $l['usu_id'],
            'prf_rnp'  => (string) $l['prf_rnp'],
            'usu_nome' => (string) $l['usu_nome'],
        ], $stmt->fetchAll());
    }

    // ---------------------------------------------------------------- interno

    /** @return list<string> */
    private function numerosDasArts(int $catId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.art_numero
               FROM crea_cat_arts cta
               JOIN crea_arts a ON a.art_id = cta.cta_art_id
              WHERE cta.cta_cat_id = :cat AND cta.cta_status = :ativo
              ORDER BY a.art_numero'
        );
        $stmt->execute([':cat' => $catId, ':ativo' => STATUS_ATIVO]);

        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function idPorNumero(string $numero): int
    {
        $stmt = $this->pdo->prepare('SELECT cat_id FROM crea_cats WHERE cat_numero = :numero');
        $stmt->execute([':numero' => $numero]);

        return (int) $stmt->fetchColumn();
    }
}
