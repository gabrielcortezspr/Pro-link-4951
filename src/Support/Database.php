<?php

declare(strict_types=1);

namespace ProLink\Support;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Conexão PDO única. Toda query da aplicação passa por aqui, sempre com prepared statement
 * (edital 8.5c; OWASP A03). Nenhum repositório monta SQL por concatenação de entrada.
 */
final class Database
{
    private static ?PDO $pdo = null;

    private function __construct()
    {
    }

    public static function conexao(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        try {
            self::$pdo = new PDO(DB_DSN, DB_USERNAME, DB_PASSWORD, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (PDOException $e) {
            // Nunca vazar credencial ou DSN para a resposta (OWASP A05)
            error_log('Falha na conexão com o banco: ' . $e->getMessage());
            throw new RuntimeException('Banco de dados indisponível.', 0, $e);
        }

        return self::$pdo;
    }

    /** Executa dentro de transação. Usado nas cinco operações atômicas da proposta. */
    public static function transacao(callable $operacao): mixed
    {
        $pdo = self::conexao();
        $pdo->beginTransaction();

        try {
            $resultado = $operacao($pdo);
            $pdo->commit();

            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }
}
