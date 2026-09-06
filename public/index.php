<?php

declare(strict_types=1);

/**
 * Front controller. Toda requisição entra por aqui; o document root do nginx é public/,
 * então nem _config.php, nem src/, nem .env são alcançáveis pelo navegador.
 *
 * Ordem: sessão → rota → autorização por perfil → CSRF em escrita → controller.
 * A autorização acontece por requisição, não só no login (OWASP A01).
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Controller\AdminController;
use ProLink\Controller\HomeController;
use ProLink\Controller\SaudeController;
use ProLink\Support\Auditoria;
use ProLink\Support\Csrf;
use ProLink\Support\Requisicao;
use ProLink\Support\Router;
use ProLink\Support\Sessao;
use ProLink\Support\View;

Sessao::iniciar();

$router = new Router();

// ---------------------------------------------------------------- rotas
$router->get('/',       HomeController::class,  'index');
$router->get('/saude',  SaudeController::class, 'index');
$router->get('/admin',  AdminController::class, 'index', PERFIL_ADMIN);

// Próximas, na ordem do backlog: /cadastro, /login, /perfil, /demandas — ver docs/backlog.md

// ---------------------------------------------------------------- despacho
$rota = $router->resolver(Requisicao::metodo(), Requisicao::caminho());

if ($rota === null) {
    echo View::erro(404, 'Página não encontrada.');
    exit;
}

// Autorização por perfil, em toda requisição
if (!Router::ehPublica($rota['perfis'])) {
    if (!Sessao::autenticado()) {
        echo View::erro(401, 'Entre com sua conta para acessar esta página.');
        exit;
    }

    if (!Sessao::temPerfil(...$rota['perfis'])) {
        Auditoria::registrar(Auditoria::ACESSO_NEGADO, 'rota', null, null, null, Requisicao::caminho());
        echo View::erro(403, 'Seu perfil não tem acesso a esta página.');
        exit;
    }
}

// CSRF em toda escrita (edital 8.5e)
if (Requisicao::ehPost() && !Csrf::valido($_POST['_csrf'] ?? null)) {
    echo View::erro(419, 'Formulário expirado. Recarregue a página e tente de novo.');
    exit;
}

try {
    $controller = new $rota['controller']();
    echo $controller->{$rota['metodo']}(...$rota['params']);
} catch (Throwable $e) {
    error_log(sprintf('[%s] %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    echo View::erro(500, APP_DEBUG ? $e->getMessage() : 'Erro interno. A equipe foi notificada.');
}
