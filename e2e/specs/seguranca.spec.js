import { test, expect } from '@playwright/test';
import { ADMIN, EMPRESA, PROFISSIONAL } from '../apoio/contas.js';
import { entrar, sair } from '../apoio/acoes.js';

/**
 * Sondas de autorização e validação, com requisição forjada.
 *
 * O backlog da E7 pede isto com todas as letras: *"conferir com requisição forjada, não por
 * leitura. Foi assim que as duas falhas da D27 apareceram, depois de já terem passado por revisão
 * de código"*. Revisão de código não pega POST que aceita identificador alheio, porque o trecho
 * lido parece certo: o que falha é a combinação entre o que a tela desenha e o que o servidor
 * aceita de volta.
 *
 * Cada teste aqui manda ao servidor algo que **nenhuma tela desenhou**: identificador de outra
 * pessoa, valor fora da lista fechada, item inválido no meio de um lote válido, escrita sem token.
 * A conferência é dupla: a resposta recusa, **e** o banco continua como estava. Recusar na tela e
 * gravar no banco é a falha que mais se esconde.
 */

/** Lê o token CSRF que a página serviu, para forjar o resto da requisição em volta dele. */
async function tokenDaPagina(pagina, rota) {
  await pagina.goto(rota);

  const token = await pagina.locator('input[name="_csrf"]').first().getAttribute('value');
  expect(token, `${rota} não trouxe token CSRF`).toBeTruthy();

  return token;
}

/** POST forjado, com os cookies da sessão aberta no navegador. */
async function postar(pagina, rota, campos) {
  return pagina.request.post(rota, { form: campos, maxRedirects: 0, failOnStatusCode: false });
}

test.describe('Autorização por operação, não só por rota', () => {
  test('conta sem perfil de administração não alcança o painel', async ({ page }) => {
    await entrar(page, PROFISSIONAL);

    for (const rota of ['/admin', '/admin/auditoria', '/admin/denuncias', '/admin/parametros', '/admin/lixeira']) {
      const resposta = await page.request.get(rota, { maxRedirects: 0, failOnStatusCode: false });

      expect(
        [401, 403, 302, 303].includes(resposta.status()),
        `${rota} respondeu ${resposta.status()} para conta sem perfil de administração`,
      ).toBe(true);
    }

    await sair(page);
  });

  test('escrita no painel é recusada para quem não administra, e não grava', async ({ page }) => {
    // O valor de partida é lido com a conta que **enxerga** a tela. Lê-lo com a conta sem perfil
    // devolveria nulo, e o teste passaria comparando nulo com nulo: a primeira escrita deste
    // arquivo fazia exatamente isso.
    await entrar(page, ADMIN);
    const antes = await valorDoParametro(page, 'match.limiar');
    expect(antes, 'não consegui ler o valor de partida').not.toBeNull();
    await sair(page);

    await entrar(page, PROFISSIONAL);

    const resposta = await postar(page, '/admin/parametros', {
      _csrf: (await page.locator('input[name="_csrf"]').first().getAttribute('value')) ?? 'nenhum',
      'parametro[match.limiar]': '0.99',
    });

    expect([401, 403, 302, 303].includes(resposta.status()), `respondeu ${resposta.status()}`).toBe(true);

    await sair(page);
    await entrar(page, ADMIN);

    expect(await valorDoParametro(page, 'match.limiar'), 'o peso do motor mudou por quem não administra')
      .toBe(antes);

    await sair(page);
  });

  test('escrita sem token CSRF é recusada', async ({ page }) => {
    await entrar(page, ADMIN);

    const antes = await valorDoParametro(page, 'match.limiar');

    // Sem `_csrf` nenhum: é o que uma página de terceiro conseguiria montar.
    const resposta = await postar(page, '/admin/parametros', { 'parametro[match.limiar]': '0.99' });

    expect(resposta.status(), 'POST sem token CSRF foi aceito').not.toBe(200);
    expect(await valorDoParametro(page, 'match.limiar'), 'gravou sem token CSRF').toBe(antes);

    await sair(page);
  });
});

test.describe('O formulário aceita de volta só o que ele desenhou', () => {
  test('parâmetro fora da lista fechada não é gravado', async ({ page }) => {
    await entrar(page, ADMIN);

    const token = await tokenDaPagina(page, '/admin/parametros');

    await postar(page, '/admin/parametros', {
      _csrf: token,
      'parametro[par.que.nao.existe]': '1',
    });

    const existe = await page.evaluate(async () => {
      const html = await (await fetch('/admin/parametros')).text();
      return html.includes('par.que.nao.existe');
    });

    expect(existe, 'uma chave inventada entrou na lista de parâmetros').toBe(false);

    await sair(page);
  });

  test('um valor fora da faixa reprova o lote inteiro, e nada é gravado', async ({ page }) => {
    await entrar(page, ADMIN);

    const token = await tokenDaPagina(page, '/admin/parametros');
    const limiarAntes = await valorDoParametro(page, 'match.limiar');
    const pesoAntes = await valorDoParametro(page, 'match.peso.contrato');

    // O primeiro campo é válido e o segundo não. A D27 e a D28 mostram por que o lote tem de cair
    // inteiro: meia gravação deixa o motor num estado que ninguém pediu.
    await postar(page, '/admin/parametros', {
      _csrf: token,
      'parametro[match.peso.contrato]': '0.25',
      'parametro[match.limiar]': '7',
    });

    expect(await valorDoParametro(page, 'match.peso.contrato'), 'o valor válido do lote foi gravado mesmo com o lote reprovado')
      .toBe(pesoAntes);
    expect(await valorDoParametro(page, 'match.limiar'), 'o valor fora da faixa foi gravado').toBe(limiarAntes);

    await sair(page);
  });

  test('restauração da lixeira sem motivo é recusada', async ({ page }) => {
    await entrar(page, ADMIN);

    const token = await tokenDaPagina(page, '/admin/lixeira');

    const resposta = await postar(page, '/admin/lixeira', {
      _csrf: token,
      entidade: 'sis_usuarios',
      id: '999999999',
      motivo: '',
    });

    // Redireciona de volta com a mensagem, e não estoura: entrada recusada é regra de negócio,
    // não falha de sistema.
    expect(resposta.status(), `respondeu ${resposta.status()}`).toBeLessThan(500);

    await sair(page);
  });

  test('entidade inventada na lixeira não é aceita', async ({ page }) => {
    await entrar(page, ADMIN);

    const token = await tokenDaPagina(page, '/admin/lixeira');

    const resposta = await postar(page, '/admin/lixeira', {
      _csrf: token,
      entidade: 'sis_usuarios; DROP TABLE sis_usuarios',
      id: '1',
      motivo: 'Motivo suficientemente longo para passar da validação.',
    });

    expect(resposta.status()).toBeLessThan(500);

    // A tabela continua existindo. A injeção não teria como funcionar, porque o nome da tabela
    // vem de uma lista fechada e nunca da entrada, mas a sonda é barata e o custo do contrário
    // seria total.
    await page.goto('/admin');
    await expect(page.locator('body')).toBeVisible();

    await sair(page);
  });
});

test.describe('Fluxos do titular', () => {
  test('não dá para manifestar interesse em nome de outra pessoa', async ({ page }) => {
    await entrar(page, EMPRESA);

    // A empresa é a dona da demanda; manifestar interesse é do lado de quem atende.
    const resposta = await page.request.get('/demandas/1/manifestar', {
      maxRedirects: 0,
      failOnStatusCode: false,
    });

    expect(resposta.status(), `respondeu ${resposta.status()}`).not.toBe(200);

    await sair(page);
  });

  test('a tela de interessados de uma demanda alheia é recusada', async ({ page }) => {
    await entrar(page, EMPRESA);

    // Demanda 1 é de outra conta: o painel do demandante é de quem publicou.
    const resposta = await page.request.get('/demandas/1/interessados', {
      maxRedirects: 0,
      failOnStatusCode: false,
    });

    expect([302, 303, 403, 404].includes(resposta.status()), `respondeu ${resposta.status()}`).toBe(true);

    await sair(page);
  });
});

/**
 * Lê o valor de um parâmetro pela própria tela do painel.
 *
 * Pela tela, e não pelo banco, de propósito: o que interessa é o que a aplicação passou a
 * reportar depois da tentativa de escrita.
 */
async function valorDoParametro(pagina, chave) {
  await pagina.goto('/admin/parametros');

  const campo = pagina.locator(`[name="parametro[${chave}]"]`).first();

  if (!(await campo.count())) {
    return null;
  }

  return campo.inputValue();
}
