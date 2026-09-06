<?php

declare(strict_types=1);

/**
 * Cria o usuário administrador.
 *
 * Existe para que nenhuma credencial viaje no repositório: o Anexo VI do edital lista
 * "não contém credenciais, segredos ou chaves reais" como item de triagem da banca, e uma
 * senha padrão em carga-inicial.sql seria exatamente isso.
 *
 *     docker compose exec php php scripts/criar-admin.php
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Support\Crypto;
use ProLink\Support\Database;

function perguntar(string $rotulo, bool $oculto = false): string
{
    fwrite(STDOUT, $rotulo);

    if (!$oculto) {
        return trim((string) fgets(STDIN));
    }

    // Sem eco no terminal, para a senha não ficar no histórico visível
    shell_exec('stty -echo 2>/dev/null');
    $valor = trim((string) fgets(STDIN));
    shell_exec('stty echo 2>/dev/null');
    fwrite(STDOUT, PHP_EOL);

    return $valor;
}

$pdo = Database::conexao();

$perfilId = $pdo->query("SELECT per_id FROM sis_perfis WHERE per_codigo = 'ADMIN'")->fetchColumn();

if ($perfilId === false) {
    exit("Perfil ADMIN não encontrado. A carga inicial rodou? Veja _arq/README.md.\n");
}

$nome  = perguntar('Nome do administrador: ');
$email = perguntar('E-mail: ');
$senha = perguntar('Senha (não aparece na tela): ', true);
$confirmacao = perguntar('Repita a senha: ', true);

if ($senha !== $confirmacao) {
    exit("As senhas não conferem.\n");
}

if (mb_strlen($senha) < 12) {
    exit("Use pelo menos 12 caracteres.\n");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("E-mail inválido.\n");
}

$jaExiste = $pdo->prepare('SELECT COUNT(*) FROM sis_usuarios WHERE usu_email = :email');
$jaExiste->execute([':email' => $email]);

if ((int) $jaExiste->fetchColumn() > 0) {
    exit("Já existe usuário com esse e-mail.\n");
}

Database::transacao(function (PDO $pdo) use ($nome, $email, $senha, $perfilId): void {
    $insercao = $pdo->prepare(
        'INSERT INTO sis_usuarios (usu_per_id, usu_nome, usu_email, usu_senha_hash,
                                   usu_tipo_pessoa, usu_email_verificado, usu_status)
         VALUES (:perfil, :nome, :email, :hash, :tipo, 1, :status)'
    );

    $insercao->execute([
        ':perfil' => $perfilId,
        ':nome'   => $nome,
        ':email'  => $email,
        ':hash'   => password_hash($senha, PASSWORD_ALGO),
        ':tipo'   => 'F',
        ':status' => STATUS_ATIVO,
    ]);

    $usuarioId = (int) $pdo->lastInsertId();

    $auditoria = $pdo->prepare(
        'INSERT INTO sis_auditoria (aud_usu_id, aud_ip, aud_acao, aud_entidade, aud_entidade_id)
         VALUES (:usuario, :ip, :acao, :entidade, :entidade_id)'
    );

    $auditoria->execute([
        ':usuario'     => $usuarioId,
        ':ip'          => 'cli',
        ':acao'        => 'CRIAR_ADMIN',
        ':entidade'    => 'sis_usuarios',
        ':entidade_id' => $usuarioId,
    ]);
});

fwrite(STDOUT, "Administrador criado. Entre em " . APP_URL . "/login\n");
