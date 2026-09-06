<?php

declare(strict_types=1);

/**
 * Front controller. Toda requisição entra por aqui; o document root do nginx é public/,
 * então nem _config.php, nem src/, nem .env são alcançáveis pelo navegador.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Controller\HomeController;
use ProLink\Controller\SaudeController;
use ProLink\Support\Csrf;
use ProLink\Support\Router;

session_set_cookie_params([
    'lifetime' => SESSION_LIFETIME_MINUTES * 60,
    'path'     => '/',
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => APP_ENV !== 'dev',
]);
session_start();

$router = new Router();

// ---------------------------------------------------------------- rotas
$router->get('/',       HomeController::class, 'index');
$router->get('/saude',  SaudeController::class, 'index');

// Próximas: /cadastro, /login, /perfil, /demandas, /admin — ver _arq/arquitetura.md

// ---------------------------------------------------------------- despacho
$verbo   = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$caminho = $_SERVER['REQUEST_URI'] ?? '/';

$rota = $router->resolver($verbo, $caminho);

if ($rota === null) {
    http_response_code(404);
    echo ProLink\Support\View::render('erro.html.twig', ['codigo' => 404, 'mensagem' => 'Página não encontrada.']);
    exit;
}

// CSRF em toda escrita (edital 8.5e)
if ($verbo === 'POST' && !Csrf::valido($_POST['_csrf'] ?? null)) {
    http_response_code(419);
    echo ProLink\Support\View::render('erro.html.twig', ['codigo' => 419, 'mensagem' => 'Sessão expirada. Recarregue a página.']);
    exit;
}

try {
    $controller = new $rota['controller']();
    echo $controller->{$rota['metodo']}(...$rota['params']);
} catch (Throwable $e) {
    error_log(sprintf('[%s] %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    http_response_code(500);
    echo ProLink\Support\View::render('erro.html.twig', [
        'codigo'   => 500,
        'mensagem' => APP_DEBUG ? $e->getMessage() : 'Erro interno. A equipe foi notificada.',
    ]);
}
