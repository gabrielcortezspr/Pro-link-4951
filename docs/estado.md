# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

14/09/2026 — Camila, com Claude Code. O front entrou no repositório e a E6 começou.

## Onde parou

**Design concluído e versionado** em `claude/frontend-design`, pronta para merge na main: tema sobre
o Bootstrap em `public/assets/css/prolink.css` (componentes `pl-*`, verde água só no selo, laranja
como única ação preenchida), `docs/design.md`, `docs/fluxos.md`, onze telas em `docs/mockups/` e as
decisões D28 a D34. Template escrito daqui pra frente já nasce estilizado.

**E6 em andamento** em `claude/e6-denuncias-admin`, que sai da branch de design porque o tema mora
lá. O plano é `docs/sprint-2026-09-14-e6.md`, oito blocos com verificação executável. Blocos 0 e 1
fechados: ambiente de pé nesta máquina pela primeira vez (140 testes, 379 asserções), e o
experimento do bloqueio decidido — **revogar as sessões já derruba quem está logado**, medido por
requisição real, sem tocar em `Sessao`. Contraria o que a E7 supunha.

## Próximo passo

Bloco 2 do `sprint-2026-09-14-e6.md`: `DenunciaRepository` e `DenunciaService`, denúncia gravando em
`pro_denuncias` e em `sis_auditoria`, conferida pelo `scripts/verificar-e6.php` que o bloco cria.

## Decisões pendentes

- `match.early_career.min_arts` vale `3`, número escolhido por nós. Decidir antes das telas de feed
  e busca ativa — o porquê e os números estão na E4 do `backlog.md`.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura (como
  a D01) ou ponto único de escrita?
- Nenhuma tela mostra `perfil.campos` (e-mail, telefone, resumo), nos dois perfis.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b), e é o **único**
  dos seis itens do `_arq/` que ainda não existe.

## Lembrar

- **`PROLINK_API_TOKEN` é obrigatório mesmo sem chamar a API**: `/cadastro` constrói o
  `TransporteCurl`, que lança se o token faltar, e a página devolve 500. Não deixe vazio.
- **`docker compose restart` não relê o `.env`** — o Compose injeta as variáveis ao **criar** o
  container. Depois de mexer no `.env`, use `docker compose up -d`.
- **As três mudanças de schema da sessão passada já estão no `_arq/estrutura.sql`** (linhas 335, 375
  e 414), que o compose roda como init. Em banco novo elas já vêm aplicadas e rodar os `ALTER`
  devolve 1061/1060. A instrução antiga vale só para máquina com volume anterior a elas.
- **`verificar-e1.php` acusa uma falha quando a API está acessível**: o script gera CNPJ sintético
  derivado do relógio, a API responde `200 []`, e a D26 corretamente rebaixa para Terceiro PJ, mas a
  checagem espera EMPRESA. Passa só com a API fora. É acoplamento do teste, não regressão.
- A API oficial responde desta máquina (HTTP 200), então a dúvida de rede da sessão passada está
  resolvida.
- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Gatilho é item da E5.
- `PrivacidadeService::exportar` leva conta, consentimentos, sessões e auditoria, e nada de perfil
  nem de acervo. Com as duas metades de pé, a exportação do 11.3 ficou incompleta.
- **Relógios diferentes**: `*_dt_consulta` vem do `date()` do PHP (Manaus) e `*_dt_sincronizacao` do
  `NOW()` do MariaDB (UTC). Dá 4h em "Consultado em", e vai morder o filtro de período da auditoria.
- Documento da massa usado uma vez fica consumido para sempre (D15). CPFs livres: `...290`, `...370`,
  `...451`. Do lado da empresa só as 15 da `massa-de-dados.md` passam no DV.
- `ATTR_EMULATE_PREPARES` está desligado: placeholder nomeado **não** pode repetir na mesma query.
- Contas locais desta máquina: admin `camila@prolink.local` / `ProLinkDemo2026!`; cobaia de bloqueio
  `cobaia@prolink.local` (usu_id 10, devolvida ao estado ativo para o Bloco 5).
