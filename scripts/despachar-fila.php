<?php

declare(strict_types=1);

/**
 * Despacha a fila de notificações à mão.
 *
 * O caminho normal é automático: `public/index.php` despacha um lote pequeno depois de cada
 * resposta, num `register_shutdown_function`. Este script existe para três coisas que aquele
 * caminho não cobre:
 *
 *   · **drenar uma fila represada** sem precisar navegar dezenas de vezes pela aplicação;
 *   · **ver o que aconteceu**, porque o despacho pós-resposta é silencioso por construção — a
 *     resposta já foi ao navegador quando ele roda, e só resta registro;
 *   · **servir de alvo para cron**, para quem preferir agendar em vez de acoplar à requisição.
 *     A decisão de acoplar foi tomada porque não acrescenta peça móvel ao ambiente que a banca
 *     sobe; ela não impede que alguém agende isto num `crontab`.
 *
 * Uso:
 *   docker compose exec php php scripts/despachar-fila.php
 *   docker compose exec php php scripts/despachar-fila.php --limite=200
 *   docker compose exec php php scripts/despachar-fila.php --fila    (só mostra, não envia)
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Service\NotificacaoService;
use ProLink\Support\Database;

$opcoes = getopt('', ['limite::', 'fila']);
$limite = (int) ($opcoes['limite'] ?? 50);
$soVer  = isset($opcoes['fila']);

$pdo = Database::conexao();

$resumo = $pdo->query(
    'SELECT not_tipo,
            SUM(not_dt_envio IS NOT NULL)                              AS enviadas,
            SUM(not_dt_envio IS NULL AND not_tentativas < ' . MAIL_MAX_TENTATIVAS . ') AS a_enviar,
            SUM(not_dt_envio IS NULL AND not_tentativas >= ' . MAIL_MAX_TENTATIVAS . ') AS desistidas
       FROM sis_notificacoes
      WHERE not_status = "A"
      GROUP BY not_tipo
      ORDER BY not_tipo'
)->fetchAll();

printf("\e[1mFila de notificações\e[0m  destino: %s\n\n", MAIL_HOST === '' ? "\e[33mnenhum (MAIL_HOST vazio)\e[0m" : MAIL_HOST . ':' . MAIL_PORT);
printf("  %-22s %8s %9s %11s\n", 'tipo', 'enviadas', 'a enviar', 'desistidas');

foreach ($resumo as $linha) {
    printf(
        "  %-22s %8d %9d %11s\n",
        $linha['not_tipo'],
        (int) $linha['enviadas'],
        (int) $linha['a_enviar'],
        (int) $linha['desistidas'] > 0
            ? "\e[33m" . $linha['desistidas'] . "\e[0m"
            : '0',
    );
}

if ($soVer) {
    printf("\n\e[33mSó leitura.\e[0m  Rode sem --fila para enviar.\n");

    exit(0);
}

if (MAIL_HOST === '') {
    printf("\n\e[31mMAIL_HOST está vazio.\e[0m  Nada é enviado: configure o SMTP no .env.\n");

    exit(1);
}

$r = (new NotificacaoService())->despachar($limite);

printf(
    "\n%s  %d enviada(s) · %d falha(s)%s\n",
    $r['falhas'] === 0 ? "\e[32mPRONTO\e[0m" : "\e[33mCOM FALHAS\e[0m",
    $r['enviadas'],
    $r['falhas'],
    $r['ignoradas'] > 0 ? sprintf(' · %d ignorada(s) por SMTP ausente', $r['ignoradas']) : '',
);

if ($r['falhas'] > 0) {
    printf(
        "Falha só conta tentativa: a mensagem volta na próxima execução até %d tentativas, e\n"
        . "depois disso sai da fila com o erro guardado em not_erro.\n",
        MAIL_MAX_TENTATIVAS,
    );
}
