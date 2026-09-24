/**
 * O campo "Início" da demanda (D78): "Prazo em aberto" ou "Até" uma data.
 *
 * Melhoria progressiva. O HTML já chega certo (a data vem desabilitada quando o prazo está em
 * aberto) e o servidor valida a data de qualquer jeito. Aqui só o que o HTML sozinho não faz:
 * escolher "Até" libera a data e a exige; voltar para "Prazo em aberto" desabilita a data, e o
 * navegador deixa de enviá-la, então ninguém grava um prazo que desmarcou; e digitar uma data
 * marca "Até" sozinho, que é o que a pessoa quis dizer.
 *
 * Sem script, a data nasce habilitada (ver `inicio_ate` em layout/_form.html.twig), para quem
 * precisa de prazo conseguir informá-lo.
 *
 * Arquivo externo, e não script embutido, porque a CSP recusa inline.
 */
(function () {
    'use strict';

    document.querySelectorAll('[data-inicio]').forEach(function (grupo) {
        var aberto = grupo.querySelector('[data-inicio-aberto]');
        var ate = grupo.querySelector('[data-inicio-ate]');
        var data = grupo.querySelector('input[type="date"]');

        if (!aberto || !ate || !data) {
            return;
        }

        function aplicar() {
            data.disabled = aberto.checked;
            data.required = ate.checked;
        }

        aberto.addEventListener('change', aplicar);
        ate.addEventListener('change', function () {
            aplicar();
            if (ate.checked) {
                data.focus();
            }
        });
        // O rótulo da data é o próprio "Até": clicar nela com o prazo em aberto escolhe "Até".
        data.addEventListener('input', function () {
            if (data.value && !ate.checked) {
                ate.checked = true;
                aplicar();
            }
        });

        aplicar();
    });
})();
