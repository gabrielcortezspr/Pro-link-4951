<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\SaudeRepository;
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
            // O repositório é construído aqui, e não injetado no construtor, porque construí-lo
            // já abre conexão — e esta rota precisa responder 503 com corpo legível justamente
            // quando o banco está fora.
            $tos   = (new SaudeRepository())->totalDeCodigosTos();
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
