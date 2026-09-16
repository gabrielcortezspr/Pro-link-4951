/**
 * Confirmação antes de ação destrutiva, para qualquer formulário com `data-confirmar`.
 *
 * Existe por causa da CSP: estas confirmações eram `onsubmit="return confirm(...)"` escrito no
 * template, e `script-src 'self'` recusa handler embutido tanto quanto recusa `<script>` inline.
 * O atributo continuava lá, o navegador ignorava em silêncio, e as duas ações destrutivas da
 * aplicação — remover experiência e encerrar demanda — passaram a executar sem perguntar nada.
 * Bloqueio de CSP não dá erro visível na tela, só no console, e foi assim que isso passou.
 *
 * Escutador delegado no documento, e carregado pelo `base.html.twig`, não por tela: formulário
 * novo só precisa declarar `data-confirmar="pergunta"` e já nasce protegido. Se dependesse de
 * lembrar de incluir o arquivo, a falha de esquecer seria de novo a confirmação sumir calada.
 *
 * O servidor não confia nisto. `confirm()` é cortesia de interface; quem barra de fato é o CSRF
 * do formulário e a checagem de dono no controller.
 */
(function () {
    'use strict';

    document.addEventListener('submit', function (evento) {
        var formulario = evento.target;

        if (!(formulario instanceof HTMLFormElement)) {
            return;
        }

        var pergunta = formulario.getAttribute('data-confirmar');

        if (!pergunta) {
            return;
        }

        if (!window.confirm(pergunta)) {
            evento.preventDefault();
        }
    });
})();
