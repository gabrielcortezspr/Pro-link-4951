<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * O que está em exclusão lógica, e o caminho de volta (edital 8.6j).
 *
 * O item 8.6j não pede só que nada seja apagado: pede que o excluído "continue acessível somente
 * por mecanismo administrativo de lixeira". Enquanto essa tela não existia, a primeira metade da
 * regra estava cumprida e a segunda não — o registro em `'X'` sumia das consultas operacionais e
 * de todo lugar, o que na prática é igual a ter sumido.
 *
 * ## Por que a data e o autor da exclusão vêm da auditoria
 *
 * Nenhuma tabela tem coluna de "excluído em" ou "excluído por". Acrescentá-las duplicaria o que
 * `sis_auditoria` já guarda com precisão de milissegundo, insert-only e protegido por trigger, e
 * dois lugares para o mesmo fato divergem no primeiro caminho de código que esquecer um deles.
 * A subconsulta pega a linha `EXCLUIR` mais recente daquele registro. Registro sem linha de
 * auditoria (carga inicial, ou `UPDATE` direto no banco) aparece com origem desconhecida, e isso
 * é informação, não falha.
 *
 * ## Uma consulta por entidade, de propósito
 *
 * Seria possível montar o SQL a partir de um mapa de tabelas e colunas. Não é: identificador não
 * entra em placeholder no MariaDB, e o SQL montado por concatenação é exatamente o que o item
 * 8.5c manda evitar. Cada entidade tem o seu método, com o nome da tabela escrito à mão.
 */
final class LixeiraRepository extends Repositorio
{
    /** As entidades que a lixeira cobre, na ordem em que a tela as mostra. */
    public const ENTIDADES = [
        'sis_usuarios',
        'pro_demandas',
        'pro_experiencias',
        'pro_mensagens',
        'pro_denuncias',
    ];

    /**
     * Quantos registros excluídos há em cada entidade.
     *
     * @return array<string, int>
     */
    public function contagens(): array
    {
        $sql = 'SELECT
                    (SELECT COUNT(*) FROM sis_usuarios     WHERE usu_status = :e1) AS sis_usuarios,
                    (SELECT COUNT(*) FROM pro_demandas     WHERE dem_status = :e2) AS pro_demandas,
                    (SELECT COUNT(*) FROM pro_experiencias WHERE exp_status = :e3) AS pro_experiencias,
                    (SELECT COUNT(*) FROM pro_mensagens    WHERE msg_status = :e4) AS pro_mensagens,
                    (SELECT COUNT(*) FROM pro_denuncias    WHERE den_status = :e5) AS pro_denuncias';

        $stmt = $this->pdo->prepare($sql);

        // Placeholder nomeado não repete na mesma query: ATTR_EMULATE_PREPARES está desligado.
        foreach (range(1, 5) as $i) {
            $stmt->bindValue(':e' . $i, STATUS_EXCLUIDO);
        }

        $stmt->execute();

        return array_map('intval', $stmt->fetch() ?: []);
    }

    /**
     * Uma página de registros excluídos de uma entidade.
     *
     * @return list<array<string, mixed>>
     */
    public function listar(string $entidade, int $limite, int $deslocamento): array
    {
        return match ($entidade) {
            'sis_usuarios'     => $this->usuarios($limite, $deslocamento),
            'pro_demandas'     => $this->demandas($limite, $deslocamento),
            'pro_experiencias' => $this->experiencias($limite, $deslocamento),
            'pro_mensagens'    => $this->mensagens($limite, $deslocamento),
            'pro_denuncias'    => $this->denuncias($limite, $deslocamento),
            default            => [],
        };
    }

    /**
     * Devolve o status de um registro excluído a `'A'`.
     *
     * Devolve false quando nada casou, o que quer dizer que o registro não existe **ou** que já
     * está ativo. Quem chama trata os dois como "não restaurei", porque do ponto de vista do
     * administrador o efeito é o mesmo e distinguir exigiria uma segunda consulta sem ganho.
     */
    public function restaurar(string $entidade, int $id): bool
    {
        $sql = match ($entidade) {
            'sis_usuarios'     => 'UPDATE sis_usuarios     SET usu_status = :ativo WHERE usu_id = :id AND usu_status = :excluido',
            'pro_demandas'     => 'UPDATE pro_demandas     SET dem_status = :ativo WHERE dem_id = :id AND dem_status = :excluido',
            'pro_experiencias' => 'UPDATE pro_experiencias SET exp_status = :ativo WHERE exp_id = :id AND exp_status = :excluido',
            'pro_mensagens'    => 'UPDATE pro_mensagens    SET msg_status = :ativo WHERE msg_id = :id AND msg_status = :excluido',
            'pro_denuncias'    => 'UPDATE pro_denuncias    SET den_status = :ativo WHERE den_id = :id AND den_status = :excluido',
            default            => null,
        };

        if ($sql === null) {
            return false;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->bindValue(':excluido', STATUS_EXCLUIDO);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Um registro excluído, com a origem da exclusão. É o que o serviço lê antes de restaurar.
     *
     * Traz o dono porque é ele que decide se a restauração é permitida: conta que o próprio
     * titular mandou excluir não volta por ato administrativo.
     *
     * @return array<string, mixed>|null
     */
    public function porId(string $entidade, int $id): ?array
    {
        $sql = match ($entidade) {
            'sis_usuarios' =>
                'SELECT usu_id AS id, usu_nome AS titulo, usu_id AS dono_id
                   FROM sis_usuarios WHERE usu_id = :id AND usu_status = :excluido',
            'pro_demandas' =>
                'SELECT dem_id AS id, dem_titulo AS titulo, dem_usu_id AS dono_id
                   FROM pro_demandas WHERE dem_id = :id AND dem_status = :excluido',
            'pro_experiencias' =>
                'SELECT e.exp_id AS id, e.exp_titulo AS titulo, p.prf_usu_id AS dono_id
                   FROM pro_experiencias e
                   JOIN pro_profissionais p ON p.prf_id = e.exp_prf_id
                  WHERE e.exp_id = :id AND e.exp_status = :excluido',
            'pro_mensagens' =>
                'SELECT msg_id AS id, CONCAT("Mensagem na manifestação ", msg_man_id) AS titulo,
                        msg_usu_id AS dono_id
                   FROM pro_mensagens WHERE msg_id = :id AND msg_status = :excluido',
            'pro_denuncias' =>
                'SELECT den_id AS id, CONCAT(den_tipo, " em ", den_entidade) AS titulo,
                        den_usu_id AS dono_id
                   FROM pro_denuncias WHERE den_id = :id AND den_status = :excluido',
            default => null,
        };

        if ($sql === null) {
            return null;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->bindValue(':excluido', STATUS_EXCLUIDO);
        $stmt->execute();

        $linha = $stmt->fetch();

        if ($linha === false) {
            return null;
        }

        $origem = $this->origemDaExclusao($entidade, (int) $linha['id']);

        return [
            'entidade'     => $entidade,
            'id'           => (int) $linha['id'],
            'titulo'       => (string) $linha['titulo'],
            'dono_id'      => (int) $linha['dono_id'],
            'pelo_titular' => $origem['excluido_por_id'] !== null
                && $origem['excluido_por_id'] === (int) $linha['dono_id'],
        ] + $origem;
    }

    /**
     * Quem excluiu e quando, pela trilha de auditoria.
     *
     * `pelo_titular` é a comparação entre o autor da exclusão e o dono do registro. Para
     * `sis_usuarios` o dono é o próprio registro; para as demais, é a coluna de usuário da linha.
     * A distinção existe porque exclusão pedida pelo titular é exercício de direito (LGPD, art.
     * 18), e desfazê-la administrativamente sem que ele saiba não é restauração: é reativar dado
     * que alguém mandou apagar.
     *
     * @return array{excluido_em: string|null, excluido_por_id: int|null, excluido_por: string|null}
     */
    private function origemDaExclusao(string $entidade, int $id): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.aud_dt_registro, a.aud_usu_id, u.usu_nome
               FROM sis_auditoria a
               LEFT JOIN sis_usuarios u ON u.usu_id = a.aud_usu_id
              WHERE a.aud_entidade = :entidade
                AND a.aud_entidade_id = :id
                AND a.aud_acao = :acao
              ORDER BY a.aud_id DESC
              LIMIT 1'
        );

        $stmt->bindValue(':entidade', $entidade);
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->bindValue(':acao', 'EXCLUIR');
        $stmt->execute();

        $linha = $stmt->fetch();

        if ($linha === false) {
            return ['excluido_em' => null, 'excluido_por_id' => null, 'excluido_por' => null];
        }

        return [
            'excluido_em'     => (string) $linha['aud_dt_registro'],
            'excluido_por_id' => $linha['aud_usu_id'] === null ? null : (int) $linha['aud_usu_id'],
            'excluido_por'    => $linha['usu_nome'] === null ? null : (string) $linha['usu_nome'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function usuarios(int $limite, int $deslocamento): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.usu_id AS id, u.usu_nome AS titulo, u.usu_email AS detalhe,
                    p.per_codigo AS categoria, u.usu_dt_registro AS criado_em,
                    u.usu_id AS dono_id
               FROM sis_usuarios u
               JOIN sis_perfis p ON p.per_id = u.usu_per_id
              WHERE u.usu_status = :excluido
              ORDER BY u.usu_id DESC
              LIMIT :limite OFFSET :deslocamento'
        );

        return $this->pagina($stmt, 'sis_usuarios', $limite, $deslocamento);
    }

    /** @return list<array<string, mixed>> */
    private function demandas(int $limite, int $deslocamento): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.dem_id AS id, d.dem_titulo AS titulo, u.usu_nome AS detalhe,
                    d.dem_situacao AS categoria, d.dem_dt_registro AS criado_em,
                    d.dem_usu_id AS dono_id
               FROM pro_demandas d
               JOIN sis_usuarios u ON u.usu_id = d.dem_usu_id
              WHERE d.dem_status = :excluido
              ORDER BY d.dem_id DESC
              LIMIT :limite OFFSET :deslocamento'
        );

        return $this->pagina($stmt, 'pro_demandas', $limite, $deslocamento);
    }

    /** @return list<array<string, mixed>> */
    private function experiencias(int $limite, int $deslocamento): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT e.exp_id AS id, e.exp_titulo AS titulo, u.usu_nome AS detalhe,
                    NULL AS categoria, e.exp_dt_registro AS criado_em,
                    p.prf_usu_id AS dono_id
               FROM pro_experiencias e
               JOIN pro_profissionais p ON p.prf_id = e.exp_prf_id
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
              WHERE e.exp_status = :excluido
              ORDER BY e.exp_id DESC
              LIMIT :limite OFFSET :deslocamento'
        );

        return $this->pagina($stmt, 'pro_experiencias', $limite, $deslocamento);
    }

    /**
     * Mensagens excluídas.
     *
     * O corpo **não** é trazido. A lixeira é tela de administração, e conteúdo de conversa
     * privada entre duas partes não precisa passar pelos olhos do moderador para que ele decida
     * restaurar uma linha: o que ele precisa saber é de quem é, de quando é, e a qual
     * manifestação pertence. Quando o conteúdo importa, existe a denúncia, que é o caminho com
     * motivo declarado.
     *
     * @return list<array<string, mixed>>
     */
    private function mensagens(int $limite, int $deslocamento): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT m.msg_id AS id,
                    CONCAT("Mensagem na manifestação ", m.msg_man_id) AS titulo,
                    u.usu_nome AS detalhe,
                    NULL AS categoria, m.msg_dt_registro AS criado_em,
                    m.msg_usu_id AS dono_id
               FROM pro_mensagens m
               JOIN sis_usuarios u ON u.usu_id = m.msg_usu_id
              WHERE m.msg_status = :excluido
              ORDER BY m.msg_id DESC
              LIMIT :limite OFFSET :deslocamento'
        );

        return $this->pagina($stmt, 'pro_mensagens', $limite, $deslocamento);
    }

    /** @return list<array<string, mixed>> */
    private function denuncias(int $limite, int $deslocamento): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.den_id AS id,
                    CONCAT(d.den_tipo, " em ", d.den_entidade) AS titulo,
                    u.usu_nome AS detalhe,
                    d.den_situacao AS categoria, d.den_dt_registro AS criado_em,
                    d.den_usu_id AS dono_id
               FROM pro_denuncias d
               JOIN sis_usuarios u ON u.usu_id = d.den_usu_id
              WHERE d.den_status = :excluido
              ORDER BY d.den_id DESC
              LIMIT :limite OFFSET :deslocamento'
        );

        return $this->pagina($stmt, 'pro_denuncias', $limite, $deslocamento);
    }

    /**
     * Executa a consulta de página e acrescenta a origem da exclusão a cada linha.
     *
     * Uma consulta de auditoria por linha, e não um JOIN: a linha certa é "a mais recente com
     * ação EXCLUIR", que num JOIN viraria função de janela ou subconsulta correlacionada dentro
     * de um SELECT que já tem dois JOINs. A página é de vinte itens, e a coluna
     * `(aud_entidade, aud_entidade_id)` é indexada.
     *
     * @return list<array<string, mixed>>
     */
    private function pagina(\PDOStatement $stmt, string $entidade, int $limite, int $deslocamento): array
    {
        $stmt->bindValue(':excluido', STATUS_EXCLUIDO);
        $stmt->bindValue(':limite', $limite, \PDO::PARAM_INT);
        $stmt->bindValue(':deslocamento', $deslocamento, \PDO::PARAM_INT);
        $stmt->execute();

        $linhas = [];

        foreach ($stmt->fetchAll() as $linha) {
            $origem = $this->origemDaExclusao($entidade, (int) $linha['id']);

            $linhas[] = [
                'entidade'     => $entidade,
                'id'           => (int) $linha['id'],
                'titulo'       => (string) $linha['titulo'],
                'detalhe'      => (string) ($linha['detalhe'] ?? ''),
                'categoria'    => $linha['categoria'] === null ? null : (string) $linha['categoria'],
                'criado_em'    => (string) $linha['criado_em'],
                'dono_id'      => (int) $linha['dono_id'],
                'pelo_titular' => $origem['excluido_por_id'] !== null
                    && $origem['excluido_por_id'] === (int) $linha['dono_id'],
            ] + $origem;
        }

        return $linhas;
    }
}
