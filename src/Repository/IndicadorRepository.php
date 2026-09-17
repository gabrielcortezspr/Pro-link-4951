<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Os números da visão geral do painel administrativo (RF06; Anexo I, item 3, "emitir relatórios").
 *
 * Contagem, e só contagem: nada aqui identifica pessoa, e nada aqui ordena gente. O item 10.1 do
 * edital veda ranking de profissionais, e um painel de indicadores é justamente onde a tentação
 * apareceria — "os cinco mais compatíveis", "quem mais recebeu manifestação". Não existe método
 * para isso nesta classe, de propósito.
 *
 * Toda leitura filtra por `_status = 'A'`: exclusão é lógica (item 8.6j) e o que está na lixeira
 * não pode contar como se estivesse em operação. A exceção é `contasExcluidas()`, que existe
 * exatamente para medir a lixeira.
 */
final class IndicadorRepository extends Repositorio
{
    /**
     * Tudo que a visão geral mostra, numa estrutura só.
     *
     * Devolve um mapa fechado em vez de números soltos porque a tela precisa saber o que é
     * contagem principal e o que é quebra por categoria — e porque indicador que chega sem
     * rótulo vira número órfão na interface.
     *
     * @return array{
     *     contas: array{total: int, por_perfil: array<string, int>, excluidas: int},
     *     demandas: array{total: int, publicadas: int, rascunho: int, por_situacao: array<string, int>},
     *     manifestacoes: array{total: int, por_situacao: array<string, int>},
     *     denuncias: array{total: int, por_situacao: array<string, int>},
     *     evidencia: array{arts: int, profissionais_com_acervo: int, em_construcao: int},
     *     motor: array{sessoes: int, sessoes_7_dias: int},
     *     limiar_em_construcao: int
     * }
     */
    public function resumo(): array
    {
        $contas = $this->contasPorPerfil();

        return [
            'contas' => [
                'total'      => array_sum($contas),
                'por_perfil' => $contas,
                'excluidas'  => $this->contasExcluidas(),
            ],
            'demandas' => $this->demandas(),
            'manifestacoes' => [
                'total'        => $this->total('pro_manifestacoes', 'man_status'),
                'por_situacao' => $this->porSituacao('pro_manifestacoes', 'man_situacao', 'man_status'),
            ],
            'denuncias' => [
                'total'        => $this->total('pro_denuncias', 'den_status'),
                'por_situacao' => $this->porSituacao('pro_denuncias', 'den_situacao', 'den_status'),
            ],
            'evidencia' => $this->evidencia(),
            'motor'     => $this->motor(),
            // O limiar que define "perfil em construção" vem de `sis_parametros` e é editável
            // pelo administrador. Sem ele a tela teria de escrever o número à mão, e ele
            // mudaria sem que a frase mudasse junto.
            'limiar_em_construcao' => (new ParametroRepository($this->pdo))
                ->inteiro('match.early_career.min_arts', 3),
        ];
    }

    /**
     * Demandas, separando o que está publicado do que ainda é rascunho.
     *
     * `dem_situacao` vale `ABERTA` nos dois casos, e essa é a armadilha: a tela que dissesse "21
     * abertas" contradiria toda outra da aplicação, que chama rascunho de rascunho. Quem separa é
     * `dem_dt_publicacao`, nula enquanto ninguém publicou.
     *
     * @return array{total: int, publicadas: int, rascunho: int, por_situacao: array<string, int>}
     */
    private function demandas(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN dem_dt_publicacao IS NOT NULL THEN 1 ELSE 0 END) AS publicadas
               FROM pro_demandas
              WHERE dem_status = :ativo'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();
        $linha = $stmt->fetch() ?: [];

        $total      = (int) ($linha['total'] ?? 0);
        $publicadas = (int) ($linha['publicadas'] ?? 0);

        return [
            'total'        => $total,
            'publicadas'   => $publicadas,
            'rascunho'     => $total - $publicadas,
            'por_situacao' => $this->porSituacao('pro_demandas', 'dem_situacao', 'dem_status'),
        ];
    }

    /**
     * Contas ativas por perfil, com o código do perfil como chave (`PROFISSIONAL`, `EMPRESA`...).
     *
     * O código é forma interna e não vai para a tela sem passar por `Support\Rotulos`.
     *
     * @return array<string, int>
     */
    private function contasPorPerfil(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.per_codigo, COUNT(*) AS total
               FROM sis_usuarios u
               JOIN sis_perfis p ON p.per_id = u.usu_per_id
              WHERE u.usu_status = :ativo
              GROUP BY p.per_codigo'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        $saida = [];

        foreach ($stmt->fetchAll() as $linha) {
            $saida[(string) $linha['per_codigo']] = (int) $linha['total'];
        }

        return $saida;
    }

    /**
     * Contas em exclusão lógica. É o tamanho da lixeira do item 8.6j, e o único indicador que
     * conta deliberadamente o que está em `'X'`.
     */
    private function contasExcluidas(): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM sis_usuarios WHERE usu_status = :excluido'
        );

        $stmt->bindValue(':excluido', STATUS_EXCLUIDO);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * O que a plataforma tem de evidência documental, que é a tese do projeto.
     *
     * `profissionais_com_acervo` conta quem tem pelo menos uma ART, e `em_construcao` conta quem
     * está abaixo do limiar de `match.early_career.min_arts`. Os dois juntos dizem quanto do
     * cadastro sustenta compatibilização por evidência, e o segundo é a medida do viés de início
     * de carreira que a declaração do item 12.3 assume.
     *
     * @return array{arts: int, profissionais_com_acervo: int, em_construcao: int}
     */
    private function evidencia(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS arts, COUNT(DISTINCT art_pro_rnp) AS titulares
               FROM crea_arts
              WHERE art_status = :ativo'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();
        $acervo = $stmt->fetch() ?: ['arts' => 0, 'titulares' => 0];

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM pro_profissionais
              WHERE prf_status = :ativo
                AND prf_em_construcao = 1'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return [
            'arts'                     => (int) $acervo['arts'],
            'profissionais_com_acervo' => (int) $acervo['titulares'],
            'em_construcao'            => (int) $stmt->fetchColumn(),
        ];
    }

    /**
     * Execuções do motor de compatibilização, total e na última semana.
     *
     * A janela de sete dias usa `NOW()` do banco, que o `Database::conexao()` alinha ao fuso do
     * PHP (D40). Comparar coluna gravada por um caminho com relógio de outro já rendeu defeito
     * uma vez.
     *
     * @return array{sessoes: int, sessoes_7_dias: int}
     */
    private function motor(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN mts_dt_registro >= NOW() - INTERVAL 7 DAY THEN 1 ELSE 0 END) AS recentes
               FROM mat_sessoes
              WHERE mts_status = :ativo'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();
        $linha = $stmt->fetch() ?: [];

        return [
            'sessoes'        => (int) ($linha['total'] ?? 0),
            'sessoes_7_dias' => (int) ($linha['recentes'] ?? 0),
        ];
    }

    /**
     * Contagem de linhas ativas de uma tabela.
     *
     * Tabela e coluna vêm de literais escritos aqui dentro, nunca de entrada externa: placeholder
     * não liga identificador no MariaDB, e concatenar o que veio de fora seria injeção. A lista
     * fechada em `exigirConhecida()` existe para que isso não se perca numa edição futura.
     */
    private function total(string $tabela, string $colunaStatus): int
    {
        $this->exigirConhecida($tabela, $colunaStatus);

        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$tabela} WHERE {$colunaStatus} = :ativo"
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Quebra por situação, com a situação crua como chave.
     *
     * @return array<string, int>
     */
    private function porSituacao(string $tabela, string $colunaSituacao, string $colunaStatus): array
    {
        $this->exigirConhecida($tabela, $colunaStatus, $colunaSituacao);

        $stmt = $this->pdo->prepare(
            "SELECT {$colunaSituacao} AS situacao, COUNT(*) AS total
               FROM {$tabela}
              WHERE {$colunaStatus} = :ativo
              GROUP BY {$colunaSituacao}"
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        $saida = [];

        foreach ($stmt->fetchAll() as $linha) {
            $saida[(string) $linha['situacao']] = (int) $linha['total'];
        }

        return $saida;
    }

    /**
     * Recusa qualquer identificador que não esteja na lista fechada.
     *
     * Hoje nenhum destes valores vem de fora, e é por isso que a guarda é barata: ela custa nada
     * agora e impede que a primeira edição que passe uma variável para cá vire injeção de SQL.
     */
    private function exigirConhecida(string ...$identificadores): void
    {
        $conhecidos = [
            'pro_demandas', 'dem_situacao', 'dem_status',
            'pro_manifestacoes', 'man_situacao', 'man_status',
            'pro_denuncias', 'den_situacao', 'den_status',
        ];

        foreach ($identificadores as $identificador) {
            if (!in_array($identificador, $conhecidos, true)) {
                throw new \InvalidArgumentException(
                    'Identificador fora da lista fechada de indicadores: ' . $identificador
                );
            }
        }
    }
}
