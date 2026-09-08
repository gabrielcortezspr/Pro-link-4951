<?php

declare(strict_types=1);

/**
 * Front controller. Toda requisição entra por aqui; o document root do nginx é public/,
 * então nem _config.php, nem src/, nem .env são alcançáveis pelo navegador.
 *
 * Ordem: sessão → rota → autorização por perfil → respaldo da sessão no servidor →
 * CSRF em escrita → controller.
 *
 * A autorização acontece por requisição, não só no login (OWASP A01; decisão D06).
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Controller\AdminController;
use ProLink\Controller\AuthController;
use ProLink\Controller\HomeController;
use ProLink\Controller\PerfilController;
use ProLink\Controller\PrivacidadeController;
use ProLink\Controller\SaudeController;
use ProLink\Controller\TermoController;
use ProLink\Service\AutenticacaoService;
use ProLink\Support\Auditoria;
use ProLink\Support\Csrf;
use ProLink\Support\Flash;
use ProLink\Support\Requisicao;
use ProLink\Support\Router;
use ProLink\Support\Sessao;
use ProLink\Support\View;

Sessao::iniciar();

$router = new Router();

// ---------------------------------------------------------------- rotas públicas
$router->get('/',                     HomeController::class,  'index');
$router->get('/saude',                SaudeController::class, 'index');
$router->get('/termos/uso',           TermoController::class, 'uso');
$router->get('/termos/privacidade',   TermoController::class, 'privacidade');

// ---------------------------------------------------------------- identidade (RF01)
$router->get('/cadastro',             AuthController::class, 'formularioCadastro');
$router->post('/cadastro',            AuthController::class, 'cadastrar');
$router->get('/login',                AuthController::class, 'formularioLogin');
$router->post('/login',               AuthController::class, 'entrar');
$router->get('/recuperar-senha',      AuthController::class, 'formularioRecuperacao');
$router->post('/recuperar-senha',     AuthController::class, 'solicitarRecuperacao');
$router->get('/redefinir-senha/{token}',  AuthController::class, 'formularioRedefinicao');
$router->post('/redefinir-senha/{token}', AuthController::class, 'redefinir');

// Logout é POST: por link seria escrita de estado sem proteção de CSRF (edital 8.5e).
$router->post('/sair',                AuthController::class, 'sair', PERFIS_AUTENTICADOS);

// ---------------------------------------------------------------- privacidade do titular (11.3)
$router->get('/perfil',                       PerfilController::class, 'index', PERFIS_AUTENTICADOS);
$router->post('/perfil/visibilidade',        PerfilController::class, 'definirVisibilidade', PERFIS_AUTENTICADOS);
$router->post('/perfil/validar-registro',    PerfilController::class, 'validarRegistro', PERFIL_PROFISSIONAL);

$router->get('/privacidade',                  PrivacidadeController::class, 'index', PERFIS_AUTENTICADOS);
$router->post('/privacidade/consentimento',   PrivacidadeController::class, 'definirConsentimento', PERFIS_AUTENTICADOS);
$router->get('/privacidade/exportar',         PrivacidadeController::class, 'exportar', PERFIS_AUTENTICADOS);
$router->post('/privacidade/excluir',         PrivacidadeController::class, 'excluir', PERFIS_AUTENTICADOS);

// ---------------------------------------------------------------- administração (RF06)
$router->get('/admin',                AdminController::class, 'index', PERFIL_ADMIN);

// Próximas, na ordem do backlog: /perfil, /demandas — ver docs/backlog.md

// ---------------------------------------------------------------- despacho
$rota = $router->resolver(Requisicao::metodo(), Requisicao::caminho());

if ($rota === null) {
    echo View::erro(404, 'Página não encontrada.');
    exit;
}

try {
    if (!Router::ehPublica($rota['perfis'])) {
        if (!Sessao::autenticado()) {
            echo View::erro(401, 'Entre com sua conta para acessar esta página.');
            exit;
        }

        // A sessão do navegador ainda tem linha válida em sis_sessoes? É o que faz a troca de
        // senha e o bloqueio administrativo (E6) derrubarem quem já estava logado.
        if (!(new AutenticacaoService())->sessaoTemRespaldo()) {
            Sessao::encerrar();
            session_start();
            Flash::aviso('Sua sessão foi encerrada. Entre novamente.');
            View::redirecionar('/login');
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

    $controller = new $rota['controller']();
    echo $controller->{$rota['metodo']}(...$rota['params']);
} catch (Throwable $e) {
    error_log(sprintf('[%s] %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    echo View::erro(500, APP_DEBUG ? $e->getMessage() : 'Erro interno. A equipe foi notificada.');
}
