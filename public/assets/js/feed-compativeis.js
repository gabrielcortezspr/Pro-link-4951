/**
 * Feed de compatíveis: o modo palco, um perfil por vez.
 *
 * Melhoria progressiva. O servidor entrega todos os cards em lista, que é a tela válida por si:
 * sem este arquivo nada some, nada quebra, e quem tem 40 perfis no resultado continua lendo os 40.
 * O que o script acrescenta é o modo palco (um card em foco, com passagem para o próximo) e o
 * alternador entre os dois modos, que nasce escondido no HTML justamente para não prometer uma
 * ação que só existe com script.
 *
 * Duas decisões que não são detalhe:
 *
 * A roda do mouse NÃO é capturada. No mockup o palco ocupava o quadro inteiro; aqui a página
 * continua abaixo, com o quadro de procedência da execução, e sequestrar a rolagem prenderia o
 * leitor antes dele. A passagem é por botão, por teclado e pela silhueta do próximo.
 *
 * Nada aqui numera, ordena ou pontua card: a ordem veio da semente gravada na sessão e o item
 * 10.1 do edital veda ranking de profissionais. O trilho de progresso é contínuo e sem número de
 * posição de propósito.
 *
 * Arquivo externo, e não <script> no template, porque a CSP recusa inline.
 */
(function () {
    'use strict';

    var palco = document.getElementById('feed-palco');

    if (!palco) {
        return;
    }

    var slides = Array.prototype.slice.call(palco.querySelectorAll('[data-feed-slide]'));

    // Um card só (o fecho, sem ninguém no pool) não tem o que folhear.
    if (slides.length < 2) {
        return;
    }

    var grupoModo = document.getElementById('feed-modo');
    var btPalco = document.getElementById('feed-modo-palco');
    var btLista = document.getElementById('feed-modo-lista');
    var trilho = document.getElementById('feed-rail');
    var anterior = document.getElementById('feed-prev');
    var proximo = document.getElementById('feed-next');
    var progresso = document.getElementById('feed-progresso');
    var polegar = document.getElementById('feed-thumb');
    var espia = document.getElementById('feed-peek');
    var espiaNome = document.getElementById('feed-peek-nome');
    var espiaIniciais = document.getElementById('feed-peek-iniciais');
    var reiniciar = document.getElementById('feed-reiniciar');
    var estado = document.getElementById('feed-status');

    if (!btPalco || !btLista || !trilho || !anterior || !proximo || !espia) {
        return;
    }

    var atual = 0;
    var modo = 'palco';

    function posicionarTrilho() {
        if (!progresso || !polegar) {
            return;
        }

        var altura = progresso.clientHeight || 116;
        var tamanho = Math.max(14, Math.round(altura / slides.length));
        var avanco = slides.length > 1 ? atual / (slides.length - 1) : 0;

        polegar.style.height = tamanho + 'px';
        polegar.style.transform = 'translateY(' + Math.round((altura - tamanho) * avanco) + 'px)';
    }

    function mostrar(indice, moveu) {
        atual = Math.max(0, Math.min(slides.length - 1, indice));

        slides.forEach(function (slide, i) {
            slide.hidden = i !== atual;
            slide.classList.remove('is-entrando');
        });

        var emFoco = slides[atual];

        if (moveu) {
            // Reinicia a animação de entrada: sem o reflow a classe volta no mesmo quadro e o
            // navegador não reexecuta os keyframes.
            void emFoco.offsetWidth;
            emFoco.classList.add('is-entrando');
            emFoco.focus({ preventScroll: true });
        }

        anterior.disabled = atual === 0;
        proximo.disabled = atual === slides.length - 1;

        var seguinte = slides[atual + 1];
        var nomeSeguinte = seguinte ? (seguinte.getAttribute('data-feed-nome') || '') : '';

        espia.hidden = !seguinte;

        if (seguinte) {
            espia.setAttribute(
                'aria-label',
                nomeSeguinte === ''
                    ? 'Ir para o fim do resultado'
                    : 'Ir para o próximo perfil do pool, ' + nomeSeguinte
            );

            if (espiaNome) {
                espiaNome.textContent = nomeSeguinte === '' ? 'Fim do resultado' : nomeSeguinte;
            }

            if (espiaIniciais) {
                espiaIniciais.textContent = seguinte.getAttribute('data-feed-iniciais') || '';
            }
        }

        if (reiniciar) {
            reiniciar.hidden = false;
        }

        posicionarTrilho();

        if (estado && moveu) {
            estado.textContent = emFoco.getAttribute('data-feed-nome')
                || 'Fim do resultado. Você viu todos os perfis desta busca.';
        }
    }

    function aplicarModo(novo, moveu) {
        modo = novo;

        palco.classList.toggle('palco', modo === 'palco');
        palco.classList.toggle('lista', modo !== 'palco');
        btPalco.setAttribute('aria-pressed', modo === 'palco' ? 'true' : 'false');
        btLista.setAttribute('aria-pressed', modo === 'palco' ? 'false' : 'true');
        trilho.hidden = modo !== 'palco';

        if (modo === 'palco') {
            mostrar(atual, moveu);

            return;
        }

        slides.forEach(function (slide) {
            slide.hidden = false;
            slide.classList.remove('is-entrando');
        });

        espia.hidden = true;

        if (reiniciar) {
            reiniciar.hidden = true;
        }
    }

    anterior.addEventListener('click', function () {
        mostrar(atual - 1, true);
    });

    proximo.addEventListener('click', function () {
        mostrar(atual + 1, true);
    });

    espia.addEventListener('click', function () {
        mostrar(atual + 1, true);
    });

    if (reiniciar) {
        reiniciar.addEventListener('click', function () {
            mostrar(0, true);
        });
    }

    btPalco.addEventListener('click', function () {
        aplicarModo('palco', true);
    });

    btLista.addEventListener('click', function () {
        aplicarModo('lista', false);
    });

    // Teclado só enquanto o foco está dentro do palco: seta para cima e para baixo rolam a página
    // em todo o resto do site, e tomá-las globalmente seria sequestrar a navegação de quem nem
    // está olhando para o feed.
    palco.addEventListener('keydown', function (evento) {
        if (modo !== 'palco' || evento.altKey || evento.ctrlKey || evento.metaKey) {
            return;
        }

        var alvo = (evento.target.tagName || '').toLowerCase();

        if (alvo === 'input' || alvo === 'textarea' || alvo === 'select') {
            return;
        }

        if (evento.key === 'ArrowDown') {
            evento.preventDefault();
            mostrar(atual + 1, true);
        } else if (evento.key === 'ArrowUp') {
            evento.preventDefault();
            mostrar(atual - 1, true);
        }
    });

    window.addEventListener('resize', posicionarTrilho);

    if (grupoModo) {
        grupoModo.hidden = false;
    }

    aplicarModo('palco', false);
}());
