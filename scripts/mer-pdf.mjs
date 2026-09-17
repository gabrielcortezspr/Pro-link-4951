/**
 * Exporta o HTML do MER para PDF **com as cores de fundo**.
 *
 * Existe por uma limitação do `npx playwright pdf`: o CLI não expõe a opção `printBackground`, e
 * sem ela o Chromium imprime só texto, bordas e linhas. O MER perdia o bege da página, o
 * tingimento de cada faixa de módulo e a sombra dos cartões, ou seja, metade do que distingue um
 * módulo do outro no papel. Um driver de vinte linhas resolve o que uma flag inexistente não
 * resolve.
 *
 * O Playwright vem de `e2e/node_modules`, onde a suíte de ponta a ponta já o instalou: não há
 * segundo lugar com a dependência, e não há instalação nova.
 *
 *   node scripts/mer-pdf.mjs <html de entrada> <pdf de saída>
 *
 * Chamado por `scripts/gerar-mer.py`. Rodar à mão só faz sentido para depurar a exportação.
 */
import { pathToFileURL } from 'node:url';
import path from 'node:path';

const [entrada, saida] = process.argv.slice(2);

if (!entrada || !saida) {
  console.error('Uso: node scripts/mer-pdf.mjs <html> <pdf>');
  process.exit(2);
}

const raiz = path.resolve(path.dirname(new URL(import.meta.url).pathname), '..');
const { chromium } = await import(pathToFileURL(path.join(raiz, 'e2e/node_modules/playwright/index.mjs')).href);

const navegador = await chromium.launch();
const pagina = await navegador.newPage();

await pagina.goto(pathToFileURL(path.resolve(entrada)).href, { waitUntil: 'networkidle' });

// A2 em paisagem, explícito, e não `preferCSSPageSize`: deixar o `@page` do documento decidir
// produziu página menor que o conteúdo, e o diagrama saiu cortado na direita. O formato aqui é o
// mesmo que o `npx playwright pdf --paper-format A2` usava antes, com a orientação declarada.
await pagina.pdf({
  path: path.resolve(saida),
  printBackground: true,
  format: 'A2',
  landscape: false,
});

await navegador.close();
