#!/usr/bin/env bash
#
# A bateria inteira, numa ordem que não se atrapalha, com placar no fim.
#
# Existe porque na véspera da entrega ninguém lembra de rodar os oito, e rodar sete é como rodar
# nenhum: o que passa despercebido é sempre o que ficou de fora. A ordem aqui é deliberada.
#
#   1. O que não toca banco nem rede primeiro (unidade, compilação de tela, padrão visual).
#   2. Depois os verificadores por etapa, que escrevem no banco.
#   3. Por último a suíte de ponta a ponta, que cria dado real pela interface.
#
# **Nenhum passo daqui chama a API oficial.** `verificar-api.php` e `semear-candidatos.php` ficam
# de fora de propósito: cada chamada é registrada pela organização, e uma bateria que gasta cota
# não pode ser rodada à vontade. Rode aqueles dois à mão, quando quiser confirmar que nada mudou
# do lado deles.
#
# Uso:
#   bash scripts/verificar-tudo.sh                 # tudo, menos o que precisa de senha
#   PROLINK_E2E_ADMIN_SENHA=... bash scripts/verificar-tudo.sh
#   (ou deixe a credencial em e2e/.env.local, que este script carrega sozinho)
#
# A senha do administrador da suíte de ponta a ponta vem do ambiente. Sem ela, os passos de
# navegador são pulados com aviso, e o resto roda: pular com aviso é melhor do que falhar por um
# motivo que não é o do teste.

set -uo pipefail

cd "$(dirname "$0")/.." || exit 1

verde="\033[32m"; vermelho="\033[31m"; amarelo="\033[33m"; forte="\033[1m"; fim="\033[0m"

passos_ok=0
passos_falha=0
pulados=0
resumo=()

passo() {
    local nome="$1"; shift
    printf "\n${forte}%s${fim}\n" "$nome"

    local saida
    saida=$("$@" 2>&1)
    local codigo=$?

    printf '%s\n' "$saida" | tail -3

    if [ $codigo -eq 0 ]; then
        passos_ok=$((passos_ok + 1))
        resumo+=("ok      $nome")
    else
        passos_falha=$((passos_falha + 1))
        resumo+=("FALHOU  $nome")
    fi
}

pular() {
    pulados=$((pulados + 1))
    resumo+=("pulado  $1  ($2)")
    printf "\n${amarelo}pulado${fim}  %s  (%s)\n" "$1" "$2"
}

printf "${forte}Pro-Link, bateria completa${fim}  ·  nenhum passo chama a API oficial\n"

# ---------------------------------------------------------------- sem banco, sem rede
passo "Testes de unidade"            docker compose exec -T php composer test
passo "Telas compilam"               docker compose exec -T php php scripts/verificar-telas.php
passo "Padrão visual"                docker compose exec -T php php scripts/verificar-padrao.php

# ---------------------------------------------------------------- contra o banco
passo "E1, identidade e consentimento" docker compose exec -T php php scripts/verificar-e1.php https://nginx:8443
passo "E2, portfólio e sincronização"  docker compose exec -T php php scripts/verificar-e2.php
passo "E4, motor de compatibilização"  docker compose exec -T php php scripts/verificar-e4.php
passo "E6, denúncias e painel"         docker compose exec -T php php scripts/verificar-e6.php

# ---------------------------------------------------------------- pelo navegador
# A credencial da administração pode vir do ambiente ou de `e2e/.env.local`, que o .gitignore
# recusa e que `e2e/rodar.sh` também carrega. Sem isso, a bateria pulava os dois passos de
# navegador mesmo com o arquivo no lugar, e o placar dizia "7 no verde, 2 pulados" numa máquina
# onde os nove passam.
if [ -z "${PROLINK_E2E_ADMIN_SENHA:-}" ] && [ -f e2e/.env.local ]; then
    set -a
    # shellcheck disable=SC1091
    . ./e2e/.env.local
    set +a
fi

if [ -z "${PROLINK_E2E_ADMIN_SENHA:-}" ]; then
    pular "Seis cenários pelo navegador" "exporte PROLINK_E2E_ADMIN_SENHA"
    pular "Acessibilidade e 390px"       "exporte PROLINK_E2E_ADMIN_SENHA"
elif [ ! -d e2e/node_modules ]; then
    pular "Seis cenários pelo navegador" "rode: cd e2e && npm install"
    pular "Acessibilidade e 390px"       "rode: cd e2e && npm install"
else
    passo "Seis cenários e sondas de segurança" \
        env -C e2e npx playwright test --project=desktop
    passo "Acessibilidade e 390px" \
        env -C e2e npx playwright test --project=celular
fi

# ---------------------------------------------------------------- placar
printf "\n${forte}Placar${fim}\n"
for linha in "${resumo[@]}"; do
    case "$linha" in
        ok*)     printf "  ${verde}%s${fim}\n" "$linha" ;;
        FALHOU*) printf "  ${vermelho}%s${fim}\n" "$linha" ;;
        *)       printf "  ${amarelo}%s${fim}\n" "$linha" ;;
    esac
done

printf "\n%d no verde · %d falharam · %d pulados\n" "$passos_ok" "$passos_falha" "$pulados"

if [ $passos_falha -gt 0 ]; then
    printf "${vermelho}A bateria tem falha.${fim} Nada de tag nem de zip antes de resolver ou declarar.\n"
    exit 1
fi

if [ $pulados -gt 0 ]; then
    printf "${amarelo}Passos pulados não contam como verdes.${fim}\n"
    exit 0
fi

printf "${verde}Bateria completa no verde.${fim}\n"
