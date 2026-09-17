// @ts-check
import { test, expect } from '@playwright/test';
import { ADMIN, EMPRESA, PROFISSIONAL } from '../apoio/contas.js';
import { entrar, sair, telaSaudavel } from '../apoio/acoes.js';

/**
 * A régua do refinamento visual.
 *
 * Os outros arquivos desta pasta provam que a plataforma **funciona**. Este prova que ela **é o
 * desenho**, que é uma pergunta diferente e foi a que a autora fez ao reprovar a entrega: telas
 * numa coluna estreita no meio do monitor, duas barras de navegação diferentes convivendo, e a
 * landing sem as animações que o mockup tem.
 *
 * Nada aqui julga gosto. Cada conferência é uma afirmação verificável sobre o que os mockups de
 * `docs/mockups/` definem, e uma tela que passe nas quatro continua podendo estar feia: o que ela
 * não pode é estar estruturalmente diferente do que foi desenhado.
 *
 * ## As quatro réguas
 *
 * 1. **Uma barra por situação.** Quem está dentro da conta vê a barra lateral navy; quem está
 *    fora vê a barra pública do mockup. Nunca as duas, nunca nenhuma, e nunca a navbar do
 *    Bootstrap que o projeto usava antes.
 * 2. **Largura total.** O conteúdo ocupa o espaço disponível. A régua é frouxa de propósito
 *    (90%), porque o alvo não é "medida exata" e sim a coluna de 960px no meio de um monitor de
 *    1600, que foi o que a autora apontou.
 * 3. **Console limpo.** Erro de script e bloqueio de política de segurança quebram a tela em
 *    silêncio. Foi assim que as animações da landing sumiram sem ninguém notar.
 * 4. **A tela saudável**, que é a régua comum da suíte: sem 500, sem rolagem horizontal, sem
 *    identificador de sistema à mostra.
 */

/** O que a barra pública do mockup precisa oferecer, com o texto que o mockup usa. */
const LINKS_PUBLICOS = ['Como funciona', 'Para profissionais', 'Para empresas'];

/** Telas sem sessão. As três primeiras são de conteúdo e entram também na régua de largura. */
const PUBLICAS = [
  { caminho: '/', nome: 'landing', larga: true },
  { caminho: '/profissionais', nome: 'busca de profissionais', larga: true },
  { caminho: '/login', nome: 'entrar', larga: false },
  { caminho: '/cadastro', nome: 'criar conta', larga: false },
  { caminho: '/recuperar-senha', nome: 'recuperar senha', larga: false },
  { caminho: '/termos/uso', nome: 'termos de uso', larga: false },
  { caminho: '/termos/privacidade', nome: 'política de privacidade', larga: false },
];

/** Telas de dentro da conta, por papel. */
const DO_PROFISSIONAL = [
  { caminho: '/inicio', nome: 'início do profissional' },
  // Demandas abertas pede conta, e por isso não está na lista pública: sem sessão a plataforma
  // oferece a busca de profissionais, que é o que a landing usa, e não a lista de demandas.
  { caminho: '/demandas/abertas', nome: 'demandas abertas' },
  { caminho: '/perfil', nome: 'perfil' },
  { caminho: '/manifestacoes', nome: 'interesses' },
  { caminho: '/privacidade', nome: 'privacidade' },
];

const DA_ADMINISTRACAO = [
  { caminho: '/admin', nome: 'visão geral' },
  { caminho: '/admin/auditoria', nome: 'trilha de auditoria' },
  { caminho: '/admin/sessoes', nome: 'sessões do motor' },
  { caminho: '/admin/denuncias', nome: 'moderação' },
  { caminho: '/admin/parametros', nome: 'parâmetros' },
  { caminho: '/admin/lixeira', nome: 'lixeira' },
  { caminho: '/admin/contas', nome: 'contas' },
];

const DA_EMPRESA = [
  { caminho: '/inicio', nome: 'início da empresa' },
  { caminho: '/demandas', nome: 'minhas demandas' },
];

/**
 * Liga a escuta do console antes de navegar, e devolve o acumulador.
 *
 * Recusa de recurso que o navegador pede sozinho não conta: favicon ausente é ruído de ambiente,
 * não defeito da tela.
 */
function escutarConsole(pagina) {
  const problemas = [];

  pagina.on('console', (m) => {
    if (m.type() !== 'error') return;
    const texto = m.text();
    if (/favicon/i.test(texto)) return;
    problemas.push(texto);
  });

  pagina.on('pageerror', (e) => problemas.push(`exceção: ${e.message}`));

  return problemas;
}

/** Uma barra, e só uma, de acordo com a situação. */
async function conferirShell(pagina, { logado }) {
  const lateral = pagina.locator('.pl-app .pl-side');
  // Duas classes para a mesma barra, e é assim nos mockups: na landing ela flutua transparente
  // sobre o herói (`.pl-lp-nav`), e nas outras telas públicas ela é sólida (`.pl-topnav`). Os
  // links e as ações são os mesmos nas duas, e é isso que as conferências abaixo cobram.
  const publica = pagina.locator('.pl-topnav, .pl-lp-nav');

  if (logado) {
    await expect(lateral, 'tela de dentro da conta sem a barra lateral navy').toHaveCount(1);
    await expect(publica, 'tela de dentro da conta com a barra pública').toHaveCount(0);
  } else {
    await expect(publica, 'tela pública sem a barra do mockup').toHaveCount(1);
    await expect(lateral, 'tela pública com a barra lateral de quem está logado').toHaveCount(0);

    for (const link of LINKS_PUBLICOS) {
      await expect(
        publica.getByRole('link', { name: link }),
        `a barra pública não oferece "${link}"`,
      ).toHaveCount(1);
    }

    await expect(publica.getByRole('link', { name: 'Entrar' })).toHaveCount(1);
    await expect(publica.getByRole('link', { name: /Usar a plataforma/i })).toHaveCount(1);
  }

  // A navbar do Bootstrap era a barra antiga, e saiu das duas situações. Se ela reaparecer em
  // alguma tela, é sinal de template que ficou fora da migração.
  await expect(pagina.locator('nav.navbar'), 'a barra antiga voltou nesta tela').toHaveCount(0);
}

/**
 * O conteúdo ocupa o espaço disponível.
 *
 * Desconta a barra lateral quando ela existe: o espaço de quem está logado é a janela menos a
 * barra, e cobrar a janela inteira acusaria uma tela correta.
 */
async function conferirLargura(pagina, nome) {
  const medida = await pagina.evaluate(() => {
    const principal = document.querySelector('main');
    const barra = document.querySelector('.pl-side');

    return {
      conteudo: principal ? principal.getBoundingClientRect().width : 0,
      disponivel: window.innerWidth - (barra ? barra.getBoundingClientRect().width : 0),
    };
  });

  const proporcao = medida.conteudo / medida.disponivel;

  expect(
    proporcao,
    `${nome}: o conteúdo ocupa ${Math.round(proporcao * 100)}% do espaço `
    + `(${Math.round(medida.conteudo)}px de ${Math.round(medida.disponivel)}px disponíveis). `
    + 'A régua do projeto é largura total, não coluna estreita no meio da tela.',
  ).toBeGreaterThan(0.9);
}

test.describe('O refinamento visual', () => {
  test.describe.configure({ mode: 'serial' });

  test('as telas públicas têm a barra do mockup e ocupam a tela', async ({ page }) => {
    const problemas = escutarConsole(page);

    for (const tela of PUBLICAS) {
      const resposta = await page.goto(tela.caminho);

      await telaSaudavel(page, resposta);
      await conferirShell(page, { logado: false });

      if (tela.larga) {
        await conferirLargura(page, tela.nome);
      }
    }

    expect(problemas, `erro de console nas telas públicas:\n${problemas.join('\n')}`).toEqual([]);
  });

  test('a landing traz as animações do mockup', async ({ page }) => {
    const problemas = escutarConsole(page);

    await page.goto('/');

    // O mockup anima com `<canvas>`, e a política de segurança do projeto (`script-src 'self'`)
    // aceita arquivo próprio e recusa script embutido. Uma sessão anterior trocou o canvas por um
    // desenho estático por ler a política errado, e as animações sumiram sem nenhum teste
    // reclamar. Esta conferência existe para isso não passar de novo.
    const telas = page.locator('canvas');
    await expect(telas, 'a landing perdeu o canvas que anima o mockup').not.toHaveCount(0);

    // Canvas existe mas nunca pintado é o mesmo resultado visual de canvas nenhum: confere que
    // há pixel aceso depois de um quadro de animação.
    const pintou = await page.evaluate(async () => {
      await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

      return [...document.querySelectorAll('canvas')].some((tela) => {
        const ctx = tela.getContext('2d');
        if (!ctx || !tela.width || !tela.height) return false;
        const { data } = ctx.getImageData(0, 0, tela.width, tela.height);
        for (let i = 3; i < data.length; i += 4) {
          if (data[i] !== 0) return true;
        }
        return false;
      });
    });

    expect(pintou, 'o canvas da landing está em branco: o script não desenhou').toBe(true);

    expect(
      problemas.filter((p) => /Content Security Policy|refused to (load|execute)/i.test(p)),
      'a política de segurança bloqueou um script da landing',
    ).toEqual([]);
  });

  test('a busca sem conta funciona na própria landing', async ({ page }) => {
    // O pedido foi literal: "cadê a landing page com a pesquisa sem ter conta". A busca precisa
    // sair da landing e chegar em resultado, sem passar por login.
    await page.goto('/');

    const campo = page.locator('form[action*="profissionais"] input[type="search"], '
      + 'form[action*="profissionais"] input[name="termo"]').first();

    await expect(campo, 'a landing não tem o campo de busca do mockup').toBeVisible();
    await campo.fill('Manaus');
    await campo.press('Enter');

    await page.waitForURL(/\/profissionais/);
    await telaSaudavel(page);
    await conferirShell(page, { logado: false });
    await expect(page.locator('body')).not.toContainText(/Entre com sua conta/i);
  });

  test('as telas do profissional usam a barra lateral', async ({ page }) => {
    const problemas = escutarConsole(page);

    await entrar(page, PROFISSIONAL);

    for (const tela of DO_PROFISSIONAL) {
      const resposta = await page.goto(tela.caminho);

      await telaSaudavel(page, resposta);
      await conferirShell(page, { logado: true });
      await conferirLargura(page, tela.nome);
    }

    await sair(page);

    expect(problemas, `erro de console nas telas do profissional:\n${problemas.join('\n')}`)
      .toEqual([]);
  });

  test('as telas da empresa usam a barra lateral, inclusive o feed', async ({ page }) => {
    const problemas = escutarConsole(page);

    await entrar(page, EMPRESA);

    for (const tela of DA_EMPRESA) {
      const resposta = await page.goto(tela.caminho);

      await telaSaudavel(page, resposta);
      await conferirShell(page, { logado: true });
      await conferirLargura(page, tela.nome);
    }

    // O feed depende de uma demanda publicada desta empresa, e o id não é fixo: sai da lista. A
    // demanda em rascunho não tem feed, e por isso a busca tenta as primeiras da lista em vez de
    // parar na primeira: falhar aqui porque a primeira linha era um rascunho acusaria a tela
    // errada.
    const ids = await page.evaluate(() => [...new Set(
      [...document.querySelectorAll('a[href*="/demandas/"]')]
        .map((a) => a.getAttribute('href')?.match(/\/demandas\/(\d+)(?:$|\/)/)?.[1])
        .filter(Boolean),
    )].slice(0, 6));

    expect(ids.length, 'a lista de demandas da empresa está vazia: o feed não tem como ser aberto')
      .toBeGreaterThan(0);

    let feedAberto = false;

    for (const id of ids) {
      const resposta = await page.goto(`/demandas/${id}/compativeis`);

      if ((resposta?.status() ?? 500) >= 400) continue;

      await telaSaudavel(page, resposta);
      await conferirShell(page, { logado: true });
      await conferirLargura(page, `feed de compatíveis da demanda ${id}`);
      feedAberto = true;
      break;
    }

    expect(feedAberto, `nenhuma das demandas ${ids.join(', ')} abriu o feed de compatíveis`)
      .toBe(true);

    await sair(page);

    expect(problemas, `erro de console nas telas da empresa:\n${problemas.join('\n')}`).toEqual([]);
  });

  test('as telas da administração usam a barra lateral', async ({ page }) => {
    // Sem a senha no ambiente não há como entrar, e um teste que falha por falta de configuração
    // acusa a aplicação por um problema de quem roda. A instrução de como configurar está no
    // erro que `entrar()` levanta e no README desta pasta.
    test.skip(!ADMIN.senha, 'exporte PROLINK_E2E_ADMIN_EMAIL e PROLINK_E2E_ADMIN_SENHA');

    const problemas = escutarConsole(page);

    await entrar(page, ADMIN);

    for (const tela of DA_ADMINISTRACAO) {
      const resposta = await page.goto(tela.caminho);

      await telaSaudavel(page, resposta);
      await conferirShell(page, { logado: true });
      await conferirLargura(page, tela.nome);
    }

    await sair(page);

    expect(problemas, `erro de console nas telas da administração:\n${problemas.join('\n')}`)
      .toEqual([]);
  });
});
