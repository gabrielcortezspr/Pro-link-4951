/**
 * Feed de compatíveis: um perfil por vez, navegação vertical.
 *
 * Melhoria progressiva, e não aplicação em JavaScript. O servidor manda todos os cards do pool
 * renderizados e visíveis, na ordem sorteada pela semente da sessão; este arquivo só estreita a
 * pilha para um card por vez e liga a revelação da evidência. Sem ele a tela continua inteira:
 * a pilha rola, a evidência fica aberta e o trilho de navegação nunca aparece.
 *
 * Arquivo externo por causa da CSP (`script-src 'self'`), como `perfil-abrangencia.js` e
 * `confirmar-acao.js`. Handler embutido é recusado do mesmo jeito que `<script>` inline, e o
 * bloqueio não aparece na tela, só no console.
 *
 * O que este arquivo não faz, de propósito: não ordena, não numera e não calcula nada. A ordem
 * vem do servidor e é sorteada; o indicador do trilho é contínuo e sem número porque posição
 * numa lista sorteada leria como colocação, e o item 10.1 do edital veda ranking.
 */
(function () {
    'use strict';

    var raiz = document.querySelector('[data-feed]');

    if (!raiz) {
        return;
    }

    var feed = raiz.closest('.pl-feed');

    if (feed) {
        feed.classList.add('is-js');
    }

    // ------------------------------------------------------------ revelação da evidência
    Array.prototype.forEach.call(raiz.querySelectorAll('.pl-toggle'), function (botao) {
        var alvo = document.getElementById(botao.getAttribute('aria-controls') || '');

        if (!alvo) {
            return;
        }

        botao.addEventListener('click', function () {
            var aberto = botao.getAttribute('aria-expanded') === 'true';

            botao.setAttribute('aria-expanded', aberto ? 'false' : 'true');
            alvo.hidden = aberto;
        });
    });

    // ------------------------------------------------------------ palco
    var cartoes = Array.prototype.slice.call(raiz.querySelectorAll('[data-cartao]'));

    // Pool vazio (só o quadro de fim) não tem o que percorrer: fica a tela estática.
    if (cartoes.length < 2) {
        return;
    }

    var trilho     = raiz.querySelector('.pl-rail');
    var dica       = raiz.querySelector('.pl-hint');
    var espia      = raiz.querySelector('[data-proximo]');
    var anterior   = raiz.querySelector('[data-anterior]');
    var proxima    = raiz.querySelector('[data-proxima]');
    var indicador  = raiz.querySelector('[data-indicador]');
    var progresso  = raiz.querySelector('.pl-progress');
    var anuncio    = raiz.querySelector('[data-anuncio]');
    var reiniciar  = raiz.querySelector('[data-reiniciar]');
    var atual      = 0;

    [trilho, dica, reiniciar].forEach(function (elemento) {
        if (elemento) {
            elemento.hidden = false;
        }
    });

    function nomeCurto(nome) {
        var partes = (nome || '').trim().split(/\s+/);

        return partes.length > 1 ? partes[0] + ' ' + partes[partes.length - 1] : partes[0];
    }

    function pintarIndicador() {
        if (!indicador || !progresso) {
            return;
        }

        var altura = progresso.clientHeight || 120;
        var passo  = Math.max(14, Math.round(altura / cartoes.length));
        var topo   = cartoes.length > 1
            ? Math.round((altura - passo) * (atual / (cartoes.length - 1)))
            : 0;

        indicador.style.height = passo + 'px';
        indicador.style.transform = 'translateY(' + topo + 'px)';
    }

    function fecharRevelacao(cartao) {
        var botao = cartao.querySelector('.pl-toggle');
        var alvo  = botao && document.getElementById(botao.getAttribute('aria-controls') || '');

        if (botao && alvo) {
            botao.setAttribute('aria-expanded', 'false');
            alvo.hidden = true;
        }
    }

    function pintarEspia() {
        if (!espia) {
            return;
        }

        var seguinte = cartoes[atual + 1];
        var nome     = seguinte ? seguinte.getAttribute('data-nome') : null;

        // O quadro de fim não tem nome: a silhueta some no último perfil em vez de anunciar
        // "o próximo" que não existe.
        if (!nome) {
            espia.hidden = true;

            return;
        }

        espia.hidden = false;
        espia.querySelector('.pav').textContent = seguinte.getAttribute('data-iniciais') || '';
        espia.querySelector('.pn').textContent = nomeCurto(nome);
    }

    function mostrar(alvo, direcao) {
        if (alvo < 0 || alvo >= cartoes.length || alvo === atual) {
            return;
        }

        fecharRevelacao(cartoes[atual]);
        cartoes[atual].hidden = true;
        atual = alvo;

        var cartao = cartoes[atual];

        cartao.hidden = false;
        cartao.classList.remove('is-entrando', 'de-cima');
        // Reinicia a animação: sem a leitura forçada o navegador agrupa remover e adicionar.
        void cartao.offsetWidth;
        cartao.classList.add('is-entrando');

        if (direcao < 0) {
            cartao.classList.add('de-cima');
        }

        if (anterior) {
            anterior.disabled = atual === 0;
        }

        if (proxima) {
            proxima.disabled = atual === cartoes.length - 1;
        }

        pintarEspia();
        pintarIndicador();

        if (anuncio) {
            var nome = cartao.getAttribute('data-nome');

            anuncio.textContent = nome
                ? nome + ', em foco no feed.'
                : 'Fim da lista de perfis compatíveis desta demanda.';
        }

        if (raiz.getBoundingClientRect().top < 0) {
            raiz.scrollIntoView({ block: 'start' });
        }
    }

    cartoes.forEach(function (cartao, indice) {
        cartao.hidden = indice !== 0;
    });

    if (anterior) {
        anterior.disabled = true;
        anterior.addEventListener('click', function () {
            mostrar(atual - 1, -1);
        });
    }

    if (proxima) {
        proxima.addEventListener('click', function () {
            mostrar(atual + 1, 1);
        });
    }

    if (espia) {
        espia.addEventListener('click', function () {
            mostrar(atual + 1, 1);
        });
    }

    if (reiniciar) {
        reiniciar.addEventListener('click', function () {
            mostrar(0, -1);
        });
    }

    document.addEventListener('keydown', function (evento) {
        var etiqueta = (evento.target.tagName || '').toLowerCase();

        if (etiqueta === 'input' || etiqueta === 'textarea' || etiqueta === 'select') {
            return;
        }

        if (evento.key === 'ArrowDown') {
            evento.preventDefault();
            mostrar(atual + 1, 1);
        } else if (evento.key === 'ArrowUp') {
            evento.preventDefault();
            mostrar(atual - 1, -1);
        }
    });

    /*
     * A roda só percorre o pool quando não há mais nada para rolar na página. Sequestrar a
     * rolagem enquanto o card ainda tem conteúdo abaixo da dobra prenderia quem está lendo a
     * evidência — e numa demonstração ao vivo isso é pior do que não ter o gesto.
     */
    var travaDaRoda = 0;

    raiz.addEventListener('wheel', function (evento) {
        if (document.documentElement.scrollHeight > window.innerHeight + 2) {
            return;
        }

        if (Math.abs(evento.deltaY) < 8) {
            return;
        }

        evento.preventDefault();

        var agora = Date.now();

        if (agora < travaDaRoda) {
            return;
        }

        travaDaRoda = agora + 520;
        mostrar(atual + (evento.deltaY > 0 ? 1 : -1), evento.deltaY > 0 ? 1 : -1);
    }, { passive: false });

    var redimensionar;

    window.addEventListener('resize', function () {
        window.clearTimeout(redimensionar);
        redimensionar = window.setTimeout(pintarIndicador, 150);
    });

    pintarEspia();
    pintarIndicador();
})();
