import { test, expect } from '@playwright/test';
import { ADMIN, EMPRESA, PROFISSIONAL } from '../apoio/contas.js';
import { entrar, sair, telaSaudavel } from '../apoio/acoes.js';

/**
 * Acessibilidade e uso em dispositivo móvel (Anexo I item 5; Anexo VI, item 10 da triagem).
 *
 * Roda no projeto `celular`, que é um iPhone 13 de 390px. As conferências são as que uma máquina
 * consegue fazer com honestidade: rolagem horizontal, campo sem rótulo associado, hierarquia de
 * título e foco visível de teclado. O resto de acessibilidade é julgamento humano e não se finge
 * que um teste cobriu.
 *
 * Marcado `@responsivo` porque é o filtro do projeto `celular` no `playwright.config.js`. Sem a
 * marca, aquele projeto não roda nada, e foi assim que ele passou a existir sem rodar nunca.
 */

const PUBLICAS = [
  ['/', 'landing'],
  ['/profissionais', 'busca pública'],
  ['/login', 'entrada'],
  ['/cadastro', 'cadastro'],
  ['/termos/uso', 'termos de uso'],
  ['/termos/privacidade', 'política de privacidade'],
];

const DO_PROFISSIONAL = [
  ['/perfil', 'perfil'],
  ['/perfil#conta', 'conta e dados'],
  ['/demandas/abertas', 'demandas abertas'],
  ['/manifestacoes', 'candidaturas e convites'],
];

const DA_EMPRESA = [
  ['/demandas', 'minhas demandas'],
  ['/demandas/nova', 'nova demanda'],
];

const DO_ADMIN = [
  ['/admin', 'visão geral'],
  ['/admin/denuncias', 'fila de denúncias'],
  ['/admin/auditoria', 'trilha de auditoria'],
  ['/admin/sessoes', 'sessões do motor'],
];

test.describe('@responsivo Em 390px de largura', () => {
  test('as telas públicas cabem, sem rolagem lateral', async ({ page }) => {
    for (const [rota, nome] of PUBLICAS) {
      const resposta = await page.goto(rota);
      await telaSaudavel(page, resposta);
      await conferirAcessibilidade(page, nome);
    }
  });

  test('as telas do profissional cabem', async ({ page }) => {
    await entrar(page, PROFISSIONAL);

    for (const [rota, nome] of DO_PROFISSIONAL) {
      const resposta = await page.goto(rota);
      await telaSaudavel(page, resposta);
      await conferirAcessibilidade(page, nome);
    }

    await sair(page);
  });

  test('as telas da empresa cabem', async ({ page }) => {
    await entrar(page, EMPRESA);

    for (const [rota, nome] of DA_EMPRESA) {
      const resposta = await page.goto(rota);
      await telaSaudavel(page, resposta);
      await conferirAcessibilidade(page, nome);
    }

    await sair(page);
  });

  test('o painel administrativo cabe', async ({ page }) => {
    await entrar(page, ADMIN);

    for (const [rota, nome] of DO_ADMIN) {
      const resposta = await page.goto(rota);
      await telaSaudavel(page, resposta);
      await conferirAcessibilidade(page, nome);
    }

    await sair(page);
  });

  test('a entrada é navegável e submissível só pelo teclado', async ({ page }) => {
    await page.goto('/login');

    // Tabular até o primeiro campo e digitar sem tocar no mouse. Formulário que só funciona com
    // clique exclui quem navega por teclado, e é o caminho de quem usa leitor de tela.
    await page.keyboard.press('Tab');

    const focoTemContorno = await page.evaluate(() => {
      const ativo = document.activeElement;
      if (!ativo || ativo === document.body) return null;
      const estilo = getComputedStyle(ativo);
      return estilo.outlineStyle !== 'none' || estilo.boxShadow !== 'none';
    });

    expect(focoTemContorno, 'o primeiro elemento focável não mostra que está em foco').not.toBe(false);

    await page.getByLabel(/E-mail/i).focus();
    await page.keyboard.type(PROFISSIONAL.email);
    await page.keyboard.press('Tab');
    await page.keyboard.type(PROFISSIONAL.senha);
    await page.keyboard.press('Enter');

    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
    await telaSaudavel(page);

    await sair(page);
  });
});

/**
 * As conferências de acessibilidade que uma máquina faz sem fingir.
 *
 * Campo sem rótulo associado é o defeito que mais quebra leitor de tela, e é o que o edital cobra
 * em "labels em todo campo". Título fora de ordem confunde navegação por cabeçalho. Imagem sem
 * texto alternativo é ruído para quem não vê.
 */
async function conferirAcessibilidade(pagina, nome) {
  const achados = await pagina.evaluate(() => {
    const semRotulo = [];

    for (const campo of document.querySelectorAll('input, select, textarea')) {
      if (campo.type === 'hidden' || campo.type === 'submit' || campo.type === 'button') continue;

      const temRotulo = (campo.labels && campo.labels.length > 0)
        || campo.getAttribute('aria-label')
        || campo.getAttribute('aria-labelledby')
        || campo.getAttribute('title');

      if (!temRotulo) {
        semRotulo.push(campo.name || campo.id || campo.tagName.toLowerCase());
      }
    }

    const imagensSemTexto = [...document.querySelectorAll('img')]
      .filter((img) => img.getAttribute('alt') === null)
      .map((img) => img.getAttribute('src') ?? '(sem src)');

    const niveis = [...document.querySelectorAll('h1, h2, h3, h4, h5, h6')]
      .map((t) => Number(t.tagName.slice(1)));

    const saltos = [];
    for (let i = 1; i < niveis.length; i++) {
      if (niveis[i] - niveis[i - 1] > 1) saltos.push(`h${niveis[i - 1]} para h${niveis[i]}`);
    }

    return {
      semRotulo,
      imagensSemTexto,
      saltos,
      titulosH1: document.querySelectorAll('h1').length,
    };
  });

  expect(achados.semRotulo, `${nome}: campo sem rótulo associado`).toEqual([]);
  expect(achados.imagensSemTexto, `${nome}: imagem sem texto alternativo`).toEqual([]);
  expect(achados.saltos, `${nome}: hierarquia de título com salto`).toEqual([]);
  expect(achados.titulosH1, `${nome}: a tela precisa de um h1, e de um só`).toBeLessThanOrEqual(1);
}
