# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

26/09/2026 (madrugada) — Gabriel, com Claude Code. E9 concluída e mesclada da `Teste-Demo` na `main`.

## Onde parou

Refeitos a navegação da demanda (D87), o convite com mensagem e a conta dentro do perfil (D88), a
vitrine pela TOS (D89) e o Início (D90). Verificado: 255 testes, 44 telas, padrão sem violação,
E2, E4 e E6. **E1 e a suíte do navegador não rodaram depois da D88.** O E1 gasta chamadas da API.

## Próximo passo

**Ensaiar a demo nos portais** (Manauara, Arthur, Sophia) e reescrever `docs/roteiro-demo.md`, que
ainda descreve o caminho antigo: "registrar interesse", "Interessados" e o Início com indicadores.

## Decisões pendentes

- Rodar a bateria completa (`scripts/verificar-tudo.sh`, com E1 e navegador) antes do Demo Day:
  pede aval da equipe, porque o E1 consome chamadas registradas.
- O deck ainda mostra porcentagem em todas as dimensões e usa "interessados": alinhar com D78/D87.
- Confirmar com a organização se a alteração pós-entrega pede tag ou `.zip` novo; a tag
  `entrega-fase3` nunca foi criada.
- Reforço da CAT proporcional à afinidade, adiado.

## Lembrar

- **Migração nova da D88** (`_arq/migracoes/2026-09-25-d88-modelo-convite.sql`): aplicar em banco
  que já existe. Tabela nova exige rodar `_arq/usuarios.sh` de novo; coluna nova, não.
- **A atividade recente mostra candidaturas a demandas de verificação antigas** ("Plano de
  intervenção urbana (021445)"). O limpar-rastro encerra a demanda, mas o evento fica no histórico.
- **Depois de qualquer bateria, `limpar-rastro-de-verificacao.php`** (D80).
- **HTTPS local**: `mkcert -install` e `scripts/gerar-certificado-local.sh`; portais em
  `https://localhost:8443`. Senha das contas semeadas: `ProLinkDemo2026!`; admin em `e2e/.env.local`.
