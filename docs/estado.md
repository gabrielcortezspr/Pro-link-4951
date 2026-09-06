# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

06/09/2026 — Gabriel, com Claude Code. Três commits: `6c067e3`, `d032b51`, `0328df0`.

## Onde parou

Esqueleto validado de ponta a ponta no Docker (28 tabelas + view, front controller, cliente da
API, primitivas do motor). Docs corrigidos contra a API real, configuração deduplicada, sistema de
retomada montado e commitado. Nenhuma linha de RF escrita ainda.

## Próximo passo

E0 do `docs/backlog.md`: criar o repositório no GitHub, `Support/Auditoria`, `Support/Sessao`
com o middleware de perfil, `Support/Flash`, macros de formulário e o PHPUnit rodando. Meio dia.
Depois E1 (RF01).

## Decisões pendentes

- MER (`_arq/mer/`): gerar do MySQL Workbench ou de ferramenta de linha de comando? Obrigatório
  na entrega (edital 8.3.2b).
- Repositório remoto no GitHub ainda não existe. O edital exige o endereço informado na
  plataforma, com histórico completo.

## Lembrar

- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
- Admin de dev: `admin@prolink.local`; senha definida em `criar-admin.php`, nunca versionar.
- Bootstrap vem de CDN; baixar para `public/assets/` antes da entrega.
- Docker reclamando de socket do Desktop → `docker context use default`.
