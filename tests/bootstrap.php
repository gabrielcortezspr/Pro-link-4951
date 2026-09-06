<?php

declare(strict_types=1);

/**
 * Bootstrap dos testes. Fixa o ambiente ANTES de carregar _config.php: o Dotenv é imutável,
 * então o que está definido aqui vence o .env local. Testes não dependem de segredo real
 * nem de banco — o que precisa de banco é teste de integração, e roda no container.
 */

$_ENV['APP_ENV']   = 'test';
$_ENV['APP_DEBUG'] = 'false';
$_ENV['APP_KEY']   = base64_encode(str_repeat("\x01", 32));   // 32 bytes determinísticos

foreach (['APP_ENV', 'APP_DEBUG', 'APP_KEY'] as $chave) {
    putenv($chave . '=' . $_ENV[$chave]);
}

require_once dirname(__DIR__) . '/_config.php';
