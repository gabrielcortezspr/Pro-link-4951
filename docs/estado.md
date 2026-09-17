# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

16/09/2026 — Gabriel, com Claude Code. E5 concluída e mesclada; E7 começada em `e7/entrega`.

## Onde parou

**Os seis cenários mínimos do edital rodam.** A E5 fechou o cenário 4 e foi para a `main`. Na E7,
dois itens entraram: a declaração de uso de IA, vieses e limitações, que não existia, e o front
servido localmente com a CSP em `'self'` — o risco operacional que o estado carregava há três
sessões acabou.

Verificado: 188 testes · 55 conferências de padrão com **0 violações** · 34 telas · E2 138 ·
E4 32 · E6 19.

## Próximo passo

**O MER em `_arq/mer/`** — é o único item ainda obrigatório (8.3.2b) que não existe. Gerar do banco
real, em PDF e PNG. Decidir antes: Workbench ou linha de comando.

Depois, em ordem: texto real de `sis_termos` (hoje espaço reservado, e o 11.3 cobra que D03 e D05
estejam na Política de Privacidade); README testado num clone limpo; roteiro dos seis cenários com
os identificadores usados, ensaiado duas vezes.

**Entrega: 17/09 até as 18h.** `git tag entrega-fase3`, push, `.zip` sem `vendor/`, `.env` e
`storage/`, endereço do GitHub na plataforma. Não deixar para as 17h.

## Decisões pendentes

- `prf_em_construcao` é derivado guardado em coluna: derivar na leitura (como a D01) ou ponto único
  de escrita? Não bloqueia a entrega.
- `ARQUIVADA` em `man_situacao`: quem arquiva, e o que significa para quem manifestou.

## Lembrar

- **`git pull` que toque `docker/nginx/default.conf` exige `up -d --force-recreate nginx`** — bind
  mount de arquivo prende o inode. Já está no `CLAUDE.md`.
- **A senha do admin não é a das contas semeadas.**
- **`ParametroRepository` faz cache estático por processo**: mudar `sis_parametros` e chamar o
  serviço na mesma execução lê o valor antigo.
- **`verificar-e4.php` grava duas sessões a cada execução**; a primeira página de `/admin/sessoes`
  está cheia de lixo de teste. Limpar antes do pitch.
- **Antes da demonstração**: recarregar o banco e rodar `semear-candidatos.php`,
  `abrir-visibilidade-demo.php` e `preencher-declarados-demo.php`, nesta ordem.
- **Escreva as demandas do roteiro olhando o índice**, nunca antes.
- `verificar-api.php` consome chamadas registradas: nunca rodar em laço sobre `verificar-*.php`.
