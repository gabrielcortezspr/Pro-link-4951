# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

06/09/2026 — Gabriel, com Claude Code.

## Onde parou

Esqueleto validado de ponta a ponta no Docker: 28 tabelas + view, front controller, cliente da
API, primitivas do motor. Docs corrigidos contra a API real. Configuração deduplicada. Sistema de
retomada entre sessões montado (este arquivo, hook `SessionStart`, skill `/encerrar`).

## Próximo passo

RF01 — cadastro, login, RBAC e recuperação de senha. Começar por `src/Repository/UsuarioRepository.php`
e `src/Service/AutenticacaoService.php`; a primeira tela é `templates/auth/cadastro.html.twig`.

## Decisões pendentes

- MER (`_arq/mer/`): gerar do MySQL Workbench ou de ferramenta de linha de comando? Precisa
  existir antes da entrega.
- Roteiro da demonstração só depois de montar o índice com candidatos reais cadastrados
  (`docs/matching.md`, "Cenários de demonstração").

## Lembrar

- Admin de desenvolvimento: `admin@prolink.local` (senha definida ao rodar `criar-admin.php`; nunca versionar).
- Bootstrap vem de CDN; baixar para `public/assets/` antes da entrega (banca pode estar offline).
- `docker context use default` se o Docker reclamar de socket do Desktop.
