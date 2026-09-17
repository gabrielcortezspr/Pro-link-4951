import { expect } from '@playwright/test';

/**
 * Ações repetidas da suíte.
 *
 * Tudo aqui passa pela interface: nenhum atalho por SQL, nenhuma sessão forjada por cookie. O
 * ponto da suíte é provar que a pessoa consegue, e atalho no teste é a forma mais eficiente de
 * provar que o sistema funciona por um caminho que ninguém percorre.
 */

/** Entra com uma conta e confirma que a sessão existe. */
export async function entrar(pagina, conta) {
  if (!conta.senha) {
    throw new Error(
      'Conta sem senha. Para o administrador, exporte PROLINK_E2E_ADMIN_EMAIL e '
      + 'PROLINK_E2E_ADMIN_SENHA, ou crie a conta com:\n'
      + '  docker compose exec -T php php scripts/criar-admin.php --nome=... --email=... --senha=...',
    );
  }

  await pagina.goto('/login');
  await pagina.getByLabel(/E-mail/i).fill(conta.email);
  await pagina.getByLabel(/Senha/i).fill(conta.senha);
  await pagina.getByRole('button', { name: 'Entrar' }).click();
  await pagina.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/**
 * Encerra a sessão pela interface, e garante que ela acabou.
 *
 * O clique é o caminho que uma pessoa percorre, e é ele que se quer exercitar. A limpeza de
 * cookies depois não é redundância: o botão de sair do painel administrativo vive na sidebar e
 * pode não estar visível em toda largura, e sessão sobrevivente fazia o próximo `entrar()` cair
 * numa tela que não tem campo de e-mail, com o teste falhando por um motivo que não é o dele.
 */
export async function sair(pagina) {
  const botao = pagina.getByRole('button', { name: 'Sair' });

  if (await botao.count()) {
    await botao.first().click().catch(() => {});
    await pagina.waitForLoadState('domcontentloaded').catch(() => {});
  }

  await pagina.context().clearCookies();
}

/**
 * Falha o teste se a página trouxer erro de servidor ou rastro de exceção.
 *
 * Existe porque a aplicação devolve 500 com página de erro desenhada, e um teste que só confere
 * texto passaria por cima disso sem reclamar. O item 8.5 do edital também veda vazar rastro de
 * pilha para o usuário.
 */
export async function semErroDeServidor(pagina, resposta) {
  if (resposta) {
    expect(resposta.status(), `${pagina.url()} respondeu ${resposta.status()}`).toBeLessThan(400);
  }

  const corpo = await pagina.locator('body').innerText();

  expect(corpo, 'a página vazou rastro de pilha').not.toMatch(/Stack trace|Fatal error|Uncaught/i);
}

/**
 * Nenhuma tela pode rolar na horizontal. É a régua de responsividade do projeto, e vale em
 * qualquer largura: a do desktop e a de 390px do celular.
 */
export async function semRolagemHorizontal(pagina) {
  const estoura = await pagina.evaluate(
    () => document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
  );

  expect(estoura, `${pagina.url()} rola na horizontal`).toBe(false);
}

/**
 * Nenhum identificador de sistema pode aparecer na tela (regra do `CLAUDE.md`).
 *
 * Confere o texto visível, não o HTML: o valor cru é permitido em `title=""`, que é onde ele deve
 * ficar para quem audita.
 */
export async function semIdentificadorDeSistema(pagina) {
  const texto = await pagina.locator('body').innerText();
  const proibidos = [
    /\bsis_[a-z_]+\b/,
    /\bpro_[a-z_]+\b/,
    /\bcrea_[a-z_]+\b/,
    /\bmat_[a-z_]+\b/,
    /\bACESSO_NEGADO\b/,
    /\bLOGIN_FALHOU\b/,
  ];

  for (const padrao of proibidos) {
    const achado = texto.match(padrao);
    expect(achado?.[0], `${pagina.url()} mostra identificador de sistema na tela`).toBeUndefined();
  }
}

/** As três conferências que toda tela precisa passar. */
export async function telaSaudavel(pagina, resposta) {
  await semErroDeServidor(pagina, resposta);
  await semRolagemHorizontal(pagina);
  await semIdentificadorDeSistema(pagina);
}

/**
 * Aceita as confirmações de ação irreversível, guardando o texto de cada uma.
 *
 * A aplicação intercepta o envio de formulário marcado com `data-confirmar` e pede confirmação
 * antes de agir. O Playwright **recusa** diálogo por padrão, então sem isto o clique parece
 * funcionar, o POST nunca sai, e o teste falha três passos adiante por um motivo que não é o
 * verdadeiro. Foi exatamente o que aconteceu ao escrever o cenário 4.
 *
 * Os textos ficam guardados porque a confirmação **é** requisito, não obstáculo do teste:
 * manifestar interesse não pode ser desfeito, e avisar disso antes de agir é consentimento
 * informado. O cenário confere que a confirmação existiu.
 *
 * @returns {{textos: string[]}} acumulador vivo, preenchido conforme os diálogos aparecem
 */
export function aceitarConfirmacoes(pagina) {
  const registro = { textos: [] };

  pagina.on('dialog', async (dialogo) => {
    registro.textos.push(dialogo.message());
    await dialogo.accept();
  });

  return registro;
}

/**
 * Manifesta interesse na demanda e confirma que a plataforma aceitou.
 *
 * O ponto desta função é traduzir uma recusa em mensagem. `manifestacao.limite_hora` vale 10 e
 * conta por pessoa: rodando a suíte várias vezes seguidas, o mesmo profissional bate o teto e o
 * POST passa a ser recusado. Sem isto, o sintoma é `waitForURL` estourando o tempo, e três
 * investigações já começaram procurando defeito no botão.
 *
 * Recusa por limite é o anti-spam funcionando, e o teste diz isso em vez de acusar a aplicação.
 */
export async function manifestar(pagina, demandaId) {
  await pagina.goto(`/demandas/${demandaId}/manifestar`);
  await pagina.getByRole('button', { name: /Manifestar interesse/i }).click();

  try {
    await pagina.waitForURL(/\/manifestacoes/, { timeout: 8000 });
  } catch {
    const corpo = await pagina.locator('body').innerText();
    const aviso = corpo.match(/[^\n]*(limite|aguarde|muitas)[^\n]*/i)?.[0]?.trim();

    throw new Error(
      aviso
        ? `A manifestação foi recusada pela plataforma: "${aviso}". `
          + 'Isso costuma ser o teto de manifestações por hora, que conta por pessoa e é '
          + 'comportamento correto. Espere a hora virar, use outra conta, ou suba '
          + '`manifestacao.limite_hora` em /admin/parametros.'
        : `A manifestação não redirecionou e a tela não explicou por quê. URL: ${pagina.url()}`,
    );
  }
}

/**
 * Inventário de uma tela, para depuração.
 *
 * Não é conferência: serve para o relatório de execução dizer o que havia na página quando algo
 * falhou, sem obrigar quem lê a reproduzir na mão.
 */
export async function descrever(pagina) {
  return pagina.evaluate(() => {
    const campos = [...document.querySelectorAll('form input:not([type=hidden]), form select, form textarea')]
      .map((c) => `${c.tagName.toLowerCase()}[name=${c.name}]`);
    const botoes = [...document.querySelectorAll('form button, form input[type=submit]')]
      .map((b) => `"${(b.textContent || b.value || '').trim().replace(/\s+/g, ' ')}"`);

    return `campos: ${campos.join(', ') || '(nenhum)'}\nbotoes: ${botoes.join(', ') || '(nenhum)'}`;
  });
}
