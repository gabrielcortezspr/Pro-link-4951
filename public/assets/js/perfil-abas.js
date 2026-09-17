/**
 * As abas do perfil, em melhoria progressiva.
 *
 * O conteúdo das abas NÃO depende deste arquivo. Sem script, `:target` na seção 38 do
 * prolink.css abre o painel endereçado e acende o segmento, e as abas continuam navegáveis por
 * teclado porque são links dentro de um <nav>. Com script, três coisas que o CSS não entrega:
 *
 *  1. O segmento aceso na carga direta. `/perfil#experiencia` digitado, colado ou aberto pela
 *     suíte mostrava o painel certo com o PRIMEIRO segmento aceso: o Chromium não reavalia o
 *     `:has()` do ancestral quando o alvo do fragmento é definido na carga do documento, só
 *     quando ele muda por clique. Painel certo com aba errada acesa é pior que aba nenhuma.
 *  2. O padrão de abas de verdade: role="tablist", aria-selected, foco itinerante e seta que
 *     anda entre as abas. Isso é escrito AQUI, e não no Twig, porque sem script não há seta que
 *     ande: anunciar um widget que a tela não cumpre é pior do que anunciar o que ela é.
 *  3. A página parada no lugar. Navegação por fragmento rola até o painel; aqui a posição é
 *     devolvida, e trocar de aba deixa de sacudir a tela.
 *
 * Arquivo externo, e não script embutido: a CSP recusa inline (docker/nginx/default.conf).
 */
(function () {
    'use strict';

    var area = document.querySelector('.pl-seg-area');
    if (!area) {
        return;
    }

    var nav = area.querySelector('.pl-seg');
    var abas = Array.prototype.slice.call(area.querySelectorAll('.pl-seg > .pl-seg-b'));
    var paineis = Array.prototype.slice.call(area.querySelectorAll(':scope > .pl-seg-painel'));

    if (!nav || abas.length < 2 || paineis.length < 2) {
        return;
    }

    /** Painel de cada aba, casado pelo fragmento. Aba sem painel sai da conta. */
    var pares = [];

    abas.forEach(function (aba) {
        var alvo = (aba.getAttribute('href') || '').slice(1);
        var painel = null;

        paineis.forEach(function (candidato) {
            if (candidato.id === alvo) {
                painel = candidato;
            }
        });

        if (painel) {
            pares.push({ aba: aba, painel: painel, id: alvo });
        }
    });

    if (pares.length < 2) {
        return;
    }

    // A partir daqui quem manda no que aparece é este arquivo, e não o `:target`.
    document.documentElement.classList.add('js-seg');

    nav.setAttribute('role', 'tablist');

    pares.forEach(function (par) {
        par.aba.id = 'aba-' + par.id;
        par.aba.setAttribute('role', 'tab');
        par.aba.setAttribute('aria-controls', par.id);
        par.painel.setAttribute('role', 'tabpanel');
        par.painel.setAttribute('aria-labelledby', par.aba.id);
        // O painel recebe foco para quem chega nele pela aba: sem isto, o Tab depois de escolher
        // uma aba pula direto para o primeiro campo, sem passar pelo conteúdo que ele revelou.
        par.painel.setAttribute('tabindex', '0');
    });

    /** Índice da aba que o endereço pede. Fragmento de outra coisa (o pular para o conteúdo, por
     *  exemplo) não muda de aba: cai na primeira, que é a mesma regra do CSS. */
    function doEndereco() {
        var frag = (window.location.hash || '').slice(1);
        var achado = 0;

        pares.forEach(function (par, i) {
            if (par.id === frag) {
                achado = i;
            }
        });

        return achado;
    }

    function aplicar(indice) {
        pares.forEach(function (par, i) {
            var aqui = i === indice;

            par.aba.classList.toggle('aqui', aqui);
            par.aba.setAttribute('aria-selected', aqui ? 'true' : 'false');
            // Foco itinerante: a fileira inteira é UMA parada de tabulação, e as setas andam
            // dentro dela. É o que o padrão de abas manda, e é o que evita cinco paradas de Tab
            // antes de chegar ao conteúdo.
            par.aba.setAttribute('tabindex', aqui ? '0' : '-1');
            par.painel.classList.toggle('aberto', aqui);
        });
    }

    var posicao = null;

    nav.addEventListener('click', function (evento) {
        if (evento.target.closest && evento.target.closest('.pl-seg-b')) {
            posicao = window.scrollY;
        }
    });

    window.addEventListener('hashchange', function () {
        aplicar(doEndereco());

        if (posicao !== null) {
            var y = posicao;
            posicao = null;
            window.scrollTo(0, y);
        }
    });

    nav.addEventListener('keydown', function (evento) {
        var atual = -1;

        pares.forEach(function (par, i) {
            if (par.aba === document.activeElement) {
                atual = i;
            }
        });

        if (atual < 0) {
            return;
        }

        var destino = null;

        if (evento.key === 'ArrowRight' || evento.key === 'ArrowDown') {
            destino = (atual + 1) % pares.length;
        } else if (evento.key === 'ArrowLeft' || evento.key === 'ArrowUp') {
            destino = (atual - 1 + pares.length) % pares.length;
        } else if (evento.key === 'Home') {
            destino = 0;
        } else if (evento.key === 'End') {
            destino = pares.length - 1;
        } else {
            return;
        }

        evento.preventDefault();
        posicao = window.scrollY;
        // O endereço é parte da aba: a seta troca de aba E de endereço, senão a URL passa a
        // mentir sobre o que está na tela.
        window.location.hash = '#' + pares[destino].id;
        aplicar(destino);
        pares[destino].aba.focus({ preventScroll: true });
    });

    aplicar(doEndereco());
})();
