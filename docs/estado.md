# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

25/09/2026 — Gabriel, com Claude Code. E8 fechada; repositório só com a `main`.

## Onde parou

**Tudo verde na bateria completa**: 9 de 9, com os 31 cenários do navegador e os de
administração rodando. CAT no produto (D76), espera no botão de atualizar (D77), perguntas da
demanda a quem manifesta interesse (D78), banco de demonstração povoado (D79) e sem rastro de
teste à vista (D80). O deck está no artifact `claude.ai/artifact/6qhS4HKwjyD3ZKjrhwaCjY`.

## Próximo passo

**Ensaiar a demo por `docs/roteiro-demo.md`**, que ainda descreve o caminho antigo: trocar pela
Construtora Manauara e a demanda 96 (contrato PJ, prazo e 4 interessados), e acrescentar a
manifestação com as perguntas e a lista de interessados com "pediu × respondeu".

## Decisões pendentes

- **Confirmar com a organização** se a alteração pós-entrega pede tag nova ou reenvio do `.zip`.
  O repositório **não tem a tag `entrega-fase3`**: ela nunca foi criada.
- O deck (afinidade e seis dimensões) ainda mostra porcentagem em todas as dimensões: alinhar
  com a D78 antes da revisão da Camila.
- Reforço da CAT proporcional à afinidade, adiado pela equipe.

## Lembrar

- **Depois de qualquer bateria, `limpar-rastro-de-verificacao.php`** (D80): E4, E6 e a suíte do
  navegador deixam demandas, denúncias e experiências de teste à vista.
- **A suíte do navegador precisa de `e2e/.env.local`** com a credencial do admin local (fora do
  Git) e de `npm ci` e `npx playwright install chromium` em `e2e/`, já feitos nesta máquina.
- **Banco criado antes da D78 precisa da migração** em `_arq/migracoes/` (idempotente).
- **Sessão do motor anterior a uma mudança de acervo mostra o estado antigo**: sortear de novo.
- Cadastro de profissional custa quatro chamadas da API (D76); senha das contas semeadas:
  `ProLinkDemo2026!`.
