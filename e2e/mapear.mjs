/**
 * Utilitário de exploração: entra com uma conta e imprime os campos e as ações de cada tela.
 *
 * Não é teste. Existe para escrever os cenários olhando o HTML que a aplicação realmente
 * devolve, em vez de inferir nome de campo lendo macro de Twig.
 *
 *   node e2e/mapear.mjs <email> <senha> [rota...]
 */
import { chromium } from '@playwright/test';

const [email, senha, ...rotas] = process.argv.slice(2);
const base = process.env.PROLINK_URL ?? 'https://localhost:8443';

const navegador = await chromium.launch();
const pagina = await navegador.newPage({ viewport: { width: 1440, height: 900 } });

await pagina.goto(`${base}/login`);
console.log(`\n== /login ==`);
console.log(await inventario(pagina));

await preencherLogin(pagina, email, senha);

for (const rota of rotas) {
  const resposta = await pagina.goto(base + rota, { waitUntil: 'domcontentloaded' });
  console.log(`\n== ${rota}  (HTTP ${resposta.status()}) ==`);
  console.log(`h1: ${(await pagina.locator('h1').first().textContent().catch(() => '(nenhum)'))?.trim()}`);
  console.log(await inventario(pagina));
}

await navegador.close();

async function preencherLogin(pagina, email, senha) {
  const campos = await pagina.locator('input:not([type=hidden])').all();
  if (campos.length >= 2) {
    await campos[0].fill(email);
    await campos[1].fill(senha);
  }
  await pagina.locator('form button[type=submit], form input[type=submit]').first().click();
  await pagina.waitForLoadState('domcontentloaded');
  console.log(`\nlogin -> ${pagina.url()}`);
}

async function inventario(pagina) {
  return pagina.evaluate(() => {
    const linhas = [];
    for (const form of document.querySelectorAll('form')) {
      linhas.push(`  form ${form.method.toUpperCase()} ${form.getAttribute('action') || '(mesma url)'}`);
      for (const campo of form.querySelectorAll('input, select, textarea')) {
        if (campo.type === 'hidden' && campo.name === '_csrf') continue;
        const rotulo = campo.labels?.[0]?.textContent?.trim().replace(/\s+/g, ' ') ?? '';
        linhas.push(`    ${campo.tagName.toLowerCase()}[${campo.type || ''}] name=${campo.name || '(sem)'} label="${rotulo}"`);
      }
      for (const botao of form.querySelectorAll('button, input[type=submit]')) {
        linhas.push(`    botao "${(botao.textContent || botao.value || '').trim().replace(/\s+/g, ' ')}"`);
      }
    }
    const links = [...document.querySelectorAll('a[href]')]
      .map((a) => `${a.textContent.trim().replace(/\s+/g, ' ')} -> ${a.getAttribute('href')}`)
      .filter((t) => t.length < 90)
      .slice(0, 25);
    linhas.push('  links: ' + (links.length ? '\n    ' + links.join('\n    ') : '(nenhum)'));
    return linhas.join('\n');
  });
}
