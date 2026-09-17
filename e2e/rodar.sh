#!/usr/bin/env bash
#
# Roda a suíte com a credencial de administração do ambiente local.
#
# Existe porque metade dos cenários precisa entrar como administração, e a senha não pode viver em
# arquivo versionado (Anexo VI, item 5). Ela fica em `e2e/.env.local`, que o .gitignore recusa, e
# este script só a carrega para o processo do Playwright.
#
# Sem o arquivo, a suíte roda igual: os testes que precisam de administração **pulam**, em vez de
# falhar, porque falta de configuração de quem roda não é defeito da aplicação.
#
# Uso:  ./rodar.sh                      a suíte inteira
#       ./rodar.sh specs/cenarios.spec.js --project=desktop
set -euo pipefail

cd "$(dirname "$0")"

if [ -f .env.local ]; then
  set -a
  # shellcheck disable=SC1091
  . ./.env.local
  set +a
else
  echo "aviso: e2e/.env.local não existe; os cenários de administração vão pular" >&2
fi

exec npx playwright test "$@"
