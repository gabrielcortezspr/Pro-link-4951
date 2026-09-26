# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

25/09/2026 (noite) — Gabriel, com Claude Code. E9 na branch **Teste-Demo**, não mesclada.

## Onde parou

A navegação da demanda foi refeita (D87): candidatura, convite e contatos; abas com contadores;
sino com número; feed só com quem falta avaliar (convidar e dispensar). Bateria completa em 9 de 9
com o código atual. O pull de 25/09 trouxe HTTPS (D81), banco com usuário restrito (D82) e cookie
`__Host-` (D86), e o ambiente local foi adaptado a eles.

## Próximo passo

**Revisar a Teste-Demo nos portais e decidir o merge na `main`.** Depois, ensaiar a demo por
`docs/roteiro-demo.md`, que ainda descreve o caminho antigo (Manauara e a demanda 96).

## Decisões pendentes

- Merge da `Teste-Demo` na `main`: é da equipe.
- Confirmar com a organização se a alteração pós-entrega pede tag ou `.zip` novo; a tag
  `entrega-fase3` nunca foi criada.
- O deck ainda mostra porcentagem em todas as dimensões e usa "interessados": alinhar com D78/D87.
- Reforço da CAT proporcional à afinidade, adiado.

## Lembrar

- **Depois de um pull, confira `.env.example`, `docker-compose.yml` e `_arq/migracoes/`**: a D82
  exige `DB_APP_*` no `.env` e rodar `_arq/usuarios.sh`; tabela nova (D87) exige rodá-lo de novo.
- **HTTPS local em Linux**: `sudo apt install mkcert libnss3-tools && mkcert -install`, depois
  `scripts/gerar-certificado-local.sh`. Sem isso, os portais do Maestri não abrem a aplicação.
- **O E1 consulta a API**: o cadastro via web chama o CREA com documento inventado. Não rodar em laço.
- **Depois de qualquer bateria, `limpar-rastro-de-verificacao.php`** (D80).
- Senha das contas semeadas: `ProLinkDemo2026!`; a do admin mora em `e2e/.env.local`.
