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
use ProLink\Controller\DemandaController;
use ProLink\Controller\DenunciaController;
use ProLink\Controller\HomeController;
use ProLink\Controller\PerfilController;
use ProLink\Controller\PrivacidadeController;
use ProLink\Controller\SaudeController;
use ProLink\Controller\TermoController;
use ProLink\Repository\SessaoRepository;
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
// Um caminho só para os dois perfis que têm registro no conselho: o controller despacha pelo
// perfil da sessão. Terceiro não entra — não há registro no CREA para validar.
$router->post('/perfil/validar-registro',    PerfilController::class, 'validarRegistro', PERFIS_COM_REGISTRO_CREA);

// Preferências declaradas (RF03; dimensões 5 e 6 do item 3.2). Só o profissional: a empresa não
// declara regime de contratação nem abrangência — quem tem essas escolhas é a pessoa.
$router->post('/perfil/preferencias',        PerfilController::class, 'salvarPreferencias', PERFIL_PROFISSIONAL);

// Experiência autodeclarada (RF03). Só o profissional tem: `exp_prf_id` referencia
// pro_profissionais, porque quem tem trajetória é a pessoa — a empresa tem quadro técnico.
$router->post('/perfil/experiencias',                 PerfilController::class, 'criarExperiencia', PERFIL_PROFISSIONAL);
$router->post('/perfil/experiencias/{id}',            PerfilController::class, 'editarExperiencia', PERFIL_PROFISSIONAL);
$router->post('/perfil/experiencias/{id}/excluir',    PerfilController::class, 'excluirExperiencia', PERFIL_PROFISSIONAL);

$router->get('/privacidade',                  PrivacidadeController::class, 'index', PERFIS_AUTENTICADOS);
$router->post('/privacidade/consentimento',   PrivacidadeController::class, 'definirConsentimento', PERFIS_AUTENTICADOS);
$router->get('/privacidade/exportar',         PrivacidadeController::class, 'exportar', PERFIS_AUTENTICADOS);
$router->post('/privacidade/excluir',         PrivacidadeController::class, 'excluir', PERFIS_AUTENTICADOS);

// ---------------------------------------------------------------- demandas (RF04)
// A vitrine é de qualquer usuário autenticado — o profissional precisa ver o que existe para
// manifestar interesse. Publicar é de empresa e terceiro (Anexo I, item 3).
$router->get('/demandas/abertas',        DemandaController::class, 'abertas', PERFIS_AUTENTICADOS);
$router->get('/demandas',                DemandaController::class, 'index', PERFIS_DEMANDANTES);
$router->get('/demandas/nova',           DemandaController::class, 'formulario', PERFIS_DEMANDANTES);
$router->post('/demandas',               DemandaController::class, 'criar', PERFIS_DEMANDANTES);
$router->get('/demandas/{id}',           DemandaController::class, 'ver', PERFIS_AUTENTICADOS);
$router->post('/demandas/{id}',          DemandaController::class, 'editar', PERFIS_DEMANDANTES);
$router->post('/demandas/{id}/tos',      DemandaController::class, 'alterarTos', PERFIS_DEMANDANTES);
$router->post('/demandas/{id}/publicar', DemandaController::class, 'publicar', PERFIS_DEMANDANTES);
$router->post('/demandas/{id}/encerrar', DemandaController::class, 'encerrar', PERFIS_DEMANDANTES);

// Compatibilização (RF04; operação atômica 2). Executar é POST porque grava uma sessão auditável
// em mat_sessoes; ver o resultado é GET num endereço estável, que recarregar não reexecuta.
$router->post('/demandas/{id}/compatibilizar', DemandaController::class, 'compatibilizar', PERFIS_DEMANDANTES);
$router->get('/demandas/{id}/candidatos/{sessao}', DemandaController::class, 'candidatos', PERFIS_DEMANDANTES);

// ---------------------------------------------------------------- denúncias (RF06)
// Qualquer conta autenticada denuncia, inclusive Terceiro. Anônimo recebe 401.
$router->get('/denuncias/nova', DenunciaController::class, 'formulario', PERFIS_AUTENTICADOS);
$router->post('/denuncias',     DenunciaController::class, 'registrar',  PERFIS_AUTENTICADOS);

// ---------------------------------------------------------------- administração (RF06)
$router->get('/admin',                AdminController::class, 'index', PERFIL_ADMIN);
$router->get('/admin/denuncias',      AdminController::class, 'denuncias', PERFIL_ADMIN);
$router->get('/admin/auditoria',      AdminController::class, 'auditoria', PERFIL_ADMIN);
// Depois da rota sem parâmetro: o Router percorre na ordem de registro, e {id} casaria antes.
$router->get('/admin/denuncias/{id}',  AdminController::class, 'denuncia', PERFIL_ADMIN);
$router->post('/admin/denuncias/{id}', AdminController::class, 'tratar',   PERFIL_ADMIN);

// Próximas, na ordem do backlog: /perfil, /demandas — ver docs/backlog.md

// ---------------------------------------------------------------- despacho
$rota = $router->resolver(Requisicao::metodo(), Requisicao::caminho());

if ($rota === null) {
    echo View::erro(404, 'Página não encontrada.');
    exit;
}

try {
    // A sessão do navegador ainda tem linha válida em sis_sessoes? É o que faz a troca de senha e
    // o bloqueio administrativo (E6) derrubarem quem já estava logado.
    //
    // Conferido em **toda** requisição autenticada, inclusive nas rotas públicas. Antes isto só
    // rodava nas rotas protegidas, e o efeito era que o bloqueado seguia navegando pela home e
    // pelos termos com o cabeçalho dizendo o nome dele: a conta estava derrubada e a tela dizia
    // que não. Derrubar a sessão é o ato; a rota só decide se depois disso há para onde mandar.
    //
    // Consulta o repositório direto, e não o AutenticacaoService: o construtor do serviço monta
    // sete objetos, o serviço de notificação incluído, e o roteador não tem o que fazer com
    // nenhum deles para responder uma pergunta de uma linha.
    if (Sessao::autenticado()) {
        $token = Sessao::tokenServidor();

        if ($token === null || (new SessaoRepository())->ativa($token) === null) {
            Sessao::reiniciar();
            Flash::aviso('Sua sessão foi encerrada. Entre novamente.');

            // Em rota pública segue como visitante anônimo, e não redireciona: a home e os termos
            // são visíveis a quem nunca entrou, e mandar /saude para /login quebraria o
            // monitoramento por um motivo que não é dele.
            if (!Router::ehPublica($rota['perfis'])) {
                View::redirecionar('/login');
            }
        }
    }

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

    $controller = new $rota['controller']();
    echo $controller->{$rota['metodo']}(...$rota['params']);
} catch (Throwable $e) {
    error_log(sprintf('[%s] %s em %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()));

    echo View::erro(500, APP_DEBUG ? $e->getMessage() : 'Erro interno. A equipe foi notificada.');
}
