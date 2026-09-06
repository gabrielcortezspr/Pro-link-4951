<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Token CSRF por sessão, exigido em toda requisição de escrita (edital 8.5e; OWASP A03).
 * O middleware do front controller valida antes de qualquer POST chegar ao controller.
 */
final class Csrf
{
    private const CHAVE = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::CHAVE])) {
            $_SESSION[self::CHAVE] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CHAVE];
    }

    public static function valido(?string $enviado): bool
    {
        $esperado = $_SESSION[self::CHAVE] ?? '';

        return $esperado !== ''
            && is_string($enviado)
            && hash_equals($esperado, $enviado);
    }
}
