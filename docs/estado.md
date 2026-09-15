# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

14/09/2026, madrugada. Camila: integração das duas frentes, E6 fechada e o motor da E4.

## Onde parou

**Tudo integrado na main.** O design system, a E6 inteira (denúncia, moderação, painel, trilha de
auditoria) e o pipeline de padrão visual entraram junto com a E3 e a experiência declarada.

**O motor da E4 está pronto e verificado**, em `claude/e4-motor`: a operação atômica 2 completa,
das seis dimensões até a sessão gravada com semente auditável. Falta só a camada visível.

**A base foi povoada pelo fluxo real**: 19 candidatos, 115 linhas de evidência, 41 ARTs, 81
códigos TOS, por `scripts/semear-candidatos.php`.

Verificado: 176 testes · E2 138 · E4 30 · E6 19 · 24 telas compilam · padrão visual sem violação.

## Próximo passo

**O feed do demandante**, que é a saída da E4 e o cenário 3. O pool já sai de
`CompatibilizacaoService::executar()` embaralhado e com `criterios` por candidato: score por
dimensão, quais dimensões saíram da média, e as ARTs que sustentam cada código. Falta rota,
controller e tela.

O mockup está aprovado em `docs/mockups/prolink-feed-imersivo-v3.html`. **Tela nova passa pelo
agente `designer-ui` antes de virar código** (ver `CLAUDE.md`, "Tela não se escreve direto").

Depois: a explicação de compatibilidade no card, `/admin/sessoes/{id}`, e a E5.

## Decisões pendentes

- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita?
- Nenhuma tela mostra `perfil.campos` (e-mail, telefone, resumo). A resposta natural é junto com
  `/perfil/{id}`.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b), e é o único
  dos seis itens do `_arq/` que ainda não existe.

## Lembrar

- **Recarregar o banco antes da demonstração** resolve dois problemas de uma vez: a massa de
  verificação suja a trilha e o feed ("Verificação E2", "Cobaia Bloqueio"), e o histórico de
  auditoria anterior a 14/09 está 4h adiantado. `sis_auditoria` bloqueia UPDATE e DELETE por
  trigger, então não há outro caminho. Depois de recarregar, rode `semear-candidatos.php`.
- **O relógio foi unificado** em `Database::conexao()`, que alinha o fuso do MariaDB ao do PHP
  (D40). Não voltar a tratar `NOW()` e `date()` como divergentes.
- **`semear-candidatos.php` consome chamada da API**, duas por candidato. Tem limite obrigatório
  de propósito: cadastro individual não é varredura (10.4), mas duzentos seguidos pareceriam.
- **`pro_profissionais` tem UNIQUE em `prf_rnp`.** Contas de verificação antigas seguram RNPs da
  massa, inclusive o de ANA CLARA COSTA. O semeador confere antes de criar a conta; qualquer
  outro caminho que vincule RNP precisa fazer o mesmo, senão sobra conta órfã segurando o CPF.
- **O aceite de termos são dois campos**, `aceite_uso` e `aceite_privacidade`, não um só.
- **`ON DUPLICATE KEY UPDATE` só é confiável se nenhuma coluna do índice aceitar nulo.** Já custou
  duas vezes (D25, D28).
- **`PROLINK_API_TOKEN` é obrigatório mesmo sem chamar a API**: `/cadastro` constrói o
  `TransporteCurl`, que lança se o token faltar. E `docker compose restart` não relê o `.env`,
  use `docker compose up -d`.
- **`verificar-e1.php` acusa uma falha quando a API está acessível**: o script gera CNPJ sintético
  do relógio e a D26 rebaixa para Terceiro PJ, mas a checagem espera EMPRESA. Acoplamento do
  teste, não regressão.
- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Gatilho é item da E5.
- Documento da massa usado uma vez fica consumido para sempre (D15). Do lado da empresa só as 15
  da `massa-de-dados.md` passam no DV.
- `ATTR_EMULATE_PREPARES` desligado: placeholder nomeado **não** pode repetir na mesma query.
- Contas locais: admin `camila@prolink.local` / `ProLinkDemo2026!`. As 17 contas semeadas usam a
  mesma senha, com e-mail `nome.4digitos@prolink.local`.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
