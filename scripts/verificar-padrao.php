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

// ---------------------------------------------------------------- CSP × origens dos templates
//
// A regra "nenhuma dependência de front nova" do CLAUDE.md só é verificável se alguém souber
// quais origens externas a aplicação carrega hoje. E a CSP do nginx precisa listar exatamente
// essas, nem mais nem menos: uma origem de menos serve a aplicação sem estilo e o navegador não
// avisa — bloqueio de CSP só aparece no console, e ninguém abre console na apresentação.
//
// Esta verificação existe porque o erro já aconteceu: uma CSP escrita por suposição, afirmando
// no próprio comentário que o Bootstrap era local, teria derrubado o CSS de todas as telas.

secao('CSP e origens de front');

// Origem externa, classificada pela diretiva de CSP que a governa. Conferir só se o host
// aparece "em algum lugar" da política não basta: tirar o CDN do script-src e deixá-lo no
// style-src passaria na conferência com o JS do Bootstrap morto na tela.
$origensDosTemplates = [];   // host => ['diretiva' => [...], 'onde' => [...]]

foreach ($templates as $nome => $fonte) {
    $limpo = soTextoDeInterface($fonte);

    $classificar = static function (string $tag, string $host, string $diretiva) use (&$origensDosTemplates, $nome): void {
        $host = strtolower($host);

        $origensDosTemplates[$host]['diretiva'][$diretiva] = true;
        $origensDosTemplates[$host]['onde'][$nome]         = true;
    };

    if (preg_match_all('#<script[^>]*\ssrc\s*=\s*["\']https?://([^/"\']+)#i', $limpo, $achados)) {
        foreach ($achados[1] as $host) {
            $classificar('script', $host, 'script-src');
        }
    }

    if (preg_match_all('#<link[^>]*>#i', $limpo, $links)) {
        foreach ($links[0] as $link) {
            if (!preg_match('#href\s*=\s*["\']https?://([^/"\']+)#i', $link, $h)) {
                continue;
            }

            // preconnect e dns-prefetch são dicas de rede, não carregamento: o recurso que vem
            // delas (o arquivo de fonte) é pedido pelo CSS do Google, e o template não o vê.
            // Para essas basta estar na política; a diretiva certa é font-src e é conferida à mão.
            $diretiva = preg_match('#rel\s*=\s*["\'](?:preconnect|dns-prefetch)["\']#i', $link)
                ? 'qualquer'
                : 'style-src';

            $classificar('link', $h[1], $diretiva);
        }
    }

    // Script embutido: a CSP recusa, e script da aplicação mora em public/assets/js.
    if (preg_match('#<script(?![^>]*\ssrc=)[^>]*>#i', $limpo, $m)) {
        violar($nome, 'script embutido: a CSP recusa inline. Mova para public/assets/js e use src=', $m[0]);
    }
}

$conf = (string) @file_get_contents(dirname(__DIR__) . '/docker/nginx/default.conf');

if (preg_match('/add_header\s+Content-Security-Policy\s+"([^"]+)"/', $conf, $m)) {
    // A política, quebrada por diretiva: 'script-src' => ['cdn.jsdelivr.net', ...]
    $politica = [];

    foreach (explode(';', $m[1]) as $pedaco) {
        $partes = preg_split('/\s+/', trim($pedaco), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if ($partes === []) {
            continue;
        }

        $diretiva = array_shift($partes);

        foreach ($partes as $fonte) {
            if (preg_match('#^https?://([^/]+)$#i', $fonte, $h)) {
                $politica[$diretiva][] = strtolower($h[1]);
            }
        }
    }

    $todasPermitidas = array_unique(array_merge(...array_values($politica) ?: [[]]));

    foreach ($origensDosTemplates as $host => $uso) {
        $onde = implode(', ', array_keys($uso['onde']));

        foreach (array_keys($uso['diretiva']) as $diretiva) {
            $permitida = $diretiva === 'qualquer'
                ? in_array($host, $todasPermitidas, true)
                : in_array($host, $politica[$diretiva] ?? [], true);

            if (!$permitida) {
                violar(
                    $onde,
                    $diretiva === 'qualquer'
                        ? 'origem ausente da CSP inteira: o recurso seria bloqueado sem aviso na tela'
                        : sprintf('origem ausente de %s na CSP: o recurso seria bloqueado sem aviso na tela', $diretiva),
                    $host,
                );
            }
        }
    }

    foreach ($todasPermitidas as $host) {
        if (!isset($origensDosTemplates[$host])) {
            violar(
                'docker/nginx/default.conf',
                'CSP permite origem que nenhum template usa: sobrou da versão anterior, ou a tela que a usava saiu',
                $host,
            );
        }
    }

    $conferido++;

    foreach ($origensDosTemplates as $host => $uso) {
        printf("  \e[2m%-26s %s\e[0m\n", $host, implode(' + ', array_keys($uso['diretiva'])));
    }
} else {
    violar('docker/nginx/default.conf', 'sem Content-Security-Policy: a conferência de origens não roda', '');
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

    // 6. Código TOS cru. `tos_codigo` vem da API como `TOS_1.1.2.3`, e o design (mockups) mostra
    //    `TOS 1.1.2.3`, sem o sublinhado. Conferido **antes** e à parte da regra da constante
    //    porque a regra genérica o pegava por acidente e só às vezes: `TOS_11.1` casa com
    //    `[A-Z][A-Z0-9]{2,}_`, `TOS_1.1` não. A mesma falha passava ou não conforme o grupo do
    //    código de amostra ter um ou dois dígitos — e a mensagem que saía falava de constante de
    //    sistema, mandando quem lesse procurar a coisa errada.
    if (preg_match('/\bTOS_\d/', $visivel, $m)) {
        violar($tela, 'código TOS cru: a tela mostra "TOS 1.1.2.3", sem o sublinhado da API', $m[0]);
    }

    // 7. Constante de sistema vazando: ACESSO_NEGADO, PERFIL_FRAUDULENTO.
    //    Exige 3+ letras antes do sublinhado, então EM_ANALISE escapa — está aqui escrito
    //    porque o exemplo anterior citava justamente esse caso, que a regra nunca pegou.
    //    Exclui o código TOS, que a regra acima já cobre com a mensagem certa.
    if (preg_match('/\b(?!TOS_\d)[A-Z][A-Z0-9]{2,}_[A-Z0-9_]{2,}\b/', $visivel, $m)) {
        violar($tela, 'constante de sistema visível: passe por rótulo (Support\Rotulos)', $m[0]);
    }

    // 8. Nome de tabela ou de coluna vazando. pro_denuncias, usu_status.
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
