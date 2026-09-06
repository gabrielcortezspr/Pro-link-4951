<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Leitura segura da requisição atual. Existe para que IP e user agent sejam obtidos de um
 * lugar só, com o mesmo critério, tanto na auditoria quanto no controle de força bruta.
 */
final class Requisicao
{
    public static function ip(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'cli';
        }

        // Atrás do nginx do docker-compose o REMOTE_ADDR já é o do cliente. Não confiamos em
        // X-Forwarded-For por padrão: qualquer um pode enviar o header. Se um dia houver
        // proxy na frente, a lista de proxies confiáveis entra aqui, não no chamador.
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'desconhecido';
    }

    public static function userAgent(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return null;
        }

        $agente = $_SERVER['HTTP_USER_AGENT'] ?? null;

        return is_string($agente) ? mb_substr($agente, 0, 255) : null;
    }

    public static function metodo(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public static function caminho(): string
    {
        return $_SERVER['REQUEST_URI'] ?? '/';
    }

    public static function ehPost(): bool
    {
        return self::metodo() === 'POST';
    }
}
