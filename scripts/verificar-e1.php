<?php

declare(strict_types=1);

/**
 * Verificação de ponta a ponta da E1 — identidade, consentimento e auditoria (RF01).
 *
 * O critério de pronto da E1, em docs/backlog.md, é: "cada perfil faz cadastro → login → logout;
 * cinco senhas erradas bloqueiam por 15 minutos; a exportação devolve JSON; sis_auditoria mostra
 * tudo isso." Este script executa exatamente isso, por HTTP, contra a aplicação rodando.
 *
 * Por que por HTTP e não por teste unitário: o que precisa ser provado aqui inclui CSRF, sessão,
 * cookie, redirecionamento e autorização por requisição — coisas que só existem numa requisição
 * de verdade. Os testes de PHPUnit cobrem a lógica pura; este cobre o caminho completo.
 *
 * Usa o banco para preparar e conferir (liberar bloqueio, ler auditoria), porque é o banco da
 * própria aplicação. Cada execução usa e-mails com sufixo de tempo, então rodar de novo não
 * colide; as contas criadas terminam excluídas ('X').
 *
 *     docker compose exec php php scripts/verificar-e1.php
 *     php scripts/verificar-e1.php http://127.0.0.1:8099
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Support\Database;

$base   = rtrim($argv[1] ?? APP_URL, '/');
$cookie = tempnam(sys_get_temp_dir(), 'prolink-e1-');
$marca  = date('His');
// Senha descartável das contas que este script cria e exclui. Não é credencial de ninguém e não
// abre nada: as contas nascem e morrem dentro da execução. Se a varredura de segredos da E7
// apontar esta linha, é falso positivo — e é por isso que ela está comentada assim.
$senha  = 'SenhaDeVerificacao1';

$pdo      = Database::conexao();
$aprovado = 0;
$falhou   = 0;

// ---------------------------------------------------------------- infraestrutura do script

/**
 * Zera o cookie jar: a próxima requisição começa sem sessão.
 *
 * É função separada, e não parâmetro de requisitar(), por um motivo que já custou um bug neste
 * script: PHP avalia os argumentos antes de chamar a função, então `requisitar(..., csrf(...),
 * novaSessao: true)` pegava o token numa sessão e o enviava em outra. O POST virava 419, e as
 * verificações que esperavam recusa passavam pelo motivo errado.
 */
function novaSessao(): void
{
    file_put_contents($GLOBALS['cookie'], '');
}

function requisitar(string $metodo, string $url, array $campos = []): array
{
    global $cookie;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $cookie,
        CURLOPT_COOKIEFILE     => $cookie,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT        => 20,
    ]);

    if ($metodo === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($campos));
    }

    $corpo  = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $erro   = curl_error($ch);
    curl_close($ch);

    if ($erro !== '') {
        fwrite(STDERR, "\nFalha de transporte em {$url}: {$erro}\n");
        fwrite(STDERR, "A aplicação está rodando em {$GLOBALS['base']}?\n");
        exit(2);
    }

    return ['status' => $status, 'corpo' => $corpo];
}

/** Busca o token CSRF do formulário, que é o que o front controller exige em todo POST. */
function csrf(string $caminho): string
{
    $resposta = requisitar('GET', $GLOBALS['base'] . $caminho);

    if (preg_match('/name="_csrf" value="([a-f0-9]{64})"/', $resposta['corpo'], $achado) !== 1) {
        fwrite(STDERR, "Não achei token CSRF em {$caminho} (HTTP {$resposta['status']}).\n");
        exit(2);
    }

    return $achado[1];
}

function conferir(string $descricao, bool $condicao, string $detalhe = ''): void
{
    global $aprovado, $falhou;

    if ($condicao) {
        $aprovado++;
        printf("  \e[32mok\e[0m    %s\n", $descricao);

        return;
    }

    $falhou++;
    printf("  \e[31mFALHOU\e[0m %s%s\n", $descricao, $detalhe !== '' ? "  ({$detalhe})" : '');
}

function secao(string $titulo): void
{
    printf("\n%s\n", $titulo);
}

/** Cadastra e devolve o id, ou null se a aplicação recusou. */
function cadastrar(string $tipo, string $nome, string $documento, string $email): ?int
{
    global $base, $senha, $pdo;

    novaSessao();

    $resposta = requisitar('POST', $base . '/cadastro', [
        '_csrf'              => csrf('/cadastro'),
        'tipo_cadastro'      => $tipo,
        'nome'               => $nome,
        'documento'          => $documento,
        'email'              => $email,
        'senha'              => $senha,
        'senha_confirmacao'  => $senha,
        'aceite_uso'         => '1',
        'aceite_privacidade' => '1',
        'consentimento_api'  => '1',
    ]);

    if ($resposta['status'] !== 303) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT usu_id FROM sis_usuarios WHERE usu_email = :email');
    $stmt->execute([':email' => $email]);
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

function entrar(string $email): int
{
    global $base, $senha;

    novaSessao();

    return requisitar('POST', $base . '/login', [
        '_csrf' => csrf('/login'),
        'email' => $email,
        'senha' => $senha,
    ])['status'];
}

// ---------------------------------------------------------------- execução

printf("Verificação da E1 — %s\n", $base);

$saude = requisitar('GET', $base . '/saude');
conferir('a aplicação responde e o banco está de pé', $saude['status'] === 200,
    "HTTP {$saude['status']}");

if ($saude['status'] !== 200) {
    fwrite(STDERR, "\nSem aplicação no ar não há o que verificar. Suba o ambiente e rode de novo.\n");
    exit(2);
}

// ---------------------------------------------------------------- CSRF
secao('Proteção de CSRF (edital 8.5e)');

novaSessao();
$semToken = requisitar('POST', $base . '/cadastro', ['nome' => 'Sem Token']);
conferir('POST sem token é recusado com 419', $semToken['status'] === 419,
    "HTTP {$semToken['status']}");

$tokenErrado = requisitar('POST', $base . '/cadastro',
    ['_csrf' => str_repeat('0', 64), 'nome' => 'Token Falso']);
conferir('POST com token inválido é recusado com 419', $tokenErrado['status'] === 419,
    "HTTP {$tokenErrado['status']}");

// ---------------------------------------------------------------- os quatro tipos de cadastro
secao('Cadastro nos quatro tipos, com documento da massa oficial');

// CPF e CNPJ reais da massa fictícia. Os CNPJs são dois dos 15 com dígito verificador válido
// (decisão D07); os outros 85 são recusados de propósito, e isso é conferido adiante.
$contas = [
    CADASTRO_PROFISSIONAL => ['Ana Clara Costa',      '12312300109',    "prof.{$marca}@verificacao.local"],
    CADASTRO_EMPRESA      => ['Amazônia Construções', '00123001000123', "empresa.{$marca}@verificacao.local"],
    CADASTRO_TERCEIRO_PF  => ['João Miguel Santos',   '12312300290',    "terceiropf.{$marca}@verificacao.local"],
    CADASTRO_TERCEIRO_PJ  => ['Norte Obras',          '00123002000178', "terceiropj.{$marca}@verificacao.local"],
];

$criados = [];

foreach ($contas as $tipo => [$nome, $documento, $email]) {
    $id = cadastrar($tipo, $nome, $documento, $email);
    conferir("cadastro {$tipo} cria a conta", $id !== null);

    if ($id !== null) {
        $criados[$tipo] = ['id' => $id, 'email' => $email];
    }
}

// ---------------------------------------------------------------- o que ficou gravado
secao('O que o cadastro gravou');

foreach ($criados as $tipo => $conta) {
    $stmt = $pdo->prepare(
        'SELECT usu_senha_hash, usu_documento_cif, usu_documento_hash, per_codigo
           FROM sis_usuarios JOIN sis_perfis ON per_id = usu_per_id WHERE usu_id = :id'
    );
    $stmt->execute([':id' => $conta['id']]);
    $linha = $stmt->fetch();

    $cif = $linha['usu_documento_cif'];
    $cif = is_resource($cif) ? (string) stream_get_contents($cif) : (string) $cif;

    conferir("{$tipo}: senha em Argon2id, nunca em claro",
        str_starts_with((string) $linha['usu_senha_hash'], '$argon2id$'));
    conferir("{$tipo}: documento cifrado em repouso (AES-256-GCM)", strlen($cif) >= 29);
    conferir("{$tipo}: hash cego gravado para busca sem decifrar",
        strlen((string) $linha['usu_documento_hash']) === 64);
    conferir("{$tipo}: perfil de acesso coerente com o tipo",
        $linha['per_codigo'] === \ProLink\Service\AutenticacaoService::perfilDe($tipo));
}

if ($criados === []) {
    fwrite(STDERR, "\nNenhum cadastro passou — sem isso o resto da verificação não tem base.\n");
    fwrite(STDERR, "Veja o log de erros da aplicação (storage/logs ou a saída do servidor).\n");
    exit(1);
}

$primeira = reset($criados);

$stmt = $pdo->prepare(
    'SELECT con_finalidade, con_concedido FROM sis_consentimentos WHERE con_usu_id = :id'
);
$stmt->execute([':id' => $primeira['id']]);
$consentimentos = [];

foreach ($stmt->fetchAll() as $linha) {
    $consentimentos[$linha['con_finalidade']] = (int) $linha['con_concedido'];
}

conferir('aceite dos Termos de Uso registrado e versionado',
    ($consentimentos[FINALIDADE_ACEITE_TERMOS . '_USO'] ?? 0) === 1);
conferir('aceite da Política de Privacidade registrado e versionado',
    ($consentimentos[FINALIDADE_ACEITE_TERMOS . '_PRIVACIDADE'] ?? 0) === 1);
conferir('consentimento recusado fica gravado como recusa, não como ausência',
    array_key_exists(FINALIDADE_EXIBICAO_PERFIL, $consentimentos)
        && $consentimentos[FINALIDADE_EXIBICAO_PERFIL] === 0);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM sis_notificacoes WHERE not_usu_id = :id AND not_tipo = :tipo');
$stmt->execute([':id' => $primeira['id'], ':tipo' => 'CADASTRO']);
conferir('e-mail de boas-vindas entrou na fila', (int) $stmt->fetchColumn() === 1);

// ---------------------------------------------------------------- validação de documento
secao('Validação de documento (decisão D07)');

$dvQuebrado = cadastrar(CADASTRO_EMPRESA, 'Eletronorte', '00123021000104',
    "dvquebrado.{$marca}@verificacao.local");
conferir('CNPJ da massa com dígito verificador quebrado é recusado', $dvQuebrado === null);

$cpfInventado = cadastrar(CADASTRO_TERCEIRO_PF, 'Cpf Falso', '11111111111',
    "cpffalso.{$marca}@verificacao.local");
conferir('CPF com todos os dígitos iguais é recusado', $cpfInventado === null);

$emailRepetido = cadastrar(CADASTRO_TERCEIRO_PF, 'Repetido', '12312300370', $primeira['email']);
conferir('e-mail já cadastrado é recusado', $emailRepetido === null);

$documentoRepetido = cadastrar(CADASTRO_TERCEIRO_PF, 'Documento Repetido', '12312300109',
    "docrepetido.{$marca}@verificacao.local");
conferir('documento já cadastrado é recusado', $documentoRepetido === null);

// ---------------------------------------------------------------- login, sessão e logout
secao('Login, sessão no servidor e logout');

foreach ($criados as $tipo => $conta) {
    conferir("{$tipo}: login devolve 303 e abre sessão", entrar($conta['email']) === 303);
}

$stmt = $pdo->prepare('SELECT ses_token_hash FROM sis_sessoes WHERE ses_usu_id = :id AND ses_dt_revogacao IS NULL');
$stmt->execute([':id' => $primeira['id']]);
conferir('sessão registrada em sis_sessoes com hash do identificador',
    strlen((string) $stmt->fetchColumn()) === 64);

// Entra com a última conta e confere que o painel de privacidade abre autenticado.
$ultima = end($criados);
entrar($ultima['email']);
conferir('painel de privacidade abre para quem está autenticado',
    requisitar('GET', $base . '/privacidade')['status'] === 200);

$exportacao = requisitar('GET', $base . '/privacidade/exportar');
$json       = json_decode($exportacao['corpo'], true);
conferir('exportação de dados devolve JSON válido',
    $exportacao['status'] === 200 && is_array($json));
conferir('exportação traz a conta, os consentimentos e o histórico de ações',
    is_array($json) && isset($json['conta'], $json['consentimentos'], $json['minhas_acoes']));
conferir('exportação não contém senha nem hash de senha',
    is_array($json) && !str_contains(strtolower($exportacao['corpo']), 'senha_hash')
        && !str_contains($exportacao['corpo'], '$argon2'));

$saida = requisitar('POST', $base . '/sair', ['_csrf' => csrf('/privacidade')]);
conferir('logout por POST encerra a sessão', $saida['status'] === 303);
conferir('depois do logout o painel exige login de novo',
    requisitar('GET', $base . '/privacidade')['status'] === 401);

// ---------------------------------------------------------------- força bruta
secao('Bloqueio por tentativas (OWASP A07)');

$alvo = $criados[CADASTRO_TERCEIRO_PF] ?? $primeira;
$pdo->prepare('UPDATE sis_usuarios SET usu_tentativas = 0, usu_bloqueado_ate = NULL WHERE usu_id = :id')
    ->execute([':id' => $alvo['id']]);

for ($tentativa = 1; $tentativa <= LOGIN_MAX_ATTEMPTS; $tentativa++) {
    novaSessao();
    requisitar('POST', $base . '/login', [
        '_csrf' => csrf('/login'),
        'email' => $alvo['email'],
        'senha' => 'SenhaErrada' . $tentativa,
    ]);
}

$stmt = $pdo->prepare('SELECT usu_bloqueado_ate FROM sis_usuarios WHERE usu_id = :id');
$stmt->execute([':id' => $alvo['id']]);
$bloqueadoAte = $stmt->fetchColumn();

conferir(sprintf('%d senhas erradas bloqueiam a conta', LOGIN_MAX_ATTEMPTS),
    $bloqueadoAte !== null && strtotime((string) $bloqueadoAte) > time());
conferir(sprintf('o bloqueio dura cerca de %d minutos', LOGIN_LOCKOUT_MINUTES),
    $bloqueadoAte !== null
        && abs((strtotime((string) $bloqueadoAte) - time()) - LOGIN_LOCKOUT_MINUTES * 60) < 120);
conferir('a senha correta é recusada enquanto a conta está bloqueada', entrar($alvo['email']) === 200);

$pdo->prepare('UPDATE sis_usuarios SET usu_tentativas = 0, usu_bloqueado_ate = NULL WHERE usu_id = :id')
    ->execute([':id' => $alvo['id']]);
conferir('liberado o bloqueio, a senha correta volta a funcionar', entrar($alvo['email']) === 303);

// ---------------------------------------------------------------- consentimento e sessão
secao('Revogação de consentimento e de sessão');

requisitar('POST', $base . '/privacidade/consentimento', [
    '_csrf'      => csrf('/privacidade'),
    'finalidade' => FINALIDADE_CONSULTA_API,
    'acao'       => 'revogar',
]);

$stmt = $pdo->prepare(
    'SELECT con_concedido, con_dt_concessao, con_dt_revogacao
       FROM sis_consentimentos WHERE con_usu_id = :id AND con_finalidade = :finalidade'
);
$stmt->execute([':id' => $alvo['id'], ':finalidade' => FINALIDADE_CONSULTA_API]);
$consentimento = $stmt->fetch();

conferir('revogação desliga o consentimento', (int) $consentimento['con_concedido'] === 0);
conferir('revogação preserva quando a finalidade foi concedida',
    $consentimento['con_dt_concessao'] !== null && $consentimento['con_dt_revogacao'] !== null);

// Revogar a sessão no banco é o que o administrador vai fazer na E6 ao bloquear um usuário.
$pdo->prepare('UPDATE sis_sessoes SET ses_dt_revogacao = NOW() WHERE ses_usu_id = :id')
    ->execute([':id' => $alvo['id']]);
$depoisDaRevogacao = requisitar('GET', $base . '/privacidade');
conferir('sessão revogada no servidor derruba a requisição seguinte',
    $depoisDaRevogacao['status'] === 303);

// ---------------------------------------------------------------- recuperação de senha
secao('Recuperação de senha');

$conta = $criados[CADASTRO_EMPRESA] ?? $primeira;

novaSessao();
requisitar('POST', $base . '/recuperar-senha',
    ['_csrf' => csrf('/recuperar-senha'), 'email' => $conta['email']]);

$stmt = $pdo->prepare(
    'SELECT not_corpo FROM sis_notificacoes
      WHERE not_usu_id = :id AND not_tipo = :tipo ORDER BY not_id DESC LIMIT 1'
);
$stmt->execute([':id' => $conta['id'], ':tipo' => 'RECUPERACAO_SENHA']);
$corpo = (string) $stmt->fetchColumn();

conferir('pedido de recuperação enfileira o e-mail', $corpo !== '');

$temLink = preg_match('#/redefinir-senha/([a-f0-9]{64})#', $corpo, $achado) === 1;
conferir('o e-mail traz um link com token de 256 bits', $temLink);

$stmt = $pdo->prepare('SELECT rec_token_hash FROM sis_recuperacoes WHERE rec_usu_id = :id ORDER BY rec_id DESC LIMIT 1');
$stmt->execute([':id' => $conta['id']]);
conferir('o banco guarda só o hash do token, nunca o token',
    $temLink && $stmt->fetchColumn() === hash('sha256', $achado[1]));

if ($temLink) {
    $caminho = '/redefinir-senha/' . $achado[1];
    $nova    = 'SenhaRedefinida99';

    novaSessao();
    $redefinido = requisitar('POST', $base . $caminho, [
        '_csrf'             => csrf($caminho),
        'senha'             => $nova,
        'senha_confirmacao' => $nova,
    ]);
    conferir('o link redefine a senha', $redefinido['status'] === 303);

    $reuso = requisitar('POST', $base . $caminho, [
        '_csrf'             => csrf($caminho),
        'senha'             => 'OutraQualquer123',
        'senha_confirmacao' => 'OutraQualquer123',
    ]);
    conferir('o mesmo link não serve duas vezes', $reuso['status'] === 200);

    novaSessao();
    $comNova = requisitar('POST', $base . '/login',
        ['_csrf' => csrf('/login'), 'email' => $conta['email'], 'senha' => $nova]);
    conferir('login funciona com a senha nova', $comNova['status'] === 303);

    novaSessao();
    $comAntiga = requisitar('POST', $base . '/login',
        ['_csrf' => csrf('/login'), 'email' => $conta['email'], 'senha' => $senha]);
    conferir('a senha antiga deixa de funcionar', $comAntiga['status'] === 200);
}

// ---------------------------------------------------------------- exclusão pelo titular
secao('Exclusão da conta pelo titular (edital 11.3 e 8.6j)');

$excluir = $criados[CADASTRO_TERCEIRO_PJ] ?? $primeira;
entrar($excluir['email']);

requisitar('POST', $base . '/privacidade/excluir', ['_csrf' => csrf('/privacidade'), 'confirmacao' => 'talvez']);
$stmt = $pdo->prepare('SELECT usu_status FROM sis_usuarios WHERE usu_id = :id');
$stmt->execute([':id' => $excluir['id']]);
conferir('confirmação errada não exclui a conta', $stmt->fetchColumn() === STATUS_ATIVO);

requisitar('POST', $base . '/privacidade/excluir', ['_csrf' => csrf('/privacidade'), 'confirmacao' => 'EXCLUIR']);
$stmt->execute([':id' => $excluir['id']]);
conferir('exclusão é lógica: status X, nada apagado fisicamente',
    $stmt->fetchColumn() === STATUS_EXCLUIDO);

$stmt2 = $pdo->prepare('SELECT COUNT(*) FROM sis_sessoes WHERE ses_usu_id = :id AND ses_dt_revogacao IS NULL');
$stmt2->execute([':id' => $excluir['id']]);
conferir('a exclusão encerra todas as sessões do titular', (int) $stmt2->fetchColumn() === 0);
conferir('conta excluída não autentica mais', entrar($excluir['email']) === 200);

// ---------------------------------------------------------------- trilha de auditoria
secao('Trilha de auditoria (edital 8.5g)');

$ids  = array_column($criados, 'id');
$vaga = implode(',', array_fill(0, count($ids), '?'));

$stmt = $pdo->prepare("SELECT aud_acao, COUNT(*) total FROM sis_auditoria
                        WHERE aud_usu_id IN ({$vaga}) GROUP BY aud_acao");
$stmt->execute($ids);
$acoes = [];

foreach ($stmt->fetchAll() as $linha) {
    $acoes[$linha['aud_acao']] = (int) $linha['total'];
}

foreach (['CRIAR', 'LOGIN', 'LOGIN_FALHOU', 'BLOQUEIO_LOGIN', 'LOGOUT', 'REVOGAR',
          'EXPORTAR_DADOS', 'EXCLUIR'] as $acao) {
    conferir("sis_auditoria registra {$acao}", ($acoes[$acao] ?? 0) > 0);
}

$stmt = $pdo->prepare("SELECT COUNT(*) FROM sis_auditoria
                        WHERE aud_usu_id IN ({$vaga}) AND aud_ip IS NULL");
$stmt->execute($ids);
conferir('toda linha de auditoria tem IP', (int) $stmt->fetchColumn() === 0);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM sis_auditoria
                        WHERE aud_usu_id IN ({$vaga})
                          AND (aud_valor_novo LIKE '%argon2%' OR aud_valor_anterior LIKE '%argon2%')");
$stmt->execute($ids);
conferir('nenhuma senha ou hash de senha vazou para a auditoria', (int) $stmt->fetchColumn() === 0);

try {
    $pdo->exec('UPDATE sis_auditoria SET aud_acao = "ADULTERADO" WHERE aud_id = 1');
    conferir('sis_auditoria recusa UPDATE (insert-only por trigger)', false, 'o UPDATE passou');
} catch (\PDOException) {
    conferir('sis_auditoria recusa UPDATE (insert-only por trigger)', true);
}

// ---------------------------------------------------------------- limpeza e resultado
secao('Limpeza');

$stmt = $pdo->prepare("UPDATE sis_usuarios SET usu_status = ? WHERE usu_id IN ({$vaga})");
$stmt->execute(array_merge([STATUS_EXCLUIDO], $ids));
printf("  contas de verificação marcadas como excluídas: %d\n", count($ids));
printf("  as linhas de auditoria permanecem, por definição — a tabela é imutável\n");

@unlink($cookie);

printf("\n%s  %d aprovadas, %d falharam\n",
    $falhou === 0 ? "\e[32mE1 VERIFICADA\e[0m" : "\e[31mE1 COM FALHAS\e[0m",
    $aprovado, $falhou);

exit($falhou === 0 ? 0 : 1);
