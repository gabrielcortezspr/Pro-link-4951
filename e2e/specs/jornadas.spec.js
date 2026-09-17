// @ts-check
import { test, expect } from '@playwright/test';
import { ADMIN, EMPRESA, PROFISSIONAL_DEMO } from '../apoio/contas.js';
import { aceitarConfirmacoes, entrar, sair, telaSaudavel } from '../apoio/acoes.js';
import { legenda, prepararLegenda, respirar, rolarAte } from '../apoio/legenda.js';

/**
 * Uma jornada por perfil, cada uma no seu vídeo.
 *
 * `demonstracao.spec.js` grava **um** vídeo com os seis cenários do Anexo I, item 7, na ordem em
 * que eles são numerados: é a narrativa do desafio, e ela salta de conta em conta porque o
 * cenário 4 exige os dois lados da mesma interação.
 *
 * Aqui a pergunta é outra, e foi a autora quem fez: *"faz para todas as entidades que vão usar o
 * app, e nessas contas passa por todos os fluxos que eles pediram"*. Então cada vídeo fica com
 * **uma conta só**, do login ao logout, percorrendo tudo que o Anexo I, item 3, diz que aquele
 * perfil precisa poder fazer. Quem avalia consegue perguntar "o que um profissional faz aqui?" e
 * ver a resposta inteira, sem trocar de papel no meio.
 *
 * O vídeo sai em `resultados/`, um por teste, porque o arquivo de configuração grava por contexto.
 * Para guardá-los com nome:
 *
 *     ./rodar.sh specs/jornadas.spec.js --project=desktop
 *     for d in resultados/jornadas-*; do
 *       cp "$d/video.webm" "videos/$(basename "$d" | sed 's/jornadas-Jornadas-por-perfil-//').webm"
 *     done
 *
 * As conferências são as mínimas para o vídeo não gravar uma tela quebrada sem ninguém notar. A
 * prova dura continua em `cenarios.spec.js` e `seguranca.spec.js`.
 */
test.describe('Jornadas por perfil', () => {
  // Cada jornada é longa de propósito: são as pausas que tornam o vídeo legível.
  test.describe.configure({ mode: 'serial' });

  test('a jornada do profissional, do acervo verificado à privacidade', async ({ page }) => {
    test.setTimeout(240_000);

    aceitarConfirmacoes(page);
    await prepararLegenda(page);

    await page.goto('/');
    await legenda(page, 'O profissional', 'Quem tem registro no Sistema Confea/CREA e quer aparecer para quem contrata');
    await respirar(page, 2600);

    await entrar(page, PROFISSIONAL_DEMO);

    await page.goto('/inicio');
    await telaSaudavel(page);
    await legenda(page, 'Início', 'Onde ele apareceu, o que enviou, e o que o acervo dele sustenta');
    await respirar(page, 3000);

    // ---- O acervo verificado, que é o argumento do projeto
    await page.goto('/perfil#preferencias');
    await telaSaudavel(page);
    await legenda(page, 'Acervo técnico', 'ARTs importadas da API oficial do CREA-AM, com o selo reconferido a cada exibição');
    await respirar(page, 3400);

    await rolarAte(page, '.pl-acervo, [class*="acervo"]');
    await legenda(page, 'Verificado e declarado não se misturam', 'O selo é do que o conselho registrou; o resto é o que a pessoa escreveu, e a tela separa os dois');
    await respirar(page, 3600);

    // ---- Correção de dado autodeclarado
    const resumo = 'Engenharia mecânica com foco em manutenção industrial e perícia técnica.';
    const campo = page.getByLabel(/^Resumo profissional/i);

    if (await campo.count()) {
      await rolarAte(page, 'label[for="campo-resumo"]');
      await campo.fill(resumo);
      await legenda(page, 'Correção', 'Dado autodeclarado é do titular, e cada edição guarda o valor anterior na trilha');
      await respirar(page, 2600);
      await page.getByRole('button', { name: /Salvar preferências/i }).click();
      await page.waitForLoadState('domcontentloaded');
      await telaSaudavel(page);
    }

    // ---- Visibilidade por campo
    await page.goto('/perfil#privacidade');
    await rolarAte(page, 'select[name="nivel[PERFIL:-:RESUMO]"]');
    await legenda(page, 'Quem vê o quê', 'Três níveis, campo por campo e documento por documento. O padrão de tudo é "Só eu"');
    await respirar(page, 3400);

    const nivel = page.locator('select[name="nivel[PERFIL:-:RESUMO]"]');

    if (await nivel.count()) {
      await nivel.selectOption('PUBLICO');
      await page.getByRole('button', { name: /Salvar visibilidade/i }).click();
      await page.waitForLoadState('domcontentloaded');
      await telaSaudavel(page);
      await legenda(page, 'Nada é público sem escolha', 'A mudança vale a partir de agora, e fica registrada');
      await respirar(page, 2600);
    }

    // ---- Onde ele procura trabalho
    await page.goto('/demandas/abertas');
    await telaSaudavel(page);
    await legenda(page, 'Demandas abertas', 'O que foi publicado por quem contrata, com o código da Tabela de Obras e Serviços');
    await respirar(page, 3200);

    // ---- O que ele já enviou
    await page.goto('/manifestacoes');
    await telaSaudavel(page);
    await legenda(page, 'Meus interesses', 'O que foi enviado, e o retrato do perfil congelado no instante do envio');
    await respirar(page, 3400);

    // ---- Privacidade, que é requisito e não enfeite
    await page.goto('/privacidade');
    await telaSaudavel(page);
    await legenda(page, 'Privacidade', 'Consentimentos com data e hora, exportação em JSON, sessões abertas e exclusão da conta');
    await respirar(page, 4000);

    await sair(page);
  });

  test('a jornada da empresa, da demanda publicada ao interesse registrado', async ({ page }) => {
    test.setTimeout(240_000);

    aceitarConfirmacoes(page);
    await prepararLegenda(page);

    await page.goto('/');
    await legenda(page, 'A empresa', 'Publica demanda e também é candidata: o acervo dela vem do quadro técnico');
    await respirar(page, 2600);

    await entrar(page, EMPRESA);

    await page.goto('/inicio');
    await telaSaudavel(page);
    await legenda(page, 'Início', 'O que ela publicou, quem chegou, e o acervo que o quadro técnico sustenta');
    await respirar(page, 3200);

    // ---- Publicar
    await page.goto('/demandas/nova');
    await telaSaudavel(page);
    await legenda(page, 'Publicar demanda', 'A demanda nasce rascunho: sem código da Tabela de Obras e Serviços não há o que compatibilizar');
    await respirar(page, 3600);

    // ---- O que já existe
    await page.goto('/demandas');
    await telaSaudavel(page);
    await legenda(page, 'Minhas demandas', 'Rascunho, publicada, com interessados, encerrada. A situação tem vocabulário fixo e cor fixa');
    await respirar(page, 3400);

    // ---- O motor, que é o diferencial declarado
    const feed = page.locator('a[href*="/demandas/"][href$="/compativeis"]').first();

    if (await feed.count()) {
      await page.goto((await feed.getAttribute('href')) ?? '/demandas');
      await telaSaudavel(page);
      await legenda(page, 'Compatíveis', 'Um candidato por vez, com a aderência aberta por dimensão em vez de uma nota só');
      await respirar(page, 4000);

      await legenda(page, 'Isto não é ranking', 'O score decide quem entra no conjunto; a ordem vem de uma semente sorteada e gravada. É o item 10.1 do edital');
      await respirar(page, 4200);

      await legenda(page, 'Dimensão sem dado não é zero', 'Ela sai da média, porque perfil incompleto não pode ser punido: o edital pede inclusão de quem está começando');
      await respirar(page, 4000);
    }

    // ---- Quem chegou
    const interessados = page.locator('a[href*="/interessados"]').first();

    if (await interessados.count()) {
      await page.goto((await interessados.getAttribute('href')) ?? '/demandas');
      await telaSaudavel(page);
      await legenda(page, 'Quem se interessou', 'O perfil aberto aqui é o congelado no instante do envio, e não o de agora');
      await respirar(page, 3600);
    }

    // ---- A busca ativa
    await page.goto('/profissionais');
    await telaSaudavel(page);
    await legenda(page, 'Busca ativa', 'Por nome, especialidade ou atividade técnica. A correspondência é indicativa, e a tela diz isso');
    await respirar(page, 3600);

    await sair(page);
  });

  test('a jornada da administração, da trilha à reprodução do sorteio', async ({ page }) => {
    test.skip(!ADMIN.senha, 'exporte PROLINK_E2E_ADMIN_SENHA, ou use ./rodar.sh');
    test.setTimeout(240_000);

    aceitarConfirmacoes(page);
    await prepararLegenda(page);

    await page.goto('/');
    await legenda(page, 'A administração', 'Quem audita, modera e responde pela operação. Ela também é auditada');
    await respirar(page, 2600);

    await entrar(page, ADMIN);

    await page.goto('/admin');
    await telaSaudavel(page);
    await legenda(page, 'Visão geral', 'Contagem agregada. Nenhuma lista de pessoas ordenada por nada: é aqui que um ranking apareceria disfarçado de métrica');
    await respirar(page, 4000);

    await page.goto('/admin/auditoria');
    await telaSaudavel(page);
    await legenda(page, 'Trilha de auditoria', 'Insert-only por gatilho no banco: nem a administração altera o que já foi registrado');
    await respirar(page, 4000);

    await page.goto('/admin/sessoes');
    await telaSaudavel(page);
    await legenda(page, 'Sessões do motor', 'Cada compatibilização fica gravada com a semente que ordenou o conjunto');
    await respirar(page, 3400);

    const sessao = page.locator('a[href*="/admin/sessoes/"]').first();

    if (await sessao.count()) {
      await page.goto((await sessao.getAttribute('href')) ?? '/admin/sessoes');
      await telaSaudavel(page);
      await legenda(page, 'A reprodução acontecendo', 'A plataforma refaz o sorteio a partir da semente e compara com o gravado, na frente de quem audita');
      await respirar(page, 4400);
    }

    await page.goto('/admin/denuncias');
    await telaSaudavel(page);
    await legenda(page, 'Moderação', 'Denúncia é sempre contra um alvo, e a providência vai para a trilha com o nome de quem decidiu');
    await respirar(page, 3600);

    await page.goto('/admin/contas');
    await telaSaudavel(page);
    await legenda(page, 'Gerir perfis', 'Bloquear exige motivo escrito. Conta excluída pelo titular não volta por ato administrativo');
    await respirar(page, 3800);

    await page.goto('/admin/parametros');
    await telaSaudavel(page);
    await legenda(page, 'Parâmetros do motor', 'Os pesos são editáveis, com faixa declarada por campo, e o antes e o depois vão para a trilha');
    await respirar(page, 4000);

    await page.goto('/admin/integracoes');
    await telaSaudavel(page);
    await legenda(page, 'Integrações', 'Para onde a plataforma aponta, o que já veio de lá, e desde quando. A tela não chama a API: o item 10.4 veda coleta automatizada');
    await respirar(page, 4200);

    await page.goto('/admin/lixeira');
    await telaSaudavel(page);
    await legenda(page, 'Lixeira', 'Nada é apagado. O item 8.6j do edital, com a restauração registrada e o que não se restaura');
    await respirar(page, 4000);

    await sair(page);
  });
});
