<?php

declare(strict_types=1);

/**
 * Compila todo template de templates/ e falha na primeira que não compilar.
 *
 * Existe por causa de um erro real: a tela de auditoria usou o filtro u.truncate, que vem de
 * twig/string-extra e não está instalado. As 16 verificações da E6 passaram, porque testavam
 * repositório e serviço; a tela só quebrou no navegador, em 500. Verificação que não toca a
 * camada de apresentação não prova que a apresentação existe.
 *
 * O Twig resolve filtro, função e tag na compilação, então load() pega essa classe inteira de
 * erro sem precisar de sessão, banco ou requisição HTTP. O que ele não pega é erro de execução
 * (variável ausente sob strict_variables) — isso continua sendo trabalho do teste de fluxo.
 *
 * Uso: docker compose exec php php scripts/verificar-telas.php
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Support\View;

$twig = View::motor();

$arquivos = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(PATH_TEMPLATES, FilesystemIterator::SKIP_DOTS)
);

$nomes = [];

foreach ($arquivos as $arquivo) {
    if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), '.twig')) {
        $nomes[] = ltrim(str_replace(PATH_TEMPLATES, '', $arquivo->getPathname()), '/');
    }
}

sort($nomes);

$falhou = 0;

foreach ($nomes as $nome) {
    try {
        $twig->load($nome);
        printf("  \e[32mok\e[0m    %s\n", $nome);
    } catch (Throwable $e) {
        $falhou++;
        printf("  \e[31mFALHOU\e[0m %s\n        %s\n", $nome, $e->getMessage());
    }
}

printf(
    "\n%s  %d telas, %d falharam\n",
    $falhou === 0 ? "\e[32mTELAS COMPILAM\e[0m" : "\e[31mTELA COM ERRO\e[0m",
    count($nomes),
    $falhou,
);

exit($falhou === 0 ? 0 : 1);
