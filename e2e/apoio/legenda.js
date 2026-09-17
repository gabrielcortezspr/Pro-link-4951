/**
 * A faixa de legenda dos vídeos, e o ritmo de quem apresenta.
 *
 * Vive fora dos specs porque são quatro vídeos e não um: a jornada completa dos seis cenários do
 * Anexo I, e uma jornada por perfil. Tudo aqui é sobreposição do teste, nunca elemento da
 * aplicação: a legenda é injetada no documento e some ao fim de cada passo, e nada disso existe
 * para quem usa a plataforma.
 */

/**
 * Prepara a faixa de legenda.
 *
 * O estilo é injetado uma vez e sobrevive à navegação por `addInitScript`, que roda a cada
 * documento novo. Sem isso a legenda sumiria no primeiro clique que troca de página.
 */
export async function prepararLegenda(pagina) {
  await pagina.addInitScript(() => {
    const aplicar = () => {
      if (document.getElementById('pl-legenda-estilo')) return;

      const estilo = document.createElement('style');
      estilo.id = 'pl-legenda-estilo';
      estilo.textContent = `
        #pl-legenda {
          position: fixed; left: 0; right: 0; bottom: 0; z-index: 2147483647;
          padding: 18px 28px 20px; pointer-events: none;
          background: linear-gradient(to top, rgba(11,42,74,.94), rgba(11,42,74,.86) 60%, rgba(11,42,74,0));
          color: #fff; font-family: Inter, system-ui, sans-serif;
          opacity: 0; transition: opacity .28s ease;
        }
        #pl-legenda.vis { opacity: 1; }
        #pl-legenda b {
          display: block; font-family: "Space Grotesk", Inter, system-ui, sans-serif;
          font-size: 19px; letter-spacing: -.01em; margin-bottom: 3px;
        }
        #pl-legenda span { display: block; font-size: 14.5px; opacity: .88; line-height: 1.45; }
      `;
      document.head.appendChild(estilo);

      const faixa = document.createElement('div');
      faixa.id = 'pl-legenda';
      faixa.innerHTML = '<b></b><span></span>';
      document.body.appendChild(faixa);
    };

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', aplicar);
    } else {
      aplicar();
    }
  });
}

/** Mostra a legenda do passo atual. */
export async function legenda(pagina, titulo, texto) {
  await pagina.evaluate(
    ([t, s]) => {
      const faixa = document.getElementById('pl-legenda');
      if (!faixa) return;
      faixa.querySelector('b').textContent = t;
      faixa.querySelector('span').textContent = s;
      faixa.classList.add('vis');
    },
    [titulo, texto],
  );
}

/** Pausa com a legenda no ar, e depois a desliga. */
export async function respirar(pagina, ms) {
  await pagina.waitForTimeout(ms);
  await pagina.evaluate(() => document.getElementById('pl-legenda')?.classList.remove('vis'));
  await pagina.waitForTimeout(280);
}

/** Rola até o elemento com suavidade, para o vídeo não dar saltos. */
export async function rolarAte(pagina, seletor) {
  const alvo = pagina.locator(seletor).first();

  if (await alvo.count()) {
    await alvo.evaluate((no) => no.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    await pagina.waitForTimeout(700);
  }
}

/** Digita em ritmo humano. Só onde o vídeo ganha com isso. */
export async function preencherDevagar(localizador, texto) {
  await localizador.click();
  await localizador.pressSequentially(texto, { delay: 18 });
}
