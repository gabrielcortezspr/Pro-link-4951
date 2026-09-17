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

    // ------------------------------------------------------------ o fundo do palco
    /*
     * A malha em perspectiva que ganha volume: o plano XY dos mockups com os prismas do eixo Z
     * por cima. Vem de docs/mockups/prolink-feed-imersivo-v3.html e é o que sustenta a sangria
     * total do palco: sem ela sobram duas faixas vazias ao lado do card.
     *
     * Desenho único por medida, sem animação: não há laço de quadro, e trocar de candidato não
     * redesenha nada. Redesenha ao redimensionar e ao recolher a barra lateral, que são as duas
     * coisas que mudam o tamanho do palco.
     */
    var motivo = raiz.querySelector('[data-motivo]');

    function desenharMotivo() {
        if (!motivo || !motivo.getContext) {
            return;
        }

        var largura = raiz.clientWidth;
        var altura  = raiz.clientHeight;

        // Escondida pela consulta de mídia em 390px: sem medida não há o que desenhar.
        if (!largura || !altura || motivo.offsetParent === null) {
            return;
        }

        var densidade = Math.min(window.devicePixelRatio || 1, 2);

        motivo.width  = Math.round(largura * densidade);
        motivo.height = Math.round(altura * densidade);

        var g = motivo.getContext('2d');

        g.setTransform(densidade, 0, 0, densidade, 0, 0);
        g.clearRect(0, 0, largura, altura);

        var fugaX = largura * 0.5;
        var fugaY = altura * 0.24;
        var base  = altura * 1.06;
        var TINTA = '11,42,74';

        // t é profundidade (0 no horizonte, 1 na frente), u é o afastamento lateral.
        function ponto(t, u) {
            var y = fugaY + (base - fugaY) * Math.pow(t, 2.5);
            var s = (y - fugaY) / (base - fugaY);

            return { x: fugaX + u * largura * 0.95 * s, y: y };
        }

        function linha(a, b) {
            g.beginPath();
            g.moveTo(a.x, a.y);
            g.lineTo(b.x, b.y);
            g.stroke();
        }

        function contorno(pontos) {
            g.beginPath();
            g.moveTo(pontos[0].x, pontos[0].y);
            pontos.slice(1).forEach(function (p) { g.lineTo(p.x, p.y); });
            g.closePath();
        }

        g.lineWidth = 1;

        for (var r = 1; r <= 18; r += 1) {
            var t = r / 18;

            g.strokeStyle = 'rgba(' + TINTA + ',' + (0.010 + 0.038 * t).toFixed(3) + ')';
            linha(ponto(t, -1.7), ponto(t, 1.7));
        }

        g.strokeStyle = 'rgba(' + TINTA + ',0.030)';

        for (var c = -9; c <= 9; c += 1) {
            linha(ponto(0.035, c / 5), ponto(1, c / 5));
        }

        // O plano vira volume: é a terceira dimensão que esta tela inteira defende.
        [
            { t1: 0.50, t2: 0.68, u1: -1.44, u2: -1.02 },
            { t1: 0.62, t2: 0.84, u1: 1.06,  u2: 1.52 },
            { t1: 0.30, t2: 0.40, u1: 0.74,  u2: 1.00 }
        ].forEach(function (prisma) {
            var chao = [
                ponto(prisma.t1, prisma.u1),
                ponto(prisma.t1, prisma.u2),
                ponto(prisma.t2, prisma.u2),
                ponto(prisma.t2, prisma.u1)
            ];
            var alto = (chao[3].y - chao[0].y) * 2.7;
            var teto = chao.map(function (p) { return { x: p.x, y: p.y - alto }; });

            g.strokeStyle = 'rgba(' + TINTA + ',0.058)';
            contorno(chao);
            g.stroke();

            contorno(teto);
            g.stroke();
            g.fillStyle = 'rgba(' + TINTA + ',0.016)';
            g.fill();

            chao.forEach(function (p, k) { linha(p, teto[k]); });
        });
    }

    desenharMotivo();
    window.addEventListener('load', desenharMotivo);

    // Recolher a barra lateral muda a largura do palco, e a transição do shell dura 220ms.
    var colapsar = document.querySelector('[data-pl-colapsar]');

    if (colapsar) {
        colapsar.addEventListener('click', function () {
            window.setTimeout(desenharMotivo, 260);
        });
    }

    var remedirMotivo;

    window.addEventListener('resize', function () {
        window.clearTimeout(remedirMotivo);
        remedirMotivo = window.setTimeout(desenharMotivo, 150);
    });

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
     * A roda percorre o pool quando a página já chegou ao fim daquele sentido. Sequestrar a
     * rolagem enquanto o card ainda tem conteúdo abaixo da dobra prenderia quem está lendo a
     * evidência, e numa demonstração ao vivo isso é pior do que não ter o gesto.
     *
     * A primeira versão só ligava a roda se a página inteira coubesse na janela, e com o palco
     * dentro do shell isso quase nunca acontece: o card real tem seis dimensões em dois grupos,
     * não as quatro do mockup. A dica da tela promete "role para navegar", e o limite de
     * rolagem é o ponto em que a promessa pode ser cumprida sem atrapalhar a leitura.
     */
    var travaDaRoda = 0;

    raiz.addEventListener('wheel', function (evento) {
        var documento = document.documentElement;
        var doTopo    = window.scrollY || documento.scrollTop || 0;
        var aSobrar   = documento.scrollHeight - window.innerHeight - doTopo;

        if (evento.deltaY > 0 ? aSobrar > 2 : doTopo > 2) {
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
