<?php

declare(strict_types=1);

namespace ProLink\Repository;

use PDO;
use ProLink\Support\Database;

/**
 * Base dos repositórios. Só resolve a conexão: o PDO pode ser injetado para que uma operação
 * atômica inteira (proposta, cinco operações) rode na mesma transação de Database::transacao().
 *
 * Regra do projeto, e do item 8.5c do edital: SQL mora aqui e em nenhum outro lugar. Controller
 * e template não conhecem nome de tabela, e toda query é prepared statement.
 */
abstract class Repositorio
{
    protected PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::conexao();
    }

    /**
     * Placeholders numerados para um `IN (...)` de tamanho variável.
     *
     * Subiu para a base quando o quarto repositório precisou dela. Numerados, e não `?`, porque
     * `ATTR_EMULATE_PREPARES` está desligado e nome repetido na mesma query não funciona — a
     * armadilha que o `CLAUDE.md` registra.
     *
     * Devolve listas vazias para entrada vazia: quem chama **tem** de tratar esse caso antes de
     * montar a query, porque `IN ()` é erro de sintaxe no MariaDB.
     *
     * @param  list<int> $ids
     * @return array{0: list<string>, 1: array<string, int>}
     */
    protected function marcadores(array $ids): array
    {
        $marcadores = [];
        $params     = [];

        foreach (array_values(array_unique($ids)) as $i => $id) {
            $marcadores[]     = ":i{$i}";
            $params[":i{$i}"] = $id;
        }

        return [$marcadores, $params];
    }

    /**
     * Executa um `SELECT ... IN (...)` com os placeholders de `marcadores()` já ligados como
     * inteiro, e devolve as linhas.
     *
     * O laço de `bindValue` era o mesmo em todo lugar, e errar o `PDO::PARAM_INT` num deles
     * passaria despercebido até alguém comparar id com string no banco.
     *
     * @param  callable(list<string>): string $sql recebe os marcadores e devolve a query
     * @param  list<int>                      $ids
     * @param  array<string, mixed>           $extras parâmetros nomeados fora do IN
     * @return list<array<string, mixed>>
     */
    protected function buscarPorIds(callable $sql, array $ids, array $extras = []): array
    {
        if ($ids === []) {
            return [];
        }

        [$marcadores, $params] = $this->marcadores($ids);

        $stmt = $this->pdo->prepare($sql($marcadores));

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, PDO::PARAM_INT);
        }

        foreach ($extras as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }
}
