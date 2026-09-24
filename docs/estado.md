# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

24/09/2026 — Gabriel, com Claude Code. E8 concluída e mesclada na `main`.

## Onde parou

**A CAT entrou no produto** (D76): importada no cadastro, ligada às ARTs, com selo, reforçando o
motor quando vigente, e a aba Acervo organizada por certidão. O botão de atualizar o acervo tem
espera de 60 min (D77). Os 12 profissionais semeados já têm CAT. O deck do Demo Day está em
rascunho no artifact `claude.ai/artifact/6qhS4HKwjyD3ZKjrhwaCjY`, esperando a revisão da Camila.

## Próximo passo

**Ensaiar a demo por `docs/roteiro-demo.md`**, trocando a conta que ele cita (Alfa Engenharia,
que não existe no banco local) pela Construtora Manauara e a demanda 96 (plano setorial regional,
`TOS_10.4.2.3`), que é o exemplo do deck. Antes, "Atualizar e sortear de novo" em cada demanda.

## Decisões pendentes

- **Confirmar com a organização** se a alteração pós-entrega pede tag nova ou reenvio do `.zip`,
  para o repositório bater com a demo (13.6e).
- Reforço da CAT proporcional à afinidade (`+0,30 × (1 − v) × afinidade`): hoje ela quase triplica
  evidência de atividade vizinha (0,15 → 0,405). Adiado pela equipe em 24/09; mexe no deck.
- `ARQUIVADA` em `man_situacao`, `prf_em_construcao` derivado na leitura, e o par excluído (D74).

## Lembrar

- **Sessão do motor gravada antes da importação da CAT não mostra CAT**: sortear de novo.
- **Na massa toda CAT cobre 100% das ARTs**: vencida, renovada e "ARTs sem certidão" só existem na
  prévia, nunca com dado real. Não fabricar dado para mostrar.
- **Pull que muda `_arq/estrutura.sql` não altera o banco local.** Em 24/09 faltavam `man_origem`
  e mais três itens (erro 500 em `/inicio`); alinhar comparando com um banco de referência.
- **Cada cadastro de profissional custa quatro chamadas da API** desde a D76.
- A senha da administração mora em `e2e/.env.local`; a das contas semeadas é `ProLinkDemo2026!`.
- `git pull` que toque `docker/nginx/default.conf` exige `up -d --force-recreate nginx`.
