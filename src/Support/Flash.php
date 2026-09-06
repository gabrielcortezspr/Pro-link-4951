<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Mensagens de uma requisição para a seguinte (padrão POST → redirect → GET).
 * Guardadas na sessão, consumidas uma vez pelo layout.
 */
final class Flash
{
    private const CHAVE = '_flash';

    public static function sucesso(string $mensagem): void
    {
        self::adicionar('success', $mensagem);
    }

    public static function erro(string $mensagem): void
    {
        self::adicionar('danger', $mensagem);
    }

    public static function aviso(string $mensagem): void
    {
        self::adicionar('warning', $mensagem);
    }

    public static function info(string $mensagem): void
    {
        self::adicionar('info', $mensagem);
    }

    /**
     * Devolve e apaga as mensagens pendentes.
     *
     * @return list<array{tipo: string, texto: string}>
     */
    public static function consumir(): array
    {
        $mensagens = $_SESSION[self::CHAVE] ?? [];
        unset($_SESSION[self::CHAVE]);

        return $mensagens;
    }

    private static function adicionar(string $tipo, string $texto): void
    {
        $_SESSION[self::CHAVE][] = ['tipo' => $tipo, 'texto' => $texto];
    }
}
