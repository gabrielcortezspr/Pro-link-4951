# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

16/09/2026 — Gabriel, com Claude Code. Branch `e5/manifestacao`, seis commits.

## Onde parou

**E5 concluída, e com ela os seis cenários mínimos do edital rodam.** O profissional manifesta
interesse, o e-mail sai sozinho (o gatilho da fila faltava desde a E1), o demandante abre o perfil
congelado no envio e as duas partes conversam. Conferido pelo navegador, não só por script.

Verificado: 188 testes · 55 conferências de padrão com **0 violações** · 34 telas · E2 138 ·
E4 32 · E6 19.

## Próximo passo

**A E7**, que é a entrega, e o primeiro item dela é o que está sem dono há mais tempo: a
**declaração de uso de IA, vieses e limitações** (item 12.3 e Anexo VI). A matéria-prima são as 59
entradas de `docs/decisoes.md` — ela se escreve a partir de lá, não do zero.

Junto, e obrigatório: o **MER** em `_arq/mer/` (8.3.2b).

## Decisões pendentes

- **Servir Bootstrap e as fontes localmente** e fechar a CSP em `'self'` (Consequência da D50). No
  Demo Day presencial, sem internet, a apresentação é feita sem CSS. É o risco operacional maior.
- `prf_em_construcao` é derivado guardado em coluna: derivar na leitura (como a D01) ou ponto único
  de escrita?
- `ARQUIVADA` em `man_situacao`: quem arquiva, e o que isso significa para quem manifestou.

## Lembrar

- **A senha do admin não é a das contas semeadas.** `camila@prolink.local` tem senha própria, de
  desenvolvimento, fora de arquivo versionado.
- **`ParametroRepository` faz cache estático por processo.** Mudar `sis_parametros` e chamar o
  serviço na mesma execução lê o valor antigo. Custou um falso positivo nesta sessão.
- **`verificar-e4.php` grava duas sessões a cada execução**, e a primeira página de
  `/admin/sessoes` está com dezenas de linhas de teste. Limpar antes do pitch.
- **Antes da demonstração**: recarregar o banco e rodar `semear-candidatos.php`,
  `abrir-visibilidade-demo.php` e `preencher-declarados-demo.php`, nesta ordem.
- **Escreva as demandas do roteiro olhando o índice**, nunca antes.
- `verificar-api.php` consome chamadas registradas: nunca rodar em laço sobre `verificar-*.php`.
- Pendências de front anotadas: vitrine sem filtro por UF ou atividade, navegação sem estado
  `.active`, e `dem_titulo` renderizado cru em cinco telas.
