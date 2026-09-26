# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

26/09/2026 — Gabriel, com Claude Code. Segunda rodada da E9 (D92 a D96), mesclada na `main`.

## Onde parou

Vitrine com origem de "na sua área" e "Limpar filtros"; Minhas demandas por situação; demanda
sempre com principal; **ART fechada não conta** no motor, no feed nem na busca (D95), com Política
1.1 e reforço da CAT proporcional (D96). Pools da demo recalculados e menores. Verificado: 267
testes, telas, padrão, E2, E4, E6. **E1 e a suíte do navegador não rodaram desde a D88.**

## Próximo passo

**Ensaiar a demo nos portais** e reescrever `docs/roteiro-demo.md`, que ainda descreve "registrar
interesse", "Interessados" e o Início com indicadores. Conferir os números do deck contra os novos.

## Decisões pendentes

- Rodar a bateria completa (`scripts/verificar-tudo.sh`, com E1 e navegador) antes do Demo Day:
  pede aval da equipe, porque o E1 consome chamadas registradas.
- O deck mostra porcentagem por dimensão calculada antes da D95/D96 e usa "interessados".
- Confirmar com a organização se a alteração pós-entrega pede tag ou `.zip` novo.

## Lembrar

- **Migrações novas**: `2026-09-25-d88-modelo-convite.sql` e `2026-09-26-d96-politica-privacidade-1-1.sql`.
  Aplicar em banco que já existe; nenhuma cria tabela, então `_arq/usuarios.sh` não precisa rodar.
- **`abrir-visibilidade-demo.php --so-empresas`** abriu ARTs das empresas (D95). Rodar sem a opção
  reabriria ARTs que um profissional tenha fechado pela tela.
- **Depois de mudar o motor, recalcule as demandas abertas**: o feed relê a última sessão gravada.
- **Depois de qualquer bateria, `limpar-rastro-de-verificacao.php`** (D80).
- Portais em `https://localhost:8443`; senha das contas semeadas `ProLinkDemo2026!`; admin em
  `e2e/.env.local`.
