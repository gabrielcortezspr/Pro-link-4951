/**
 * Recolher a barra lateral das telas logadas.
 *
 * O botão nasce com `hidden` no template e é revelado aqui: sem JavaScript ele não faria nada, e
 * um botão inerte é pior que a ausência dele. A barra inteira funciona expandida.
 *
 * A escolha fica em localStorage porque a aplicação navega por página inteira: sem guardar, a
 * barra voltaria a abrir a cada clique, e recolher deixaria de ser uma escolha para virar um
 * gesto que se desfaz sozinho. Armazenamento indisponível (modo privado, política do navegador)
 * não é erro: a barra simplesmente volta a abrir.
 */
(function () {
    'use strict';

    var CHAVE = 'prolink:barra-recolhida';

    var app = document.querySelector('.pl-app[data-colapsavel]');

    if (!app) {
        return;
    }

    var botao = app.querySelector('[data-pl-colapsar]');

    if (!botao) {
        return;
    }

    function guardado() {
        try {
            return window.localStorage.getItem(CHAVE) === '1';
        } catch (e) {
            return false;
        }
    }

    function guardar(recolhida) {
        try {
            window.localStorage.setItem(CHAVE, recolhida ? '1' : '0');
        } catch (e) {
            /* sem armazenamento a escolha vale só para esta página */
        }
    }

    function aplicar(recolhida) {
        app.classList.toggle('collapsed', recolhida);
        botao.setAttribute('aria-expanded', recolhida ? 'false' : 'true');
        botao.setAttribute('aria-label', recolhida ? 'Expandir menu' : 'Recolher menu');
    }

    botao.hidden = false;
    aplicar(guardado());

    botao.addEventListener('click', function () {
        var recolhida = !app.classList.contains('collapsed');

        aplicar(recolhida);
        guardar(recolhida);
    });
}());
