<?php

declare(strict_types=1);

/**
 * _config.php — configuração central da aplicação.
 *
 * Exigido pelo edital, Anexo I, item 8.3.1: centraliza URLs, PATHs, PATH_IMG, parâmetros de
 * banco e de APIs, constantes globais, ambiente, fuso horário, charset e SMTP. Nenhum segredo
 * mora aqui: tudo o que é sensível vem do .env (item 8.3.1j).
 *
 * Fica na raiz da aplicação, fora do document root (public/), de propósito.
 */

// ---------------------------------------------------------------- ambiente base

mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

require_once __DIR__ . '/vendor/autoload.php';

if (is_file(__DIR__ . '/.env')) {
    Dotenv\Dotenv::createImmutable(__DIR__)->safeLoad();
}

/** Lê variável de ambiente com valor padrão e conversão de booleano. */
function env(string $chave, mixed $padrao = null): mixed
{
    $valor = $_ENV[$chave] ?? getenv($chave);

    if ($valor === false || $valor === null || $valor === '') {
        return $padrao;
    }

    return match (strtolower((string) $valor)) {
        'true', '(true)'   => true,
        'false', '(false)' => false,
        'null', '(null)'   => null,
        default            => $valor,
    };
}

// ---------------------------------------------------------------- ambiente e fuso

define('APP_ENV', env('APP_ENV', 'dev'));
define('APP_DEBUG', (bool) env('APP_DEBUG', false));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'America/Manaus'));

date_default_timezone_set(APP_TIMEZONE);

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED);
    ini_set('display_errors', '0');   // mensagens de erro seguras (OWASP A05)
    ini_set('log_errors', '1');
}

// ---------------------------------------------------------------- URLs

define('APP_URL', rtrim((string) env('APP_URL', 'http://localhost:8080'), '/'));
define('URL_ASSETS', APP_URL . '/assets');
define('URL_IMG', URL_ASSETS . '/img');

// ---------------------------------------------------------------- caminhos físicos

define('PATH_ROOT', __DIR__);
define('PATH_PUBLIC', PATH_ROOT . '/public');
define('PATH_SRC', PATH_ROOT . '/src');
define('PATH_TEMPLATES', PATH_ROOT . '/templates');
define('PATH_ARQ', PATH_ROOT . '/_arq');
define('PATH_DATA', PATH_ROOT . '/data');
define('PATH_FIXTURES', PATH_ROOT . '/fixtures');
define('PATH_STORAGE', PATH_ROOT . '/storage');
define('PATH_CACHE', PATH_STORAGE . '/cache');
define('PATH_LOGS', PATH_STORAGE . '/logs');
define('PATH_UPLOADS', PATH_PUBLIC . '/assets/uploads');
define('PATH_IMG', PATH_PUBLIC . '/assets/img');

// ---------------------------------------------------------------- banco de dados

define('DB_HOST', env('DB_HOST', 'mariadb'));
define('DB_PORT', (int) env('DB_PORT', 3306));
define('DB_DATABASE', env('DB_DATABASE', 'prolink'));
define('DB_USERNAME', env('DB_USERNAME', 'prolink'));
define('DB_PASSWORD', env('DB_PASSWORD', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));
define('DB_COLLATION', env('DB_COLLATION', 'utf8mb4_unicode_ci'));
define('DB_DSN', sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_DATABASE, DB_CHARSET));

// ---------------------------------------------------------------- API oficial do CREA-AM

define('API_BASE', env('PROLINK_API_BASE', 'https://desafio-prolink.crea-am.org.br/api/v1/'));
define('API_TOKEN', env('PROLINK_API_TOKEN', ''));
define('API_TIMEOUT', (int) env('PROLINK_API_TIMEOUT', 30));

// ---------------------------------------------------------------- e-mail (RF07)

define('MAIL_HOST', env('MAIL_HOST', ''));
define('MAIL_PORT', (int) env('MAIL_PORT', 587));
define('MAIL_USERNAME', env('MAIL_USERNAME', ''));
define('MAIL_PASSWORD', env('MAIL_PASSWORD', ''));
define('MAIL_ENCRYPTION', env('MAIL_ENCRYPTION', 'tls'));
define('MAIL_FROM_ADDRESS', env('MAIL_FROM_ADDRESS', 'nao-responda@prolink.local'));
define('MAIL_FROM_NAME', env('MAIL_FROM_NAME', 'Pro-Link'));

// ---------------------------------------------------------------- segurança

/** Chave de 32 bytes para AES-256-GCM em repouso (CPF/CNPJ). */
define('APP_KEY', base64_decode((string) env('APP_KEY', ''), true) ?: '');

define('SESSION_LIFETIME_MINUTES', (int) env('SESSION_LIFETIME_MINUTES', 120));
define('LOGIN_MAX_ATTEMPTS', (int) env('LOGIN_MAX_ATTEMPTS', 5));
define('LOGIN_LOCKOUT_MINUTES', (int) env('LOGIN_LOCKOUT_MINUTES', 15));

/** Algoritmo de hash de senha — edital 8.5b exige password_hash(). */
define('PASSWORD_ALGO', PASSWORD_ARGON2ID);

// ---------------------------------------------------------------- constantes de domínio

const PERFIL_PUBLICO      = 'PUBLICO';
const PERFIL_PROFISSIONAL = 'PROFISSIONAL';
const PERFIL_EMPRESA      = 'EMPRESA';
const PERFIL_TERCEIRO     = 'TERCEIRO';
const PERFIL_ADMIN        = 'ADMIN';

/** Exclusão lógica — edital 8.6i/j. 'A' ativo, 'I' inativo, 'X' excluído. */
const STATUS_ATIVO    = 'A';
const STATUS_INATIVO  = 'I';
const STATUS_EXCLUIDO = 'X';

/** Visibilidade granular por campo (RF01, RF03). Nada é público por padrão. */
const VISIBILIDADE_PRIVADO     = 'PRIVADO';
const VISIBILIDADE_AUTENTICADO = 'AUTENTICADO';
const VISIBILIDADE_PUBLICO     = 'PUBLICO';

/** Perfis que exigem login. Rota de usuário autenticado, sem distinção de papel, usa esta lista. */
const PERFIS_AUTENTICADOS = [PERFIL_PROFISSIONAL, PERFIL_EMPRESA, PERFIL_TERCEIRO, PERFIL_ADMIN];

/**
 * Tipos de cadastro oferecidos (RF01, edital Anexo I item 3). Não são perfis: TERCEIRO_PF e
 * TERCEIRO_PJ viram o mesmo PERFIL_TERCEIRO, mudando só o tipo de pessoa e o documento exigido.
 */
const CADASTRO_PROFISSIONAL = 'PROFISSIONAL';
const CADASTRO_EMPRESA      = 'EMPRESA';
const CADASTRO_TERCEIRO_PF  = 'TERCEIRO_PF';
const CADASTRO_TERCEIRO_PJ  = 'TERCEIRO_PJ';

/**
 * Finalidades de consentimento (LGPD; edital 11.3). Uma linha por finalidade em
 * sis_consentimentos, revogável individualmente no painel de privacidade.
 *
 * ACEITE_TERMOS é o único que aponta para sis_termos (con_ter_id) e o único sem o qual não há
 * conta. CONSULTA_API é exigido de quem se cadastra como profissional ou empresa, porque sem
 * consultar a API não existe validação de registro no CREA — é execução do serviço, não
 * conveniência nossa, e a tela diz isso.
 */
const FINALIDADE_ACEITE_TERMOS   = 'ACEITE_TERMOS';
const FINALIDADE_CONSULTA_API    = 'CONSULTA_API';
const FINALIDADE_EXIBICAO_PERFIL = 'EXIBICAO_PERFIL';
const FINALIDADE_NOTIFICACOES    = 'NOTIFICACOES';

/** Mínimo de caracteres da senha. Acima dos 8 usuais: o edital 8.5b pede autenticação segura. */
define('SENHA_TAMANHO_MINIMO', (int) env('SENHA_TAMANHO_MINIMO', 12));

/** Validade do link de recuperação de senha, em minutos. */
define('RECUPERACAO_VALIDADE_MINUTOS', (int) env('RECUPERACAO_VALIDADE_MINUTOS', 60));
