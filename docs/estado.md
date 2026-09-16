# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

15–16/09/2026 — Gabriel, com Claude Code. Branch `e4/feed-demandante`, seis commits, **não
integrada na `main`**.

## Onde parou

**E4 concluída.** O motor deixou de ser inalcançável: feed do demandante, busca ativa e auditoria
de sessões existem e são navegáveis. A base foi povoada pelo fluxo real (14 candidatos, ~28
chamadas da API) e enriquecida com visibilidade variada e dados autodeclarados.

Verificado: 188 testes · 46 conferências de padrão com **0 violações** · 29 telas · E2 138 ·
E4 32 · E6 19. A E5 (manifestação, mensagens, e-mail) não começou.

## Próximo passo

**Integrar `e4/feed-demandante` na `main`** e então começar a E5 por
`src/Service/ManifestacaoService.php`, a operação atômica 3: grava `pro_manifestacoes` com
`man_snapshot` e hash, muda a demanda para `COM_INTERESSADOS` e enfileira notificação. O ponto de
entrada do profissional já existe — `/demandas/abertas` — e a D51 fixou o sentido do fluxo.

## Decisões pendentes

- **Servir Bootstrap e as fontes localmente** e fechar a CSP em `'self'` (Consequência da D50).
  No Demo Day presencial, sem internet, a apresentação é feita sem CSS.
- `prf_em_construcao` é derivado guardado em coluna: derivar na leitura (como a D01) ou ponto
  único de escrita?
- MER (`_arq/mer/`) e a declaração de uso de IA (12.3) seguem **sem dono**, ambos obrigatórios na
  entrega. A `decisoes.md` chegou a 56 entradas: a matéria-prima da 12.3 está lá.

## Lembrar

- **A senha do admin não é a das contas semeadas.** `camila@prolink.local` tem senha própria, de
  desenvolvimento, que não entra em arquivo versionado. Um agente perdeu duas tentativas de login
  por assumir o contrário.
- **`verificar-e4.php` grava duas sessões novas a cada execução.** Das ~45 sessões em
  `mat_sessoes`, mais de 30 são lixo de verificação e aparecem na primeira página de
  `/admin/sessoes`. Limpar antes do pitch, ou fazer o script reutilizar uma demanda fixa.
- **Antes da demonstração**: recarregar o banco e rodar, nesta ordem, `semear-candidatos.php`,
  `abrir-visibilidade-demo.php` e `preencher-declarados-demo.php`. Sem os dois últimos o feed
  mostra perfis vazios e quatro das seis dimensões saem "Não medida".
- **Escreva as demandas do roteiro olhando o índice**, nunca antes — mordeu de novo nesta sessão.
- `verificar-api.php` consome chamadas registradas: nunca rodar em laço sobre `verificar-*.php`.
- Pendências de front anotadas e não feitas: vitrine sem filtro por UF ou atividade, navegação sem
  estado `.active`, e hover de cartão que existe na busca e não em `demanda/abertas`.
- `dem_titulo` é renderizado cru em cinco telas: demanda cujo título contenha `TOS_x` vazaria o
  código. Uma linha de `|replace` em cada resolve.
