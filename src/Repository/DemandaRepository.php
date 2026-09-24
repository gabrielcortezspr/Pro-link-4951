<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `pro_demandas` e `pro_demanda_tos`: o que precisa ser feito, e em que códigos TOS isso se
 * traduz (RF04).
 *
 * A demanda tem duas metades. A primeira é texto para gente ler — título, escopo, local, tipo de
 * contrato. A segunda são os códigos TOS, e é a única que o motor enxerga: `aat_descricao` e
 * `art_objeto` da massa não discriminam ninguém, só `tos_codigo` discrimina.
 *
 * `dts_peso` separa atividade principal de secundária. Não é enfeite: entra na média ponderada
 * da dimensão de competência, então uma demanda que lista cinco códigos secundários não dilui o
 * principal.
 */
final class DemandaRepository extends Repositorio
{
    private const CAMPOS = 'dem_id, dem_usu_id, dem_titulo, dem_escopo, dem_local_uf,
                            dem_local_municipio, dem_tipo_contrato, dem_inicio_ate, dem_alvo, dem_situacao,
                            dem_dt_publicacao, dem_dt_encerramento, dem_dt_registro, dem_status';

    /** @return array<string, mixed>|null */
    public function porId(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ' FROM pro_demandas WHERE dem_id = :id'
        );
        $stmt->execute([':id' => $id]);

        return $stmt->fetch() ?: null;
    }

    /**
     * O painel do demandante: as demandas dele, com a contagem de interessados.
     *
     * A contagem vem por subconsulta e não por JOIN com GROUP BY, para uma demanda sem
     * manifestação nenhuma continuar aparecendo com zero em vez de sumir da lista.
     *
     * @return list<array<string, mixed>>
     */
    public function doUsuario(int $usuarioId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . ',
                    (SELECT COUNT(*) FROM pro_manifestacoes
                      WHERE man_dem_id = dem_id AND man_status = :ativo_m) AS interessados
               FROM pro_demandas
              WHERE dem_usu_id = :usuario AND dem_status = :ativo
              ORDER BY dem_id DESC'
        );
        $stmt->execute([':usuario' => $usuarioId, ':ativo' => STATUS_ATIVO, ':ativo_m' => STATUS_ATIVO]);

        return $stmt->fetchAll();
    }

    /**
     * As demandas publicadas e abertas, para quem procura oportunidade.
     *
     * ENCERRADA não entra: a demanda continua no banco e no painel do dono, mas sai da vitrine.
     *
     * @return list<array<string, mixed>>
     */
    public function abertas(int $limite = 50): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ' . self::CAMPOS . '
               FROM pro_demandas
              WHERE dem_status = :ativo
                AND dem_situacao <> :encerrada
                AND dem_dt_publicacao IS NOT NULL
              ORDER BY dem_dt_publicacao DESC
              LIMIT ' . max(1, min($limite, 100))
        );
        $stmt->execute([':ativo' => STATUS_ATIVO, ':encerrada' => 'ENCERRADA']);

        return $stmt->fetchAll();
    }

    /**
     * @param array{usuario_id: int, titulo: string, escopo: string, local_uf: ?string,
     *              local_municipio: ?string, tipo_contrato: ?string, alvo: string} $dados
     */
    public function criar(array $dados): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO pro_demandas
                (dem_usu_id, dem_titulo, dem_escopo, dem_local_uf, dem_local_municipio,
                 dem_tipo_contrato, dem_inicio_ate, dem_alvo, dem_situacao, dem_status)
             VALUES
                (:usuario, :titulo, :escopo, :uf, :municipio, :contrato, :inicio, :alvo, :situacao, :ativo)'
        );

        $stmt->execute([
            ':usuario'   => $dados['usuario_id'],
            ':titulo'    => $dados['titulo'],
            ':escopo'    => $dados['escopo'],
            ':uf'        => $dados['local_uf'],
            ':municipio' => $dados['local_municipio'],
            ':contrato'  => $dados['tipo_contrato'],
            ':inicio'    => $dados['inicio_ate'] ?? null,
            ':alvo'      => $dados['alvo'],
            ':situacao'  => 'ABERTA',
            ':ativo'     => STATUS_ATIVO,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array{titulo: string, escopo: string, local_uf: ?string, local_municipio: ?string,
     *              tipo_contrato: ?string, alvo: string} $dados
     */
    public function atualizar(int $id, array $dados): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE pro_demandas
                SET dem_titulo = :titulo, dem_escopo = :escopo, dem_local_uf = :uf,
                    dem_local_municipio = :municipio, dem_tipo_contrato = :contrato,
                    dem_inicio_ate = :inicio, dem_alvo = :alvo
              WHERE dem_id = :id'
        );

        $stmt->execute([
            ':titulo'    => $dados['titulo'],
            ':escopo'    => $dados['escopo'],
            ':uf'        => $dados['local_uf'],
            ':municipio' => $dados['local_municipio'],
            ':contrato'  => $dados['tipo_contrato'],
            ':inicio'    => $dados['inicio_ate'] ?? null,
            ':alvo'      => $dados['alvo'],
            ':id'        => $id,
        ]);
    }

    /** Publicar é carimbar a data: é ela que faz a demanda aparecer para quem não é o dono. */
    public function publicar(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE pro_demandas SET dem_dt_publicacao = NOW() WHERE dem_id = :id'
        )->execute([':id' => $id]);
    }

    public function mudarSituacao(int $id, string $situacao): void
    {
        $this->pdo->prepare(
            'UPDATE pro_demandas
                SET dem_situacao = :situacao,
                    dem_dt_encerramento = CASE WHEN :encerrada = :situacao_2 THEN NOW()
                                               ELSE dem_dt_encerramento END
              WHERE dem_id = :id'
        )->execute([
            ':situacao'   => $situacao,
            ':encerrada'  => 'ENCERRADA',
            ':situacao_2' => $situacao,
            ':id'         => $id,
        ]);
    }

    /** Exclusão lógica (item 8.6j): a demanda sai das consultas e permanece no banco. */
    public function excluir(int $id): void
    {
        $this->pdo->prepare(
            'UPDATE pro_demandas SET dem_status = :excluido WHERE dem_id = :id'
        )->execute([':excluido' => STATUS_EXCLUIDO, ':id' => $id]);
    }

    // ---------------------------------------------------------------- códigos TOS

    /**
     * Os códigos da demanda, com peso e descrição montada.
     *
     * @return list<array{codigo: string, peso: float, grupo: string, subgrupo: ?string,
     *                    obra_servico: ?string, complementar: ?string}>
     */
    public function tos(int $demandaId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.dts_tos_codigo AS codigo, d.dts_peso AS peso,
                    t.tos_grupo AS grupo, t.tos_subgrupo AS subgrupo,
                    t.tos_obra_servico AS obra_servico, t.tos_complementar AS complementar
               FROM pro_demanda_tos d
               JOIN crea_tos t ON t.tos_codigo = d.dts_tos_codigo
              WHERE d.dts_dem_id = :dem AND d.dts_status = :ativo
              ORDER BY d.dts_peso DESC, t.tos_nivel1, t.tos_nivel2, t.tos_nivel3, t.tos_nivel4'
        );
        $stmt->execute([':dem' => $demandaId, ':ativo' => STATUS_ATIVO]);

        $tos = [];

        foreach ($stmt->fetchAll() as $linha) {
            $linha['peso'] = (float) $linha['peso'];
            $tos[] = $linha;
        }

        return $tos;
    }

    /**
     * Sincroniza os códigos da demanda com o que o formulário mandou.
     *
     * Nada é apagado (item 8.6j): código retirado vira `dts_status = 'X'`. Uma demanda que já
     * gerou sessões de compatibilização precisa manter o que ela pedia quando aquelas sessões
     * rodaram — senão a auditoria do administrador reproduz a sessão contra critérios que não
     * são os que valeram (item 12.3).
     *
     * @param array<string, float> $codigos código => peso
     * @return int quantos ficaram ativos
     */
    public function sincronizarTos(int $demandaId, array $codigos): int
    {
        $this->pdo->prepare(
            'UPDATE pro_demanda_tos SET dts_status = :excluido
              WHERE dts_dem_id = :dem AND dts_status = :ativo'
        )->execute([':excluido' => STATUS_EXCLUIDO, ':dem' => $demandaId, ':ativo' => STATUS_ATIVO]);

        $inserir = $this->pdo->prepare(
            'INSERT INTO pro_demanda_tos (dts_dem_id, dts_tos_codigo, dts_peso, dts_status)
             VALUES (:dem, :codigo, :peso, :ativo)
             ON DUPLICATE KEY UPDATE dts_peso = VALUES(dts_peso), dts_status = VALUES(dts_status)'
        );

        foreach ($codigos as $codigo => $peso) {
            $inserir->execute([
                ':dem'    => $demandaId,
                ':codigo' => $codigo,
                ':peso'   => $peso,
                ':ativo'  => STATUS_ATIVO,
            ]);
        }

        return count($codigos);
    }
}
