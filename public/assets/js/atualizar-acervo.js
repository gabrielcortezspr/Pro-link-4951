/**
 * Reativa o botão "Atualizar meu acervo no CREA" quando a espera termina (D77).
 *
 * A tela funciona sem este arquivo: o botão desabilitado diz a partir de que horas pode ser usado,
 * e recarregar a página depois disso o traz de volta. O script só poupa o recarregamento.
 *
 * O tempo vem pronto do servidor em `data-restante` (segundos), e não como horário: contar a
 * partir do relógio de quem olha erraria em computador com hora errada. Quem decide de fato
 * continua sendo o servidor, que recusa o pedido dentro da janela seja qual for o estado do botão.
 */
(function () {
  'use strict';

  document.querySelectorAll('form.pl-atualiza[data-restante]').forEach(function (form) {
    const segundos = parseInt(form.getAttribute('data-restante'), 10);

    if (!(segundos > 0)) return;

    // Um segundo de folga, para o pedido não chegar ao servidor antes da janela fechar.
    window.setTimeout(function () {
      const botao = form.querySelector('button[type="submit"]');
      const nota  = form.querySelector('.pl-atualiza-nota');

      if (botao) {
        botao.disabled = false;
        botao.removeAttribute('aria-describedby');
      }

      if (nota) nota.remove();
      form.removeAttribute('data-restante');
    }, (segundos + 1) * 1000);
  });
})();
