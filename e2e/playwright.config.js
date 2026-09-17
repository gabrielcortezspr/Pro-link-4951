// @ts-check
import { defineConfig, devices } from '@playwright/test';

/**
 * Suíte de ponta a ponta do Pro-Link.
 *
 * Ela prova o que nenhum `verificar-*.php` prova: que os seis cenários mínimos do Anexo I rodam
 * pelo navegador, clicando onde uma pessoa clicaria. Repositório no verde não diz que a página
 * abre, e foi por isso que a tela de auditoria já subiu em 500 com dezesseis conferências
 * passando.
 *
 * Grava vídeo de toda execução: é a evidência do "demo ao vivo" do critério de funcionalidade, e
 * o insumo do vídeo demonstrativo da entrega.
 *
 * Ferramenta de desenvolvimento, não dependência do produto: Playwright é Apache-2.0, roda fora
 * do contêiner e nada aqui é servido pela aplicação. Mesmo estatuto do Python em `scripts/`.
 */
export default defineConfig({
  testDir: './specs',
  // Um worker só, e sem paralelismo: os cenários compartilham um banco e uma demanda, e dois
  // navegadores manifestando interesse ao mesmo tempo disputariam o limite por hora.
  workers: 1,
  fullyParallel: false,
  // Falhar por falhar não ajuda numa véspera de entrega: o relatório precisa mostrar tudo que
  // quebrou de uma vez.
  forbidOnly: true,
  retries: 0,
  reporter: [['list'], ['html', { outputFolder: 'relatorio', open: 'never' }]],
  timeout: 45_000,
  expect: { timeout: 10_000 },
  outputDir: './resultados',
  use: {
    baseURL: process.env.PROLINK_URL ?? 'http://localhost:8080',
    locale: 'pt-BR',
    timezoneId: 'America/Manaus',
    video: { mode: 'on', size: { width: 1440, height: 900 } },
    screenshot: 'only-on-failure',
    trace: 'retain-on-failure',
    actionTimeout: 10_000,
  },
  projects: [
    {
      name: 'desktop',
      use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 } },
    },
    {
      // O Anexo I item 5 e o Anexo VI cobram uso em dispositivo móvel. Só os cenários marcados
      // com @responsivo rodam aqui: o resto seria repetição cara sem informação nova.
      name: 'celular',
      grep: /@responsivo/,
      use: { ...devices['iPhone 13'] },
    },
  ],
});
