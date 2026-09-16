/*
 * Apoio ao "i" de informação (.pl-info). Melhoria progressiva: o componente é <details> nativo e
 * já abre, fecha, recebe foco e é anunciado sem nada disto. O que este arquivo resolve é o que o
 * <details> sozinho não faz e que apareceria numa tela com vários "i": dois painéis abertos ao
 * mesmo tempo, sobrepostos, e nenhum jeito de fechar sem voltar ao ícone.
 *
 * Arquivo externo porque a CSP recusa script embutido (docker/nginx/default.conf).
 */
(function () {
    'use strict';

    function abertos() {
        return Array.prototype.slice.call(document.querySelectorAll('details.pl-info[open]'));
    }

    // 'toggle' não borbulha: a escuta é na fase de captura.
    document.addEventListener('toggle', function (evento) {
        var alvo = evento.target;

        if (!(alvo instanceof Element) || !alvo.matches('details.pl-info') || !alvo.open) {
            return;
        }

        abertos().forEach(function (outro) {
            if (outro !== alvo) {
                outro.open = false;
            }
        });
    }, true);

    // Clique fora fecha. O clique no próprio <summary> chega aqui depois do toggle, e o teste de
    // contenção impede que ele feche justamente o que acabou de abrir.
    document.addEventListener('click', function (evento) {
        abertos().forEach(function (item) {
            if (!item.contains(evento.target)) {
                item.open = false;
            }
        });
    });

    // Esc fecha e devolve o foco ao ícone, para quem navega por teclado não perder o lugar.
    document.addEventListener('keydown', function (evento) {
        if (evento.key !== 'Escape') {
            return;
        }

        abertos().forEach(function (item) {
            item.open = false;

            var gatilho = item.querySelector('summary');

            if (gatilho && item.contains(document.activeElement)) {
                gatilho.focus();
            }
        });
    });
})();
