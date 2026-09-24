# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

24/09/2026 (tarde) — Gabriel, com Claude Code. E8 fechada e mesclada na `main`.

## Onde parou

**A CAT está no produto** (D76), o botão de atualizar o acervo tem espera (D77), e as preferências
da demanda viraram perguntas a quem manifesta interesse, com o cartão de compatíveis dando grau só
à competência (D78). O banco local tem 42 profissionais, 14 empresas, 11 demandas completas e 44
interesses (D79). O deck está no artifact `claude.ai/artifact/6qhS4HKwjyD3ZKjrhwaCjY`.

## Próximo passo

**Ensaiar a demo por `docs/roteiro-demo.md`**, que ainda descreve o caminho antigo: trocar pela
Construtora Manauara e a demanda 96 (já com contrato PJ, prazo e 4 interessados), e acrescentar a
manifestação com as perguntas e a lista de interessados com "pediu × respondeu".

## Decisões pendentes

- **Confirmar com a organização** se a alteração pós-entrega pede tag nova ou reenvio do `.zip`.
- Reforço da CAT proporcional à afinidade (`+0,30 × (1 − v) × afinidade`), adiado pela equipe.
- O deck (slide da afinidade e o das seis dimensões) ainda descreve o cartão antigo, com
  porcentagem em todas as dimensões: alinhar com a D78 antes da Camila revisar.

## Lembrar

- **Banco criado antes da D78 precisa de `_arq/migracoes/2026-09-24-d78-*.sql`** (idempotente).
  `git pull` não altera banco existente: foi o erro 500 de `man_origem` em 24/09.
- **`semear-demandas.php` encerra as demandas de verificação** que o `verificar-e4.php` cria; rodar
  de novo depois de cada bateria de verificação.
- **Sessão do motor gravada antes de uma mudança de acervo mostra o estado antigo**: sortear de novo.
- Na massa toda CAT cobre 100% das ARTs; vencida e renovada só existem na prévia.
- Cadastro de profissional custa quatro chamadas da API (D76). A senha da administração mora em
  `e2e/.env.local`; a das contas semeadas é `ProLinkDemo2026!`.
