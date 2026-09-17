<?php

declare(strict_types=1);

/**
 * Reconsulta a situação dos registros já cadastrados na API oficial do CREA (RF02).
 *
 * `pro_status` é o único dado do conselho que muda sozinho depois do cadastro. Quem está suspenso
 * hoje entrou ativo ontem, e o perfil continuaria circulando com selo verde até alguém perceber.
 * Este script é o caminho de volta: reconsulta quem passou do intervalo de `api.sincronizacao.horas`
 * e fecha a visibilidade de quem deixou de estar ativo.
 *
 * **Não é varredura, e o desenho é o que garante isso** (item 10.4): consulta só cadastro nosso,
 * só quem consentiu com a consulta à API, só quem venceu o intervalo, com teto por execução, e
 * parando na primeira indisponibilidade em vez de insistir em rajada.
 *
 * Cada profissional consultado gasta **uma** chamada registrada pela organização.
 *
 * Uso:
 *   docker compose exec php php scripts/sincronizar-status.php --simular
 *   docker compose exec php php scripts/sincronizar-status.php --limite=5
 *   docker compose exec php php scripts/sincronizar-status.php --limite=20
 *
 * `--simular` não chama a API e não grava nada: mostra quem entraria na fila. É o modo certo para
 * conferir o comportamento sem gastar chamada.
 *
 * **Sem cron na entrega.** A banca roda o script à mão, e é assim de propósito: agendador é peça
 * móvel a mais no ambiente que ela precisa subir. Para agendar em produção, um `crontab` diário
 * apontando para esta mesma linha de comando basta, e o intervalo real de reconsulta continua
 * sendo o parâmetro `api.sincronizacao.horas`, não a frequência do cron.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Service\SincronizacaoService;

$opcoes  = getopt('', ['limite::', 'simular', 'ajuda']);
$simular = array_key_exists('simular', $opcoes);
$limite  = (int) ($opcoes['limite'] ?? 10);

if (array_key_exists('ajuda', $opcoes)) {
    echo "Uso: php scripts/sincronizar-status.php [--limite=N] [--simular]\n\n";
    echo "  --limite=N   quantos registros reconsultar nesta passada (teto de "
        . SincronizacaoService::LIMITE_MAXIMO . ")\n";
    echo "  --simular    não chama a API e não grava: só mostra quem entraria na fila\n";
    exit(0);
}

if ($limite < 1) {
    exit("O limite precisa ser pelo menos 1.\n");
}

printf(
    "\e[1mSincronização de situação no CREA\e[0m%s\n",
    $simular ? "  \e[33m(simulação: nenhuma chamada à API, nada gravado)\e[0m" : '',
);

$relatorio = (new SincronizacaoService())->executar($limite, $simular);

printf(
    "\nIntervalo de reconsulta: %dh · candidatos nesta passada: %d (teto pedido: %d)\n\n",
    $relatorio['intervalo_horas'],
    $relatorio['candidatos'],
    $limite,
);

if ($relatorio['linhas'] === []) {
    echo "  Ninguém venceu o intervalo. Nada a fazer.\n\n";
}

foreach ($relatorio['linhas'] as $linha) {
    printf("  %-34s %s\n", mb_substr($linha['nome'], 0, 34), $linha['resultado']);
}

printf(
    "\n%s  consultados: %d · atualizados: %d · visibilidade fechada: %d\n",
    $simular ? "\e[33mSIMULAÇÃO\e[0m" : "\e[32mSINCRONIZADO\e[0m",
    $relatorio['consultados'],
    $relatorio['atualizados'],
    $relatorio['suspensos'],
);

printf(
    "Pulados: %d sem consentimento · %d sem CPF guardado · %d sem registro na API\n",
    $relatorio['sem_consentimento'],
    $relatorio['sem_documento'],
    $relatorio['sem_registro'],
);

if ($relatorio['interrompido'] !== null) {
    printf(
        "\n\e[31mInterrompido\e[0m: %s\n"
        . "A fila para na primeira indisponibilidade. Rode de novo quando a API responder.\n",
        $relatorio['interrompido'],
    );

    exit(1);
}

exit(0);
