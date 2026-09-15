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
            // Um relógio só para a aplicação inteira.
            //
            // O contêiner do MariaDB roda em UTC e o do PHP em America/Manaus. Sem esta linha,
            // NOW() e date() gravam horas diferentes NA MESMA COLUNA, conforme o caminho: a
            // sessão nasce com DATE_ADD(NOW(), ...) e o bloqueio de login com date(), e os dois
            // convivem em sis_usuarios. Comparar uma coluna dessas com a outra dá quatro horas
            // de erro, e a trilha de auditoria mostrava o futuro.
            //
            // O deslocamento sai do próprio fuso do PHP em vez de ser escrito à mão, para os
            // dois nunca divergirem de novo. Nome de fuso ('America/Manaus') exigiria as tabelas
            // de fuso carregadas no MariaDB, que a imagem não traz.
            self::$pdo->exec("SET time_zone = '" . (new \DateTimeImmutable())->format('P') . "'");
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
