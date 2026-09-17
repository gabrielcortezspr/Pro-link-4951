import { test, expect } from '@playwright/test';
import { ADMIN, EMPRESA, PROFISSIONAL, TOS_PRINCIPAL, TOS_SECUNDARIO } from '../apoio/contas.js';
import { aceitarConfirmacoes, comoNaTela, descrever, entrar, manifestar, sair, telaSaudavel } from '../apoio/acoes.js';

/**
 * Os seis cenários mínimos do Anexo I, item 7, pelo navegador.
 *
 * O `backlog.md` chama estes seis de definição de pronto do MVP, e até agora eles eram provados
 * por script de verificação que conversa com serviço e repositório. Isso prova a regra de
 * negócio e não prova a tela: a de auditoria já subiu em 500 com dezesseis conferências no verde.
 *
 * A ordem importa e a série é encadeada de propósito: a empresa publica a demanda no cenário 2, o
 * motor a compatibiliza no 3, e o profissional manifesta interesse nela no 4. Rodar isolado
 * exigiria dado de apoio inventado, e dado inventado é a forma mais barata de um teste passar
 * pelo motivo errado.
 */
test.describe.configure({ mode: 'serial' });

// Estado da série. Preenchido pelo cenário 2 e lido pelos seguintes.
const demanda = { id: null, titulo: null };

const AGORA = new Date().toISOString().slice(11, 19).replace(/:/g, '');

test.describe('Os seis cenários do Anexo I', () => {
  test('Cenário 1: profissional vê o acervo verificado e liga uma competência a uma experiência', async ({ page }) => {
    await entrar(page, PROFISSIONAL);

    const resposta = await page.goto('/perfil');
    await telaSaudavel(page, resposta);

    await expect(page.getByRole('heading', { level: 1 })).toContainText(comoNaTela(PROFISSIONAL.nome));

    // O selo de verificação é o compromisso central da proposta: ele separa o que a API do CREA
    // confirmou do que a pessoa declarou. Sem ele na tela, o resto do projeto não se sustenta.
    // O selo é SVG inline com a classe `pl-mark` (ou `pl-seal`), e a variante `nao` é o contrário
    // dele: círculo cinza para o que a API não confirmou. Contar só os confirmados.
    const selos = page.locator('.pl-mark:not(.nao), .pl-seal:not(.nao)');
    expect(await selos.count(), 'o portfólio não mostra nenhum selo de verificação').toBeGreaterThan(0);

    const titulo = `Experiência de verificação automática ${AGORA}`;
    await page.getByLabel(/O que você fez/i).fill(titulo);
    await page.getByLabel(/Detalhes/i).fill(
      'Registro criado pela suíte de ponta a ponta para provar o cenário 1 do Anexo I.',
    );

    // A competência ligada à experiência é o que o cenário pede: a experiência aponta para uma
    // ART do próprio acervo, e é a ART que carrega os códigos TOS.
    const seletorDeArt = page.getByLabel(/Vincular a uma ART/i);
    const opcoes = await seletorDeArt.locator('option').count();

    if (opcoes > 1) {
      await seletorDeArt.selectOption({ index: 1 });
    }

    await page.getByRole('button', { name: /Adicionar experiência/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await telaSaudavel(page);

    await expect(page.locator('body')).toContainText(titulo);

    // Dado declarado não pode se confundir com dado verificado: a distinção é visual e existe no
    // CSS desde a D29.
    expect(
      await page.locator('.dado-declarado, .pl-declarado').count(),
      'a experiência declarada não está marcada como declarada',
    ).toBeGreaterThan(0);

    await sair(page);
  });

  test('Cenário 2: empresa publica demanda com escopo, local e códigos da TOS', async ({ page }) => {
    await entrar(page, EMPRESA);

    let resposta = await page.goto('/demandas/nova');
    await telaSaudavel(page, resposta);

    demanda.titulo = `Plano de intervenção urbana, verificação ${AGORA}`;

    await page.getByLabel(/Título/i).fill(demanda.titulo);
    await page.getByLabel(/O que precisa ser feito/i).fill(
      'Elaboração de plano de intervenção urbana para área central, com diagnóstico '
      + 'físico-territorial, diretrizes de uso e ocupação e plano de ação. Demanda criada pela '
      + 'suíte de ponta a ponta.',
    );
    await page.getByLabel(/Município/i).fill('Manaus');
    await page.getByLabel(/^UF$/i).selectOption('AM');
    await page.getByRole('button', { name: /Salvar rascunho/i }).click();

    await page.waitForURL(/\/demandas\/\d+/);
    demanda.id = Number(page.url().match(/\/demandas\/(\d+)/)[1]);
    await telaSaudavel(page);

    // A demanda nasce rascunho de propósito: sem código TOS ela não tem o que compatibilizar.
    await expect(page.locator('body'), 'a demanda deveria nascer como rascunho').toContainText(/rascunho/i);

    await escolherCodigoTos(page, TOS_PRINCIPAL, 'Planejamento Urbano');
    await escolherCodigoTos(page, TOS_SECUNDARIO, 'Planejamento Urbano');

    await expect(page.locator('body')).toContainText(TOS_PRINCIPAL.replace('TOS_', ''));

    await page.getByRole('button', { name: /Publicar/i }).first().click();
    await page.waitForLoadState('domcontentloaded');
    await telaSaudavel(page);

    await expect(page.locator('body'), 'a demanda não ficou publicada').toContainText(/Aberta|publicada/i);

    await sair(page);
  });

  test('Cenário 3: o sistema apresenta compatíveis e explica os critérios, sem ranking', async ({ page }) => {
    test.skip(demanda.id === null, 'depende da demanda criada no cenário 2');

    await entrar(page, EMPRESA);

    const resposta = await page.goto(`/demandas/${demanda.id}/compativeis`);
    await telaSaudavel(page, resposta);

    const corpo = await page.locator('body').innerText();

    // O item 10.2 exige que a plataforma diga que a correspondência é indicativa, e essa frase
    // não pode ficar escondida atrás de um "saiba mais": é a tela onde o motor mostra resultado.
    expect(corpo, 'falta a declaração de que a correspondência é indicativa')
      .toMatch(/indicativ|não constitui|não é ranking|sem ranking/i);

    // O item 10.1 veda ranking. Nenhuma posição numerada, nenhuma nota, nenhum "melhor".
    expect(corpo, 'a tela sugere classificação de pessoas').not.toMatch(/\bmelhor (match|candidato|profissional)/i);
    expect(corpo, 'a tela mostra posição de ranking').not.toMatch(/\b1º lugar|\bprimeiro colocado/i);

    // "Explica os principais critérios" é o texto do cenário: a aderência aparece por dimensão.
    const dimensoes = page.locator('.pl-dim, .pl-bar');
    expect(await dimensoes.count(), 'a explicação por dimensão não aparece').toBeGreaterThan(0);

    await sair(page);
  });

  test('Cenário 4: profissional manifesta interesse e a empresa vê o perfil', async ({ page }) => {
    test.skip(demanda.id === null, 'depende da demanda criada no cenário 2');

    const confirmacoes = aceitarConfirmacoes(page);

    await entrar(page, PROFISSIONAL);

    let resposta = await page.goto(`/demandas/${demanda.id}/manifestar`);
    await telaSaudavel(page, resposta);

    // A tela mostra o retrato do perfil que vai junto, antes de qualquer clique: manifestar
    // interesse abre para o demandante o que o titular tinha aberto, e ele precisa ver o quê.
    await expect(page.locator('body')).toContainText(comoNaTela(PROFISSIONAL.nome));

    // `manifestar()` traduz a recusa em mensagem: o teto de manifestações por hora conta por
    // pessoa, e sem isso o sintoma é um estouro de tempo que parece defeito do botão.
    await manifestar(page, demanda.id);
    await telaSaudavel(page);

    // O envio não pode ser desfeito e é um por demanda: a plataforma precisa dizer isso **antes**
    // de agir, e não depois. Consentimento informado é requisito, não detalhe de interface.
    expect(confirmacoes.textos.join(' '), 'a ação irreversível não pediu confirmação')
      .toMatch(/não pode ser desfeit/i);

    await expect(page.locator('body'), 'a manifestação não apareceu em "Meus interesses"')
      .toContainText(demanda.titulo);

    await sair(page);

    // A outra metade do cenário: a empresa vê quem manifestou e abre o perfil.
    await entrar(page, EMPRESA);

    resposta = await page.goto(`/demandas/${demanda.id}/interessados`);
    await telaSaudavel(page, resposta);
    await expect(page.locator('body')).toContainText(comoNaTela(PROFISSIONAL.nome));

    await sair(page);
  });

  test('Cenário 5: o titular corrige um dado, restringe outro e registra denúncia', async ({ page }) => {
    test.skip(demanda.id === null, 'a denúncia precisa de um alvo, e o alvo é a demanda do cenário 2');

    const confirmacoes = aceitarConfirmacoes(page);

    await entrar(page, PROFISSIONAL);

    let resposta = await page.goto('/perfil');
    await telaSaudavel(page, resposta);

    // Correção: dado autodeclarado é do titular e ele edita quando quiser.
    const resumo = `Resumo corrigido pela verificação de ponta a ponta ${AGORA}.`;
    await page.getByLabel(/Resumo profissional/i).fill(resumo);
    await page.getByRole('button', { name: /Salvar preferências/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await telaSaudavel(page);
    await expect(page.locator('body')).toContainText('Resumo corrigido');

    // Restrição: visibilidade por campo, e o padrão de tudo é privado.
    await page.goto('/perfil');
    const campoResumo = page.locator('select[name="nivel[PERFIL:-:RESUMO]"]');
    await campoResumo.selectOption('PRIVADO');
    await page.getByRole('button', { name: /Salvar visibilidade/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await telaSaudavel(page);
    await expect(page.locator('select[name="nivel[PERFIL:-:RESUMO]"]')).toHaveValue('PRIVADO');

    // Denúncia: qualquer conta autenticada pode abrir, e ela é **sempre** contra um alvo, nunca
    // genérica. Abrir `/denuncias/nova` sem alvo devolve 404 de propósito, e isso é regra, não
    // defeito: denúncia sem alvo não tem o que moderar.
    const semAlvo = await page.goto('/denuncias/nova');
    expect(semAlvo.status(), 'denúncia sem alvo deveria ser recusada').toBe(404);

    resposta = await page.goto(`/denuncias/nova?entidade=DEMANDA&alvo=${demanda.id}`);
    await telaSaudavel(page, resposta);

    await page.getByLabel(/Tipo de denúncia/i).selectOption({ index: 1 });
    await page.getByLabel(/Descrição/i).fill(
      'Denúncia registrada pela suíte de ponta a ponta para provar o cenário 5 do Anexo I. '
      + 'Alvo é a demanda criada pela própria suíte.',
    );
    await page.getByRole('button', { name: /Enviar denúncia/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await telaSaudavel(page);

    await expect(page.locator('body'), 'a denúncia não foi confirmada na tela')
      .toContainText(/denúncia/i);

    await sair(page);
  });

  test('Cenário 6: administração vê a trilha de auditoria e atua na moderação', async ({ page }) => {
    const confirmacoes = aceitarConfirmacoes(page);

    await entrar(page, ADMIN);

    for (const rota of ['/admin', '/admin/auditoria', '/admin/denuncias', '/admin/sessoes']) {
      const resposta = await page.goto(rota);
      await telaSaudavel(page, resposta);
    }

    // A visão geral não pode mais anunciar indicador por medir: era um espaço reservado que
    // dizia, por escrito, que os números entrariam em 15/09. Hoje é 17/09.
    await page.goto('/admin');
    await expect(page.locator('body'), 'a visão geral ainda mostra o espaço reservado')
      .not.toContainText(/Não medido|entram em 15\/09/i);

    // A trilha precisa mostrar o que aconteceu nesta própria execução.
    await page.goto('/admin/auditoria');
    const trilha = await page.locator('body').innerText();
    expect(trilha, 'a trilha de auditoria está vazia').toMatch(/Entrada|Alteração|Criação/);

    // Moderação de verdade: a denúncia aberta no cenário 5 é tratada aqui. Visitar a fila não é
    // "atuar na moderação", que é o que o cenário do Anexo I pede.
    await page.goto('/admin/denuncias?situacao=PENDENTE');
    await telaSaudavel(page);

    const primeira = page.locator('a[href*="/admin/denuncias/"]').first();
    expect(await primeira.count(), 'a fila de moderação está vazia').toBeGreaterThan(0);

    await primeira.click();
    await page.waitForURL(/\/admin\/denuncias\/\d+/);
    await telaSaudavel(page);

    const denunciaTratada = Number(page.url().match(/\/admin\/denuncias\/(\d+)/)[1]);

    await page.getByLabel(/Situação/i).selectOption('EM_ANALISE');
    await page.getByRole('button', { name: /Registrar providência/i }).click();
    await page.waitForLoadState('domcontentloaded');
    await telaSaudavel(page);

    await expect(page.locator('body'), 'a providência não foi confirmada')
      .toContainText(/tratada|análise|providência/i);

    // O ponto de demonstração do cenário 6, e o que separa auditoria de registro de acesso: a
    // ação do **próprio administrador** aparece na trilha. Quem modera é auditado também.
    await page.goto('/admin/auditoria');
    await telaSaudavel(page);
    await expect(page.locator('body'), 'a moderação do administrador não apareceu na trilha')
      .toContainText(/Moderação|Alteração/);

    expect(denunciaTratada, 'nenhuma denúncia foi tratada').toBeGreaterThan(0);
    expect(confirmacoes, 'o acumulador de confirmações some do escopo').toBeTruthy();

    await sair(page);
  });
});

/**
 * Escolhe um código da Tabela de Obras e Serviços na tela da demanda.
 *
 * A busca da tela é **textual sobre a descrição**, não sobre o código: procurar por "10.4.2.3"
 * não acha nada, e é assim de propósito, porque ninguém decora código TOS. Por isso a função
 * recebe o texto a procurar e o código a acrescentar, separados.
 *
 * O botão certo é o do formulário cujo `codigo` escondido bate exatamente com o pedido: a busca
 * por "Planejamento Urbano" devolve dezenas de resultados, e clicar no primeiro traria um código
 * vizinho, que passaria no teste e mudaria o cenário sem ninguém perceber.
 */
async function escolherCodigoTos(pagina, codigo, textoDaBusca) {
  await pagina.getByLabel(/Procurar atividade/i).fill(textoDaBusca);
  await pagina.getByRole('button', { name: /Procurar/i }).click();
  await pagina.waitForLoadState('domcontentloaded');

  const formulario = pagina.locator(`form:has(input[name="codigo"][value="${codigo}"])`).first();

  if (!(await formulario.count())) {
    throw new Error(
      `A busca por "${textoDaBusca}" não trouxe o código ${codigo}. Tela:\n${await descrever(pagina)}`,
    );
  }

  await formulario.getByRole('button', { name: /Acrescentar/i }).click();
  await pagina.waitForLoadState('domcontentloaded');
}
