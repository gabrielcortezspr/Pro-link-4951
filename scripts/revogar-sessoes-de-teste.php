<?php

declare(strict_types=1);

/**
 * Revoga as sessões abertas que a verificação deixou para trás.
 *
 * Cada execução da suíte de ponta a ponta e de `verificar-e1.php` faz vários logins, e nem todo
 * caminho termina com logout. A sessão continua viva no servidor até expirar, e o painel de
 * privacidade do titular lista **todas** as abertas: o profissional de demonstração chegou a 77,
 * a conta de administração da suíte a 201. É dado constrangedor numa tela que a banca abre para
 * ver controle de acesso.
 *
 * **Revoga, não apaga.** `ses_dt_revogacao` é o mesmo mecanismo do botão "Sair" e do bloqueio
 * administrativo, e a linha continua em `sis_sessoes` para quem auditar. Nada aqui contraria o
 * item 8.6j.
 *
 * **Só contas de verificação e de demonstração.** O filtro é o domínio do e-mail:
 * `@verificacao.local` (criadas pelos scripts) e `@prolink.local` (semeadas por
 * `semear-candidatos.php`). Conta de pessoa real nunca entra, e é por isso que o filtro é por
 * domínio e não por "todas as sessões antigas": derrubar quem está trabalhando não é higiene.
 *
 * Uso:
 *   docker compose exec php php scripts/revogar-sessoes-de-teste.php --simular
 *   docker compose exec php php scripts/revogar-sessoes-de-teste.php
 *
 * Passo de preparo da demonstração. Ver `docs/roteiro-demo.md`.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Support\Database;

$opcoes  = getopt('', ['simular']);
$simular = array_key_exists('simular', $opcoes);

$pdo = Database::conexao();

$abertas = $pdo->query(
    "SELECT u.usu_nome, u.usu_email, COUNT(*) AS abertas
       FROM sis_sessoes s
       JOIN sis_usuarios u ON u.usu_id = s.ses_usu_id
      WHERE s.ses_dt_revogacao IS NULL
        AND s.ses_dt_expiracao > NOW()
        AND s.ses_status = 'A'
        AND (u.usu_email LIKE '%@verificacao.local' OR u.usu_email LIKE '%@prolink.local')
      GROUP BY s.ses_usu_id
      ORDER BY abertas DESC"
)->fetchAll();

printf(
    "\e[1mSessões abertas de contas de verificação e demonstração\e[0m%s\n\n",
    $simular ? "  \e[33m(simulação: nada é revogado)\e[0m" : '',
);

if ($abertas === []) {
    echo "  Nenhuma. Nada a fazer.\n";
    exit(0);
}

$total = 0;

foreach ($abertas as $linha) {
    printf("  %-34s %4d aberta(s)\n", mb_substr((string) $linha['usu_nome'], 0, 34), (int) $linha['abertas']);
    $total += (int) $linha['abertas'];
}

if ($simular) {
    printf("\n\e[33mSIMULAÇÃO\e[0m  %d sessão(ões) seriam revogadas em %d conta(s).\n", $total, count($abertas));
    exit(0);
}

$revogadas = $pdo->exec(
    "UPDATE sis_sessoes s
       JOIN sis_usuarios u ON u.usu_id = s.ses_usu_id
        SET s.ses_dt_revogacao = NOW()
      WHERE s.ses_dt_revogacao IS NULL
        AND s.ses_dt_expiracao > NOW()
        AND s.ses_status = 'A'
        AND (u.usu_email LIKE '%@verificacao.local' OR u.usu_email LIKE '%@prolink.local')"
);

printf("\n\e[32mREVOGADAS\e[0m  %d sessão(ões) em %d conta(s).\n", (int) $revogadas, count($abertas));
echo "As linhas continuam em sis_sessoes, com a data de revogação: nada foi apagado.\n";
