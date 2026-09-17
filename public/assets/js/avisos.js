/**
 * Fechar e esvair os avisos que flutuam sobre a tela.
 *
 * A tela funciona sem este arquivo: o aviso aparece, é lido, e some na navegação seguinte, porque
 * o flash é consumido no servidor e nunca volta. O script só acrescenta o fechar imediato e o
 * esvair de quem não precisa de decisão.
 *
 * **Erro não esvai.** Quem precisa corrigir alguma coisa não pode perder a frase que diz o quê, e
 * mensagem que some sozinha obriga a refazer a ação para ler de novo. Só sucesso e informação
 * saem sozinhos, e mesmo esses param de contar o tempo enquanto o ponteiro está em cima ou o foco
 * está dentro: ler devagar não pode ser punido.
 */
(function () {
  'use strict';

  const CAIXA = document.querySelector('[data-toasts]');

  if (!CAIXA) return;

  const ESPERA = 6000;

  function retirar(aviso) {
    aviso.classList.add('saindo');
    aviso.addEventListener('transitionend', () => {
      aviso.remove();
      if (!CAIXA.children.length) CAIXA.remove();
    }, { once: true });

    // A transição pode não acontecer (quem pediu menos movimento no sistema operacional não a
    // recebe), e sem isto o aviso ficaria para sempre.
    setTimeout(() => {
      if (aviso.isConnected) {
        aviso.remove();
        if (!CAIXA.children.length) CAIXA.remove();
      }
    }, 400);
  }

  CAIXA.querySelectorAll('.pl-toast').forEach((aviso) => {
    const fechar = aviso.querySelector('[data-fechar]');

    if (fechar) fechar.addEventListener('click', () => retirar(aviso));

    if (!aviso.hasAttribute('data-esvair')) return;

    let relogio = setTimeout(() => retirar(aviso), ESPERA);
    const segurar = () => clearTimeout(relogio);
    const soltar  = () => { relogio = setTimeout(() => retirar(aviso), ESPERA); };

    aviso.addEventListener('mouseenter', segurar);
    aviso.addEventListener('mouseleave', soltar);
    aviso.addEventListener('focusin', segurar);
    aviso.addEventListener('focusout', soltar);
  });
}());
