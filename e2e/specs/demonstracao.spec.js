import { test, expect } from '@playwright/test';
import { mkdir, copyFile } from 'node:fs/promises';
import path from 'node:path';
import { ADMIN, EMPRESA, PROFISSIONAL, TOS_PRINCIPAL, TOS_SECUNDARIO } from '../apoio/contas.js';
import { aceitarConfirmacoes, entrar, sair } from '../apoio/acoes.js';

/**
 * A jornada completa, de ponta a ponta, num vídeo só.
 *
 * Separada de `cenarios.spec.js` de propósito, e a diferença é de finalidade:
 *
 *   · `cenarios.spec.js` **verifica**. Seis testes independentes, conferência dura, falha rápido.
 *     É o que se roda antes de commitar.
 *   · este arquivo **mostra**. Um teste só, um contexto só, um vídeo só, no ritmo de quem
 *     apresenta. É o insumo do vídeo demonstrativo da entrega e o ensaio da demo do Demo Day.
 *
 * As conferências aqui são as mínimas para o vídeo não gravar uma tela quebrada sem ninguém
 * perceber. A prova rigorosa é a do outro arquivo.
 *
 * O texto de cada passo é sobreposto ao vídeo, como legenda. É overlay do teste, não elemento da
 * aplicação, e some ao fim de cada passo.
 */
test.describe('Demonstração', () => {
  test('a jornada completa do Pro-Link, dos seis cenários do Anexo I', async ({ page }, info) => {
    test.slow();

    const confirmacoes = aceitarConfirmacoes(page);
    const selo = new Date().toISOString().slice(11, 19).replace(/:/g, '');

    await prepararLegenda(page);

    // ---------------------------------------------------------------- abertura
    await page.goto('/');
    await legenda(page, 'Pro-Link', 'Demandas técnicas e profissionais do CREA, ligados por evidência documental');
    await respirar(page, 2500);

    // ---------------------------------------------------------------- cenário 1
    await legenda(page, 'Cenário 1 de 6', 'O profissional vê o acervo que a API oficial do CREA confirmou');
    await entrar(page, PROFISSIONAL);
    await page.goto('/perfil');
    await respirar(page, 1500);

    await rolarAte(page, '.pl-mark:not(.nao)');
    await legenda(page, 'O selo de verificação', 'Verde água só aparece no que o conselho confirmou, nunca no que a pessoa declarou');
    await respirar(page, 2500);

    const titulo = `Plano de intervenção urbana em área central (${selo})`;
    await page.getByLabel(/O que você fez/i).fill(titulo);
    await page.getByLabel(/Detalhes/i).fill(
      'Coordenação de diagnóstico físico-territorial e das diretrizes de uso e ocupação do solo.',
    );

    const art = page.getByLabel(/Vincular a uma ART/i);
    if ((await art.locator('option').count()) > 1) {
      await art.selectOption({ index: 1 });
    }

    await legenda(page, 'Competência ligada a uma experiência', 'A experiência declarada aponta para uma ART do próprio acervo');
    await respirar(page, 2000);
    await page.getByRole('button', { name: /Adicionar experiência/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await expect(page.locator('body')).toContainText(titulo);
    await respirar(page, 1500);
    await sair(page);

    // ---------------------------------------------------------------- cenário 2
    await legenda(page, 'Cenário 2 de 6', 'A empresa publica uma demanda com escopo, local e códigos da Tabela de Obras e Serviços');
    await entrar(page, EMPRESA);
    await page.goto('/demandas/nova');
    await respirar(page, 1200);

    const demandaTitulo = `Plano de intervenção urbana, área central de Manaus (${selo})`;
    await preencherDevagar(page.getByLabel(/Título/i), demandaTitulo);
    await page.getByLabel(/O que precisa ser feito/i).fill(
      'Elaboração de plano de intervenção urbana para a área central, com diagnóstico '
      + 'físico-territorial, diretrizes de uso e ocupação do solo e plano de ação por etapas.',
    );
    await page.getByLabel(/Município/i).fill('Manaus');
    await page.getByLabel(/^UF$/i).selectOption('AM');
    await respirar(page, 1200);
    await page.getByRole('button', { name: /Salvar rascunho/i }).click();
    await page.waitForURL(/\/demandas\/\d+/);

    const demandaId = Number(page.url().match(/\/demandas\/(\d+)/)[1]);

    await legenda(page, 'A demanda nasce rascunho', 'Sem código da Tabela de Obras e Serviços não há o que compatibilizar');
    await respirar(page, 2200);

    for (const codigo of [TOS_PRINCIPAL, TOS_SECUNDARIO]) {
      await page.getByLabel(/Procurar atividade/i).fill('Planejamento Urbano');
      await page.getByRole('button', { name: /Procurar/i }).click();
      await page.waitForLoadState('domcontentloaded');
      await page.locator(`form:has(input[name="codigo"][value="${codigo}"])`).first()
        .getByRole('button', { name: /Acrescentar/i }).click();
      await page.waitForLoadState('domcontentloaded');
      await respirar(page, 900);
    }

    await legenda(page, 'Publicar', 'A demanda entra no ar e o motor pode compatibilizá-la');
    await page.getByRole('button', { name: /Publicar/i }).first().click();
    await page.waitForLoadState('domcontentloaded');
    await respirar(page, 1800);

    // ---------------------------------------------------------------- cenário 3
    await legenda(page, 'Cenário 3 de 6', 'O sistema apresenta os compatíveis e explica os critérios que usou');
    await page.goto(`/demandas/${demandaId}/compativeis`);
    await respirar(page, 2000);

    await rolarAte(page, '.pl-dim, .pl-bar');
    await legenda(page, 'Aderência por dimensão, nunca nota única', 'O item 10.1 do edital veda ranking de profissionais: a ordem da lista vem de uma semente sorteada e registrada');
    await respirar(page, 3500);
    await sair(page);

    // ---------------------------------------------------------------- cenário 4
    await legenda(page, 'Cenário 4 de 6', 'O profissional manifesta interesse, e a empresa vê o perfil');
    await entrar(page, PROFISSIONAL);
    await page.goto(`/demandas/${demandaId}/manifestar`);
    await respirar(page, 1500);

    await legenda(page, 'O retrato do perfil', 'O demandante recebe o que o titular tinha aberto, congelado no instante do envio');
    await respirar(page, 3000);

    await page.getByRole('button', { name: /Manifestar interesse/i }).click();
    await page.waitForURL(/\/manifestacoes/);
    await respirar(page, 1800);
    await sair(page);

    await entrar(page, EMPRESA);
    await page.goto(`/demandas/${demandaId}/interessados`);
    await legenda(page, 'Do outro lado', 'A empresa vê quem se interessou e abre o perfil que recebeu');
    await respirar(page, 2500);
    await sair(page);

    // ---------------------------------------------------------------- cenário 5
    await legenda(page, 'Cenário 5 de 6', 'O titular corrige um dado, restringe outro e registra uma denúncia');
    await entrar(page, PROFISSIONAL);
    await page.goto('/perfil');
    await rolarAte(page, 'select[name="nivel[PERFIL:-:RESUMO]"]');
    await legenda(page, 'Visibilidade campo a campo', 'Nada é público por padrão, e não existe superusuário que abra perfil fechado');
    await page.locator('select[name="nivel[PERFIL:-:RESUMO]"]').selectOption('PRIVADO');
    await respirar(page, 2200);
    await page.getByRole('button', { name: /Salvar visibilidade/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await respirar(page, 1500);

    await page.goto(`/denuncias/nova?entidade=DEMANDA&alvo=${demandaId}`);
    await legenda(page, 'Denúncia', 'Sempre contra um alvo, nunca genérica');
    await page.getByLabel(/Tipo de denúncia/i).selectOption({ index: 1 });
    await page.getByLabel(/Descrição/i).fill(
      'Registro de demonstração: a demanda descreve escopo que não corresponde ao objeto anunciado.',
    );
    await respirar(page, 1800);
    await page.getByRole('button', { name: /Enviar denúncia/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await respirar(page, 1500);
    await sair(page);

    // ---------------------------------------------------------------- cenário 6
    await legenda(page, 'Cenário 6 de 6', 'A administração lê a trilha de auditoria e atua na moderação');
    await entrar(page, ADMIN);

    await page.goto('/admin');
    await legenda(page, 'Visão geral', 'Contagem agregada, nunca lista de pessoas ordenada por mérito');
    await respirar(page, 2500);

    await page.goto('/admin/auditoria');
    await legenda(page, 'Trilha de auditoria', 'Insert-only no banco, protegida por trigger: nem o administrador altera o que já foi registrado');
    await respirar(page, 3000);

    await page.goto('/admin/denuncias?situacao=PENDENTE');
    await respirar(page, 1200);
    const primeira = page.locator('a[href*="/admin/denuncias/"]').first();

    if (await primeira.count()) {
      await primeira.click();
      await page.waitForURL(/\/admin\/denuncias\/\d+/);
      await legenda(page, 'Moderação', 'Advertir, bloquear, remover ou julgar improcedente, e tudo fica na trilha');
      await page.getByLabel(/Situação/i).selectOption('EM_ANALISE');
      await respirar(page, 1800);
      await page.getByRole('button', { name: /Registrar providência/i }).click();
      await page.waitForLoadState('domcontentloaded');
      await respirar(page, 1500);
    }

    await page.goto('/admin/sessoes');
    await legenda(page, 'Sessões do motor', 'Cada execução guarda a semente que ordenou a lista, e pode ser reproduzida depois');
    await respirar(page, 3000);

    await legenda(page, 'Pro-Link', 'Equipe 49/51 · Desafio CREA Pro-Link · II CENATEC 2026');
    await respirar(page, 3000);

    await sair(page);

    expect(confirmacoes.textos.length, 'nenhuma ação irreversível pediu confirmação')
      .toBeGreaterThan(0);

    // O vídeo só existe depois que o contexto fecha, e o caminho é resolvido pelo Playwright.
    // Guardar o nome aqui é o que permite copiá-lo para um lugar previsível no fim da execução.
    info.annotations.push({ type: 'video', description: await page.video()?.path() ?? '(sem vídeo)' });
  });

  test.afterAll(async () => {
    // Nada a fazer se a execução não gravou: `video` pode estar desligado numa rodada de
    // depuração, e o teste não deve falhar por causa disso.
  });
});

/**
 * Prepara a faixa de legenda.
 *
 * O estilo é injetado uma vez e sobrevive à navegação por `addInitScript`, que roda a cada
 * documento novo. Sem isso a legenda sumiria no primeiro clique que troca de página.
 */
async function prepararLegenda(pagina) {
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
async function legenda(pagina, titulo, texto) {
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
async function respirar(pagina, ms) {
  await pagina.waitForTimeout(ms);
  await pagina.evaluate(() => document.getElementById('pl-legenda')?.classList.remove('vis'));
  await pagina.waitForTimeout(280);
}

/** Rola até o elemento com suavidade, para o vídeo não dar saltos. */
async function rolarAte(pagina, seletor) {
  const alvo = pagina.locator(seletor).first();

  if (await alvo.count()) {
    await alvo.evaluate((no) => no.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    await pagina.waitForTimeout(700);
  }
}

/** Digita em ritmo humano. Só onde o vídeo ganha com isso. */
async function preencherDevagar(localizador, texto) {
  await localizador.click();
  await localizador.pressSequentially(texto, { delay: 18 });
}
