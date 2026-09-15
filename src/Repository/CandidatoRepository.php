<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Profissional e empresa vistos como uma coisa só: candidato de uma demanda.
 *
 * O motor não quer saber de qual tabela veio quem; quer nome, conta, e as declarações que
 * alimentam as dimensões autodeclaradas. Sem esta camada, `CompatibilizacaoService` teria dois
 * caminhos paralelos para o mesmo cálculo, e a diferença entre eles vazaria para o score.
 *
 * A chave do candidato é `"P:12"` ou `"E:3"`, o mesmo formato que `crea_evidencias` produz
 * (`evi_candidato_tipo` mais `evi_candidato_id`).
 *
 * Empresa não declara tipo de contrato nem disponibilidade geográfica: as colunas não existem em
 * `pro_empresas`. Vêm nulas de propósito, e a dimensão correspondente sai da média em vez de
 * contar zero.
 */
final class CandidatoRepository extends Repositorio
{
    /**
     * Dados de vários candidatos numa consulta, indexados pela chave.
     *
     * Uma query por candidato dentro do laço do motor seria N+1 num caminho que roda a cada
     * abertura do feed.
     *
     * @param  list<string> $chaves  "P:12", "E:3"
     * @return array<string, array<string, mixed>>
     */
    public function porChaves(array $chaves): array
    {
        $profissionais = [];
        $empresas      = [];

        foreach ($chaves as $chave) {
            [$tipo, $id] = array_pad(explode(':', $chave, 2), 2, null);

            if ($tipo === 'P' && ctype_digit((string) $id)) {
                $profissionais[] = (int) $id;
            } elseif ($tipo === 'E' && ctype_digit((string) $id)) {
                $empresas[] = (int) $id;
            }
        }

        return $this->profissionais($profissionais) + $this->empresas($empresas);
    }

    /**
     * @param  list<int> $ids
     * @return array<string, array<string, mixed>>
     */
    private function profissionais(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$marcadores, $params] = $this->marcadores($ids);

        $stmt = $this->pdo->prepare(
            'SELECT p.prf_id, p.prf_usu_id, p.prf_rnp, p.prf_nome_api, p.prf_status_api,
                    p.prf_resumo, p.prf_tipo_contrato, p.prf_disponibilidade, p.prf_em_construcao,
                    u.usu_nome,
                    (SELECT COUNT(*) FROM pro_experiencias e
                      WHERE e.exp_prf_id = p.prf_id AND e.exp_status = :ativo_exp) AS total_experiencias,
                    -- Relato amarrado a uma ART do próprio profissional é o único caso em que o
                    -- autodeclarado encosta em evidência, e a dimensão pontua diferente por isso.
                    (SELECT COUNT(*) FROM pro_experiencias e2
                      WHERE e2.exp_prf_id = p.prf_id AND e2.exp_status = :ativo_exp2
                        AND e2.exp_art_id IS NOT NULL) AS experiencias_com_art
               FROM pro_profissionais p
               JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
              WHERE p.prf_id IN (' . implode(', ', $marcadores) . ')
                AND p.prf_status = :ativo_prf'
        );

        $stmt->bindValue(':ativo_exp', STATUS_ATIVO);
        $stmt->bindValue(':ativo_exp2', STATUS_ATIVO);
        $stmt->bindValue(':ativo_prf', STATUS_ATIVO);

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, \PDO::PARAM_INT);
        }

        $stmt->execute();

        $saida = [];

        foreach ($stmt->fetchAll() as $linha) {
            $saida['P:' . $linha['prf_id']] = [
                'tipo'               => 'P',
                'id'                 => (int) $linha['prf_id'],
                'usuario_id'         => (int) $linha['prf_usu_id'],
                'nome'               => (string) ($linha['prf_nome_api'] ?: $linha['usu_nome']),
                'rnp'                => (string) $linha['prf_rnp'],
                'registro_ativo'     => ($linha['prf_status_api'] ?? null) === 'A',
                'resumo'             => $linha['prf_resumo'],
                'tipo_contrato'      => $linha['prf_tipo_contrato'],
                'disponibilidade'    => $linha['prf_disponibilidade'],
                'em_construcao'      => (bool) $linha['prf_em_construcao'],
                'total_experiencias' => (int) $linha['total_experiencias'],
                'experiencias_com_art' => (int) $linha['experiencias_com_art'],
            ];
        }

        return $saida;
    }

    /**
     * @param  list<int> $ids
     * @return array<string, array<string, mixed>>
     */
    private function empresas(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        [$marcadores, $params] = $this->marcadores($ids);

        $stmt = $this->pdo->prepare(
            'SELECT e.emp_id, e.emp_usu_id, e.emp_registro_crea, e.emp_razao_social,
                    e.emp_nome_fantasia, e.emp_resumo, u.usu_nome
               FROM pro_empresas e
               JOIN sis_usuarios u ON u.usu_id = e.emp_usu_id
              WHERE e.emp_id IN (' . implode(', ', $marcadores) . ')
                AND e.emp_status = :ativo'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, \PDO::PARAM_INT);
        }

        $stmt->execute();

        $saida = [];

        foreach ($stmt->fetchAll() as $linha) {
            $saida['E:' . $linha['emp_id']] = [
                'tipo'               => 'E',
                'id'                 => (int) $linha['emp_id'],
                'usuario_id'         => (int) $linha['emp_usu_id'],
                'nome'               => (string) ($linha['emp_nome_fantasia'] ?: $linha['emp_razao_social']),
                'registro_crea'      => (string) $linha['emp_registro_crea'],
                // A API não devolve situação de empresa: não existe equivalente ao prf_status_api.
                // O portão da empresa é a existência da linha, conferida em VisibilidadeService.
                'registro_ativo'     => true,
                'resumo'             => $linha['emp_resumo'],
                'tipo_contrato'      => null,
                'disponibilidade'    => null,
                'em_construcao'      => false,
                'total_experiencias' => 0,
                'experiencias_com_art' => 0,
            ];
        }

        return $saida;
    }

    /**
     * Modalidades declaradas, por profissional. Empresa não tem modalidade própria: quem tem
     * atribuição é o profissional do quadro técnico.
     *
     * @param  list<int> $profissionalIds
     * @return array<int, list<string>>
     */
    public function modalidadesDe(array $profissionalIds): array
    {
        if ($profissionalIds === []) {
            return [];
        }

        [$marcadores, $params] = $this->marcadores($profissionalIds);

        $stmt = $this->pdo->prepare(
            'SELECT pm.pmo_prf_id, m.mod_nome
               FROM pro_prof_modalidades pm
               JOIN crea_modalidades m ON m.mod_id = pm.pmo_mod_id
              WHERE pm.pmo_prf_id IN (' . implode(', ', $marcadores) . ')
                AND pm.pmo_status = :ativo
              ORDER BY m.mod_nome'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, \PDO::PARAM_INT);
        }

        $stmt->execute();

        $saida = [];

        foreach ($stmt->fetchAll() as $linha) {
            $saida[(int) $linha['pmo_prf_id']][] = (string) $linha['mod_nome'];
        }

        return $saida;
    }

    /**
     * Placeholders numerados: `ATTR_EMULATE_PREPARES` está desligado e nome repetido na mesma
     * query não funciona.
     *
     * @param  list<int> $ids
     * @return array{0: list<string>, 1: array<string, int>}
     */
    private function marcadores(array $ids): array
    {
        $marcadores = [];
        $params     = [];

        foreach (array_values(array_unique($ids)) as $i => $id) {
            $marcadores[]     = ":i{$i}";
            $params[":i{$i}"] = $id;
        }

        return [$marcadores, $params];
    }
}
