import { test, expect } from '@playwright/test';
import { ADMIN, EMPRESA, PROFISSIONAL_DEMO, TOS_PRINCIPAL, TOS_SECUNDARIO } from '../apoio/contas.js';
import { aceitarConfirmacoes, entrar, manifestar, sair } from '../apoio/acoes.js';
import { legenda, preencherDevagar, prepararLegenda, respirar, rolarAte } from '../apoio/legenda.js';

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
    // `test.slow()` triplica o tempo do arquivo de configuração, e não basta: as pausas de
    // legenda somam perto de um minuto, e a jornada tem vinte navegações. O teto aqui é
    // deliberado, e é o único teste do repositório que precisa dele: os outros falham rápido de
    // propósito.
    test.setTimeout(300_000);

    const confirmacoes = aceitarConfirmacoes(page);
    const selo = new Date().toISOString().slice(11, 19).replace(/:/g, '');

    await prepararLegenda(page);

    // ---------------------------------------------------------------- abertura
    await page.goto('/');
    await legenda(page, 'Pro-Link', 'Demandas técnicas e profissionais do CREA, ligados por evidência documental');
    await respirar(page, 2500);

    // ---------------------------------------------------------------- cenário 1
    await legenda(page, 'Cenário 1 de 6', 'O profissional vê o acervo que a API oficial do CREA confirmou');
    await entrar(page, PROFISSIONAL_DEMO);
    await page.goto('/perfil#experiencia');
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
    await page.getByLabel(/Descrição do escopo/i).fill(
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
    await entrar(page, PROFISSIONAL_DEMO);
    await page.goto(`/demandas/${demandaId}/manifestar`);
    await respirar(page, 1500);

    await legenda(page, 'O retrato do perfil', 'O demandante recebe o que o titular tinha aberto, congelado no instante do envio');
    await respirar(page, 3000);

    // A ida acima foi para mostrar o retrato; `manifestar()` recarrega a mesma tela e envia.
    await manifestar(page, demandaId);
    await respirar(page, 1800);
    await sair(page);

    await entrar(page, EMPRESA);
    await page.goto(`/demandas/${demandaId}/contatos`);
    await legenda(page, 'Do outro lado', 'A empresa vê a candidatura em Contatos e abre o perfil que recebeu');
    await respirar(page, 2500);
    await sair(page);

    // ---------------------------------------------------------------- cenário 5
    await legenda(page, 'Cenário 5 de 6', 'O titular corrige um dado, restringe outro e registra uma denúncia');
    await entrar(page, PROFISSIONAL_DEMO);
    await page.goto('/perfil#privacidade');
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
    await page.getByLabel(/O que aconteceu/i).fill(
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
      await page.getByLabel(/Situação da apuração|^Situação$/i).selectOption('EM_ANALISE');
      await respirar(page, 1800);
      await page.getByRole('button', { name: /Registrar a decisão|Atualizar a decisão|Registrar providência/i }).click();
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

    // O vídeo **não** é copiado daqui. `video.saveAs()` espera o arquivo fechar, e o arquivo só
    // fecha quando o contexto do teste fecha, que acontece depois deste bloco: chamá-lo aqui
    // trava o teste até o tempo estourar, e foi o que aconteceu na primeira escrita.
    //
    // A cópia é um passo de fora, documentado em `e2e/README.md`:
    //
    //     npx playwright test specs/demonstracao.spec.js \
    //       && cp resultados/demonstracao*/video.webm videos/demonstracao-jornada-completa.webm
    info.annotations.push({ type: 'vídeo', description: 'resultados/demonstracao*/video.webm' });
  });
});
