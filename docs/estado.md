# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

06/09/2026 — Gabriel, com Claude Code. Três commits: `6c067e3`, `d032b51`, `0328df0`.

## Onde parou

E0 concluída: `Sessao` (expiração, regeneração de id), `Flash`, `Auditoria` (caminho único de
escrita), `Requisicao`, roteador com perfis múltiplos, autorização por requisição no front
controller (401 anônimo / 403 perfil errado + auditoria), macros de formulário acessíveis,
PHPUnit com 28 testes. Nenhuma linha de RF ainda.

## Próximo passo

E1 do `docs/backlog.md` (RF01). Começar por `src/Repository/UsuarioRepository.php` e
`src/Service/AutenticacaoService.php`; primeira tela `templates/auth/cadastro.html.twig`
usando as macros de `layout/_form.html.twig`. Aí a rota POST existe e o 419 do CSRF vira
testável por HTTP.

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
