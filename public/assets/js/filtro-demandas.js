/**
 * Filtro instantâneo do painel de demandas (/demandas).
 *
 * O mockup (docs/mockups/prolink-empresa-1.html, tela 03) põe uma barra de busca acima da tabela.
 * Não existe rota de busca em /demandas, e não precisa existir: a lista é a da própria conta, já
 * veio inteira na página, e filtrar no servidor custaria uma viagem de rede para esconder linhas
 * que já estão na tela.
 *
 * O campo nasce com `hidden` no template e é revelado aqui, pelo mesmo motivo do botão de recolher
 * a barra lateral: sem JavaScript ele não filtraria nada, e campo de busca que não busca é pior
 * que a ausência dele.
 *
 * O termo é comparado sem acento e sem caixa, para a tela responder como o banco responde: a
 * collation `utf8mb4_unicode_ci` do projeto já ignora as duas coisas, e um filtro mais exigente
 * que a busca do servidor confundiria quem usa as duas na mesma sessão.
 */
(function () {
    'use strict';

    var caixa = document.querySelector('[data-filtro-demandas]');
    var corpo = document.querySelector('[data-filtro-alvo]');
    var vazio = document.querySelector('[data-filtro-vazio]');

    if (!caixa || !corpo) {
        return;
    }

    var campo = caixa.querySelector('input');

    if (!campo) {
        return;
    }

    var linhas = [].slice.call(corpo.querySelectorAll('tr'));

    // O quadro de rolagem inteiro, e não só as linhas: com a tabela vazia sobrava a fileira de
    // cabeçalhos pairando sobre o aviso de "nada encontrado", que é rótulo de coluna sem coluna
    // nenhuma embaixo.
    var quadro = corpo.closest('.table-responsive');

    function simplificar(texto) {
        texto = (texto || '').toLowerCase();

        // `normalize` não existe em navegador muito antigo: sem ele o filtro continua funcionando,
        // só passa a exigir o acento digitado.
        if (typeof texto.normalize === 'function') {
            texto = texto.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }

        return texto.replace(/\s+/g, ' ').trim();
    }

    // O texto de cada linha é lido uma vez, e não a cada tecla.
    var indice = linhas.map(function (linha) {
        return simplificar(linha.getAttribute('data-busca') || linha.textContent);
    });

    function aplicar() {
        var termo = simplificar(campo.value);
        var visiveis = 0;

        linhas.forEach(function (linha, i) {
            var bate = termo === '' || indice[i].indexOf(termo) !== -1;

            linha.hidden = !bate;

            if (bate) {
                visiveis++;
            }
        });

        if (vazio) {
            vazio.hidden = visiveis !== 0;
        }

        if (quadro) {
            quadro.hidden = visiveis === 0;
        }
    }

    caixa.hidden = false;
    campo.addEventListener('input', aplicar);
    campo.addEventListener('search', aplicar);
    aplicar();
}());
