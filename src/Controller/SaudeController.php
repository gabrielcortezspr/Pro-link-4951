<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Support\Database;
use Throwable;

/**
 * Health check (proposta, diferencial 4 — disponibilidade). Serve para a banca confirmar num
 * pedido só que o container subiu, o banco respondeu e a carga inicial entrou.
 */
final class SaudeController
{
    public function index(): string
    {
        header('Content-Type: application/json; charset=utf-8');

        $banco = 'indisponivel';
        $tos   = 0;

        try {
            $pdo = Database::conexao();
            $tos = (int) $pdo->query('SELECT COUNT(*) FROM crea_tos')->fetchColumn();
            $banco = 'ok';
        } catch (Throwable) {
            http_response_code(503);
        }

        return json_encode([
            'aplicacao'    => 'Pro-Link',
            'ambiente'     => APP_ENV,
            'banco'        => $banco,
            'tos_carregada' => $tos,
            'momento'      => date('c'),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
