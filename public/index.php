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
use ProLink\Controller\BuscaController;
use ProLink\Controller\CompativelController;
use ProLink\Controller\DemandaController;
use ProLink\Controller\DenunciaController;
use ProLink\Controller\HomeController;
use ProLink\Controller\ManifestacaoController;
use ProLink\Controller\PerfilController;
use ProLink\Controller\PrivacidadeController;
use ProLink\Controller\SaudeController;
use ProLink\Controller\TermoController;
use ProLink\Repository\SessaoRepository;
use ProLink\Service\NotificacaoService;
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
// ---------------------------------------------------------------- busca ativa (RF04)
// Aberta a quem não tem conta: o edital dá "pesquisar profissionais" e "ver perfil" ao perfil
// Público (Anexo I, item 3). O anônimo não deixa de ver a tela, ele vê menos dela — quem decide
// campo a campo é a Visao, pelo alcance de Visibilidade::alcanceDe().
$router->get('/profissionais', BuscaController::class, 'profissionais', PERFIL_PUBLICO);

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
$router->post('/perfil/experiencias',                 PerfilController::class, 'criarExperiencia', PERFIS_COM_REGISTRO_CREA);
$router->post('/perfil/experiencias/{id}',            PerfilController::class, 'editarExperiencia', PERFIS_COM_REGISTRO_CREA);
$router->post('/perfil/experiencias/{id}/excluir',    PerfilController::class, 'excluirExperiencia', PERFIS_COM_REGISTRO_CREA);

// Perfil de outra pessoa. Depois das rotas literais acima, porque {id} casaria 'visibilidade'
// antes delas. PerfilService::montar() já recebe o espectador e filtra pela Visao: quem não é o
// dono vê só o que o dono abriu.
$router->get('/perfil/{id}', PerfilController::class, 'publico', PERFIL_PUBLICO);

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

// O feed é do dono da demanda, logo dos perfis que publicam. GET relê a última sessão gravada;
// POST manda calcular outra — ver CompativelController.
$router->get('/demandas/{id}/compativeis',  CompativelController::class, 'index',     PERFIS_DEMANDANTES);
$router->post('/demandas/{id}/compativeis', CompativelController::class, 'atualizar', PERFIS_DEMANDANTES);
// O caminho inverso do Anexo I item 3: o demandante registra interesse num candidato.
$router->post('/demandas/{id}/interesse',  CompativelController::class, 'registrarInteresse', PERFIS_DEMANDANTES);

// ---------------------------------------------------------------- manifestação (RF05)
// Quem manifesta é o candidato, e candidato sai de crea_evidencias, que deriva de ART: Terceiro
// não tem acervo e não é candidato de ninguém. Ver D51 sobre o sentido do fluxo.
$router->get('/demandas/{id}/manifestar',   ManifestacaoController::class, 'confirmar', PERFIS_COM_REGISTRO_CREA);
$router->post('/demandas/{id}/manifestar',  ManifestacaoController::class, 'enviar',    PERFIS_COM_REGISTRO_CREA);

// A lista de interessados é do dono da demanda; a manifestação em si é das duas partes, e quem
// decide se a pessoa é parte é o serviço.
$router->get('/demandas/{id}/interessados', ManifestacaoController::class, 'interessados', PERFIS_DEMANDANTES);

// Sem parâmetro antes da com parâmetro: o Router percorre na ordem de registro.
$router->get('/manifestacoes',                   ManifestacaoController::class, 'minhas',    PERFIS_COM_REGISTRO_CREA);
$router->get('/manifestacoes/{id}',              ManifestacaoController::class, 'ver',       PERFIS_AUTENTICADOS);
$router->post('/manifestacoes/{id}/mensagens',   ManifestacaoController::class, 'responder', PERFIS_AUTENTICADOS);

// ---------------------------------------------------------------- denúncias (RF06)
// Qualquer conta autenticada denuncia, inclusive Terceiro. Anônimo recebe 401.
$router->get('/denuncias/nova', DenunciaController::class, 'formulario', PERFIS_AUTENTICADOS);
$router->post('/denuncias',     DenunciaController::class, 'registrar',  PERFIS_AUTENTICADOS);

// ---------------------------------------------------------------- administração (RF06)
$router->get('/admin',                AdminController::class, 'index', PERFIL_ADMIN);
$router->get('/admin/denuncias',      AdminController::class, 'denuncias', PERFIL_ADMIN);
$router->get('/admin/auditoria',      AdminController::class, 'auditoria', PERFIL_ADMIN);
$router->get('/admin/sessoes',        AdminController::class, 'sessoes', PERFIL_ADMIN);
// Supervisão humana dos critérios do motor (edital 12.3): quem muda um peso fica na trilha.
$router->get('/admin/parametros',     AdminController::class, 'parametros', PERFIL_ADMIN);
$router->post('/admin/parametros',    AdminController::class, 'salvarParametros', PERFIL_ADMIN);
// Lixeira do item 8.6j: o excluído continua acessível pelo mecanismo administrativo.
$router->get('/admin/lixeira',        AdminController::class, 'lixeira', PERFIL_ADMIN);
$router->post('/admin/lixeira',       AdminController::class, 'restaurar', PERFIL_ADMIN);
// Gestão de contas (Anexo I item 3, "gerir perfis"): bloquear fora do fluxo de denúncia.
$router->get('/admin/contas',         AdminController::class, 'contas', PERFIL_ADMIN);
$router->post('/admin/contas/{id}/bloquear',   AdminController::class, 'bloquearConta', PERFIL_ADMIN);
$router->post('/admin/contas/{id}/desbloquear', AdminController::class, 'desbloquearConta', PERFIL_ADMIN);
// Depois da rota sem parâmetro, pelo mesmo motivo das denúncias: o Router percorre na ordem de
// registro e {id} casaria 'sessoes' antes.
$router->get('/admin/sessoes/{id}',   AdminController::class, 'sessao', PERFIL_ADMIN);
// Depois da rota sem parâmetro: o Router percorre na ordem de registro, e {id} casaria antes.
$router->get('/admin/denuncias/{id}',  AdminController::class, 'denuncia', PERFIL_ADMIN);
$router->post('/admin/denuncias/{id}', AdminController::class, 'tratar',   PERFIL_ADMIN);

// Próximas, na ordem do backlog: /perfil, /demandas — ver docs/backlog.md

// ---------------------------------------------------------------- fila de e-mail
//
// O gatilho que faltava desde a E1: `despachar()` existia e nada o chamava, então a fila só
// andava quando alguém rodava um script à mão.
//
// **Por que em `register_shutdown_function` e não no fim do arquivo.** O front controller sai por
// `exit` em seis caminhos — 404, 401, 403, CSRF inválido, e todo `View::redirecionar()`, que é
// `never`. Código no fim do arquivo não roda em nenhum deles, e manifestação que redireciona
// depois de gravar é justamente o caso que mais precisa do e-mail. O shutdown dispara em todos.
//
// **Por que depois de `fastcgi_finish_request()`.** SMTP é rede: sem isso o navegador ficaria
// esperando o e-mail sair para receber a página. A função existe porque o contêiner é
// `php:8.2-fpm-alpine`; o `function_exists` cobre a execução por CLI, onde o conceito não se
// aplica e a resposta já foi embora de qualquer jeito.
//
// **O lote é pequeno de propósito** (MAIL_LOTE_POS_RESPOSTA): a resposta já foi, mas o processo
// do php-fpm continua ocupado. Fila represada drena em várias requisições em vez de prender um
// processo por minutos.
//
// Falha aqui nunca chega ao usuário: a resposta já saiu, e o que resta é registro.
register_shutdown_function(static function (): void {
    if (MAIL_HOST === '') {
        return;
    }

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    try {
        (new NotificacaoService())->despachar(MAIL_LOTE_POS_RESPOSTA);
    } catch (Throwable $e) {
        error_log('Falha no despacho pos-resposta da fila: ' . $e->getMessage());
    }
});

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
        $token  = Sessao::tokenServidor();
        $viva   = $token === null ? null : (new SessaoRepository())->ativa($token);

        if ($viva === null) {
            Sessao::reiniciar();
            Flash::aviso('Sua sessão foi encerrada. Entre novamente.');

            // Em rota pública segue como visitante anônimo, e não redireciona: a home e os termos
            // são visíveis a quem nunca entrou, e mandar /saude para /login quebraria o
            // monitoramento por um motivo que não é dele.
            if (!Router::ehPublica($rota['perfis'])) {
                View::redirecionar('/login');
            }
        } else {
            // O perfil também é reconferido, e não só a existência da sessão. `$_SESSION` guarda
            // uma cópia feita no login, e enquanto ninguém a comparasse com o banco, mudança de
            // papel só valia no login seguinte: uma conta rebaixada para Terceiro continuava
            // alcançando rota de Profissional com a sessão que já tinha. Medido com requisição
            // forjada em 17/09 (OWASP A01), e a consulta que descobre isso é a mesma de cima.
            //
            // A sessão é atualizada, e não derrubada: o rebaixamento acontece quando a própria
            // pessoa manda revalidar o registro, e encerrar a sessão dela ali seria punir o ato de
            // conferir. A autorização passa a usar o perfil corrente, que é o que importa.
            $anterior = Sessao::trocarPerfil((string) $viva['per_codigo']);

            if ($anterior !== null) {
                Auditoria::registrar(
                    Auditoria::EDITAR,
                    'sis_usuarios',
                    (int) $viva['ses_usu_id'],
                    'usu_per_id',
                    $anterior,
                    (string) $viva['per_codigo'],
                    (int) $viva['ses_usu_id'],
                );
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
