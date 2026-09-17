#!/usr/bin/env bash
#
# O último comando antes do upload: prova, grava, empacota.
#
# Existe porque a sequência final tem ordem e ela não é óbvia. A suíte cria dado de verdade pela
# interface, então ela roda ANTES da limpeza, nunca depois; a limpeza sai antes dos vídeos, senão
# o vídeo grava o rastro que a limpeza ia tirar; e o pacote sai por último, com a árvore limpa.
#
# Cada passo aborta o seguinte se falhar: pacote gerado a partir de uma bateria vermelha parece
# pronto e não está.
#
# Uso:  scripts/fechar-entrega.sh [--sem-video]

set -euo pipefail

cd "$(dirname "$0")/.."

SEM_VIDEO="${1:-}"

verde() { printf '\033[32m%s\033[0m\n' "$1"; }
passo() { printf '\n\033[1m%s\033[0m\n' "$1"; }

passo "1 · A bateria inteira"
bash scripts/verificar-tudo.sh

passo "2 · O rastro que a verificação acabou de deixar"
# Nesta ordem: a suíte que acabou de rodar criou experiências no perfil de demonstração, e é
# exatamente isso que sai daqui. Rodar antes não adiantaria.
docker compose exec -T php php scripts/limpar-rastro-de-demonstracao.php --aplicar
docker compose exec -T php php scripts/revogar-sessoes-de-teste.php

if [ "${SEM_VIDEO}" != "--sem-video" ]; then
    passo "3 · Os quatro vídeos, com as telas como estão agora"
    (
        cd e2e
        ./rodar.sh specs/demonstracao.spec.js specs/jornadas.spec.js --project=desktop
        mkdir -p videos
        cp resultados/demonstracao*/video.webm                  videos/demonstracao-jornada-completa.webm
        cp resultados/jornadas-*profissional*/video.webm         videos/jornada-profissional.webm 2>/dev/null || \
        cp resultados/jornadas-*privacidade*/video.webm          videos/jornada-profissional.webm
        cp resultados/jornadas-*empresa*/video.webm              videos/jornada-empresa.webm 2>/dev/null || \
        cp resultados/jornadas-*registrado*/video.webm           videos/jornada-empresa.webm
        cp resultados/jornadas-*administra*/video.webm           videos/jornada-administracao.webm 2>/dev/null || \
        cp resultados/jornadas-*sorteio*/video.webm              videos/jornada-administracao.webm
        ls -lh videos/
    )
else
    passo "3 · Vídeos (pulado a pedido)"
fi

passo "4 · O pacote"
# Ele recusa empacotar com trabalho não commitado, com campo sensível preenchido no .env.example,
# sem os documentos do item 8.3.2 ou sem o MER. A recusa aqui é o ponto: é a última rede.
scripts/empacotar-entrega.sh

echo
verde "Pronto para o upload."
echo "Confira o conteúdo antes de enviar:  unzip -Z1 entrega/prolink-equipe-49-51.zip | head -30"
