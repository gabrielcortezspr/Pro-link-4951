# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

14/09/2026 — Gabriel e Camila em paralelo, integrados no fim do dia em `integracao/design-e6`.

## Onde parou

**E2 quase fechada** (Gabriel): perfil com as duas metades e experiência autodeclarada ao lado,
nunca dentro, do acervo verificado. **E3 pronta** (Gabriel): demandas, códigos TOS, quatro telas.
**E6 pronta** (Camila): denúncia, moderação com a operação atômica 5, painel e trilha de auditoria
com filtro e paginação. Entrou junto o **design system** (`prolink.css`, tema sobre o Bootstrap) e
um **pipeline de padrão visual** que roda sozinho: `verificar-telas.php`, `verificar-padrao.php`,
hook `PostToolUse` e o agente `designer-ui`, que toda tela nova precisa atravessar.

Verificado no merge: 143 testes, E2 108, E6 19, 20 telas compilam, padrão visual sem violação.

## Próximo passo

**E4, o motor de compatibilização.** É o que falta de verdade: destrava o cenário 3, é chamado de
centro da avaliação no `backlog.md`, e é um dos dois diferenciais que a proposta prometeu. Nenhuma
linha existe. Desenho pronto em `docs/matching.md`; começar por
`Service/CompatibilizacaoService::executar()`, a operação atômica 2. Depois E5.

## Decisões pendentes

- `match.early_career.min_arts` vale `3`, escolhido por nós. Decidir antes das telas de feed e de
  busca ativa. O porquê e os números estão na E4 do `backlog.md`.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita?
- Nenhuma tela mostra `perfil.campos` (e-mail, telefone, resumo). A resposta natural é junto com
  `/perfil/{id}`.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b), e é o único
  dos seis itens do `_arq/` que ainda não existe.

## Lembrar

- **Recarregar o banco antes da demonstração** resolve dois problemas de uma vez: a massa de
  verificação suja a trilha ("Verificação E2", "Cobaia Bloqueio", 268 linhas de linha de comando)
  e o histórico anterior a hoje está 4h adiantado. `sis_auditoria` bloqueia UPDATE e DELETE por
  trigger, então não há outro caminho.
- **O relógio foi unificado** em `Database::conexao()`, que alinha o fuso do MariaDB ao do PHP.
  Registro novo grava a hora de Manaus. Não voltar a tratar `NOW()` e `date()` como divergentes.
- **Rode isto em máquina com volume anterior a 14/09** (banco novo já vem com tudo):
  ```sql
  ALTER TABLE pro_profissionais ADD CONSTRAINT uq_prf_usu UNIQUE (prf_usu_id);
  ALTER TABLE pro_empresas ADD CONSTRAINT uq_emp_usu UNIQUE (emp_usu_id);
  ALTER TABLE crea_quadro_tecnico ADD COLUMN qut_pro_nome VARCHAR(150) NULL AFTER qut_pro_rnp;
  -- D28: tira as duplicatas que o defeito gravou, mantendo a escolha mais recente
  DELETE v FROM pro_visibilidade v JOIN pro_visibilidade novo
    ON novo.vis_usu_id = v.vis_usu_id AND novo.vis_entidade = v.vis_entidade
   AND novo.vis_entidade_id <=> v.vis_entidade_id AND novo.vis_campo <=> v.vis_campo
   AND novo.vis_id > v.vis_id;
  ```
- **`ON DUPLICATE KEY UPDATE` só é confiável se nenhuma coluna do índice aceitar nulo.** Já custou
  duas vezes (D25, D28).
- **`PROLINK_API_TOKEN` é obrigatório mesmo sem chamar a API**: `/cadastro` constrói o
  `TransporteCurl`, que lança se o token faltar. E `docker compose restart` não relê o `.env`, use
  `docker compose up -d`.
- **`verificar-e1.php` acusa uma falha quando a API está acessível**: o script gera CNPJ sintético
  derivado do relógio e a D26 rebaixa para Terceiro PJ, mas a checagem espera EMPRESA. É
  acoplamento do teste, não regressão.
- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Gatilho é item da E5.
- `PrivacidadeService::exportar` não leva perfil, acervo nem experiências. O 11.3 está incompleto.
- Documento da massa usado uma vez fica consumido para sempre (D15). CPFs livres: `...290`,
  `...370`, `...451`. Do lado da empresa só as 15 da `massa-de-dados.md` passam no DV, e a que tem
  CAO capturado é a AMAZÔNIA (`00123001000123`, registro 61859).
- `ATTR_EMULATE_PREPARES` desligado: placeholder nomeado **não** pode repetir na mesma query.
- Contas locais: admin `camila@prolink.local` / `ProLinkDemo2026!`; cobaia de bloqueio
  `cobaia@prolink.local` (usu_id 10, devolvida ao estado ativo).
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
