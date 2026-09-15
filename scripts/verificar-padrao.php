<?php

declare(strict_types=1);

/**
 * Verificação do padrão visual: o que dá para conferir por máquina, antes de gastar olho humano.
 *
 * Existe porque a régua de qualidade da interface estava só na cabeça de quem revisava, e tela
 * escrita às pressas passava. As regras abaixo são as que vinham sendo cobradas repetidamente na
 * revisão. Nenhuma delas julga estética: julgam vazamento de forma interna, ruído e inconsistência
 * com o design system. Bonito continua sendo trabalho de gente, e por isso tela nova passa pelo
 * agente designer-ui antes de chegar aqui.
 *
 * Parte estática: lê todo template.
 * Parte renderizada: monta as telas de scripts/amostras.php e lê o HTML de saída, que é onde o
 * identificador cru aparece de verdade. Template limpo pode render sujo.
 *
 * Uso: docker compose exec php php scripts/verificar-padrao.php
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Support\View;

$violacoes = 0;
$conferido = 0;

function violar(string $arquivo, string $regra, string $trecho): void
{
    global $violacoes;

    $violacoes++;
    printf("  \e[31m×\e[0m %s\n    %s\n    %s\n", $arquivo, $regra, trim($trecho));
}

function secao(string $titulo): void
{
    printf("\n\e[1m%s\e[0m\n", $titulo);
}

/** Remove comentário Twig e comentário HTML: regra de interface vale para o que a pessoa lê. */
function soTextoDeInterface(string $fonte): string
{
    $fonte = (string) preg_replace('/\{#.*?#\}/s', '', $fonte);

    return (string) preg_replace('/<!--.*?-->/s', '', $fonte);
}

$templates = [];
$iterador  = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(PATH_TEMPLATES, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterador as $arquivo) {
    if ($arquivo->isFile() && str_ends_with($arquivo->getFilename(), '.twig')) {
        $templates[ltrim(str_replace(PATH_TEMPLATES, '', $arquivo->getPathname()), '/')]
            = (string) file_get_contents($arquivo->getPathname());
    }
}

ksort($templates);

secao('Texto dos templates');

foreach ($templates as $nome => $fonte) {
    $texto = soTextoDeInterface($fonte);
    $conferido++;

    // 1. Emoji. Ícone é SVG monocromático ou nada.
    if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', $texto, $m)) {
        violar($nome, 'emoji na interface: use SVG monocromático ou texto', $m[0]);
    }

    // 2. Travessão. Substituir por pontuação equivalente (dois-pontos, vírgula, ponto).
    if (str_contains($texto, '—')) {
        $linha = '';

        foreach (explode("\n", $texto) as $l) {
            if (str_contains($l, '—')) {
                $linha = $l;
                break;
            }
        }

        violar($nome, 'travessão em texto de interface: troque por pontuação equivalente', $linha);
    }

    // 3. Cor escrita à mão. A paleta são os tokens da seção 1 do prolink.css. Corpo de e-mail é
    // a exceção real: cliente de e-mail ignora folha externa e variável CSS, então lá o estilo
    // embutido é a única coisa que funciona.
    if (!str_starts_with($nome, 'email/')
        && preg_match('/style="[^"]*(#[0-9a-fA-F]{3,8}|rgb\()/', $texto, $m)) {
        violar($nome, 'cor fora dos tokens: use var(--pl-*) numa classe, não style inline', $m[0]);
    }

    // 4. Tabela crua. A tabela do projeto é .pl-table (seção 8).
    if (preg_match('/<table(?![^>]*pl-table)[^>]*>/', $texto, $m)) {
        violar($nome, 'tabela fora do design system: use class="pl-table"', $m[0]);
    }

    // 5. Filtro de pacote ausente. twig/string-extra não está instalado.
    if (preg_match('/\|\s*u\.[a-z]/', $texto, $m)) {
        violar($nome, 'filtro de twig/string-extra, que não é dependência do projeto', $m[0]);
    }
}

secao('HTML renderizado');

/**
 * A parte renderizada precisa do banco: `amostras.php` monta as telas com dado de verdade, que é
 * justamente o ponto — template limpo pode render sujo.
 *
 * Banco parado é o estado normal de quem acabou de abrir a sessão, e até aqui isso despejava três
 * exceções encadeadas e um stack trace no lugar do relatório. Agora a parte estática vale por si,
 * e a renderizada diz o que fazer para rodar. O código de saída distingue os dois casos: 0 quando
 * conferiu tudo, 2 quando conferiu só metade — assim o `/encerrar` e o hook não leem "verde" onde
 * houve meia verificação.
 *
 * @var array<string, array<string, mixed>>|null $amostras
 */
$amostras = null;

try {
    $amostras = require __DIR__ . '/amostras.php';
} catch (Throwable $e) {
    printf(
        "\n\e[33m!\e[0m Banco indisponível: a verificação do HTML renderizado não rodou.\n"
        . "  %s\n"
        . "  Suba o ambiente e repita:  docker compose up -d && docker compose exec php php %s\n",
        $e->getMessage(),
        'scripts/' . basename(__FILE__),
    );
}

if ($amostras === null) {
    printf(
        "\n%s  %d conferências estáticas, %d violações · HTML renderizado não conferido\n",
        $violacoes === 0 ? "\e[33mPADRÃO VISUAL PARCIAL\e[0m" : "\e[31mPADRÃO VISUAL VIOLADO\e[0m",
        $conferido,
        $violacoes,
    );

    exit($violacoes === 0 ? 2 : 1);
}

foreach ($amostras as $tela => $dados) {
    try {
        $html = View::render($tela, $dados);
    } catch (Throwable $e) {
        violar($tela, 'não renderiza com dado de amostra', $e->getMessage());
        continue;
    }

    $conferido++;

    // Só o texto visível: value="", title="" e class="" carregam o identificador cru de propósito.
    $visivel = (string) preg_replace('/<[^>]*>/', ' ', $html);

    // 6. Constante de sistema vazando. ACESSO_NEGADO, EM_ANALISE, PERFIL_FRAUDULENTO.
    if (preg_match('/\b[A-Z][A-Z0-9]{2,}_[A-Z0-9_]{2,}\b/', $visivel, $m)) {
        violar($tela, 'constante de sistema visível: passe por rótulo (Support\Rotulos)', $m[0]);
    }

    // 7. Nome de tabela ou de coluna vazando. pro_denuncias, usu_status.
    if (preg_match('/\b(sis|pro|crea|mat)_[a-z_]{3,}\b/', $visivel, $m)) {
        violar($tela, 'nome de tabela visível: use |rotulo_entidade', $m[0]);
    }

    if (preg_match('/\b(usu|den|emp|prf|art|cat|dem|man)_[a-z_]{3,}\b/', $visivel, $m)) {
        violar($tela, 'nome de coluna visível: use |rotulo_campo', $m[0]);
    }

    // 8. Emoji e travessão de novo, agora no que saiu: podem vir de dado ou de include.
    if (preg_match('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', $visivel, $m)) {
        violar($tela, 'emoji no HTML final', $m[0]);
    }

    if (str_contains($visivel, '—')) {
        violar($tela, 'travessão no HTML final', '—');
    }
}

$naoCobertas = array_diff(array_keys($templates), array_keys($amostras));
$parciais    = [];

foreach ($naoCobertas as $nome) {
    // Fragmento e template de e-mail não são tela: não entram na conta do que falta cobrir.
    if (!str_starts_with($nome, 'layout/') && !str_starts_with($nome, 'email/')) {
        $parciais[] = $nome;
    }
}

if ($parciais !== []) {
    printf(
        "\n\e[33m!\e[0m %d tela(s) sem amostra, conferidas só no texto: %s\n"
        . "  Acrescente em scripts/amostras.php para valer a verificação do HTML.\n",
        count($parciais),
        implode(', ', $parciais),
    );
}

printf(
    "\n%s  %d conferências, %d violações\n",
    $violacoes === 0 ? "\e[32mPADRÃO VISUAL OK\e[0m" : "\e[31mPADRÃO VISUAL VIOLADO\e[0m",
    $conferido,
    $violacoes,
);

exit($violacoes === 0 ? 0 : 1);
