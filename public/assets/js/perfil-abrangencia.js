/**
 * Exclusão mútua entre "Qualquer lugar do país" e a lista de UFs, no perfil do profissional.
 *
 * Melhoria progressiva: o estado correto já vem renderizado do servidor — o grupo de UFs nasce
 * com `disabled` quando `QUALQUER` está gravado — e `Preferencias::normalizarAbrangencia()` dá
 * precedência a `QUALQUER` de qualquer jeito. Este arquivo só evita que a pessoa marque 27 caixas
 * que a escolha de cima já tornou irrelevantes.
 *
 * Arquivo externo, e não `<script>` embutido no template, por causa da CSP: `script-src 'self'`
 * recusa script inline, e abrir 'unsafe-inline' para economizar um arquivo desfaria boa parte do
 * que a diretiva serve para impedir.
 */
(function () {
    'use strict';

    var qualquer = document.getElementById('campo-abrangencia-qualquer');
    var ufs = document.getElementById('abrangencia-ufs');

    if (!qualquer || !ufs) {
        return;
    }

    qualquer.addEventListener('change', function () {
        ufs.disabled = qualquer.checked;
    });
})();
