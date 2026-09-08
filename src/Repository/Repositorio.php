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
}
