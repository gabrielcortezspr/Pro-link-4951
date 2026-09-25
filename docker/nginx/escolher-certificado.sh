#!/bin/sh
#
# Escolhe, a cada subida do contêiner, qual certificado o nginx serve (D81).
#
# O default.conf aponta sempre para /etc/nginx/tls/prolink.{crt,key}. Este script faz esses dois
# caminhos apontarem para o certificado do mkcert, quando ele foi gerado e montado em
# /etc/nginx/certs, ou para o autoassinado que o Dockerfile fabricou no build. Assim o
# "docker compose up" de um clone limpo nunca falha por falta de arquivo (item 8.8), e quem rodou
# scripts/gerar-certificado-local.sh ganha o certificado sem alerta só reiniciando o nginx.
set -eu

montado=/etc/nginx/certs
reserva=/etc/nginx/tls-autoassinado
ativo=/etc/nginx/tls

mkdir -p "$ativo"

# Os dois arquivos, ou nenhum: um .crt sem a .key correspondente derrubaria o nginx na subida.
if [ -s "$montado/prolink.crt" ] && [ -s "$montado/prolink.key" ]; then
    origem="$montado"
    echo "$0: usando o certificado local gerado pelo mkcert"
else
    origem="$reserva"
    echo "$0: sem certificado em $montado, usando o autoassinado de reserva (o navegador vai alertar)"
fi

ln -sf "$origem/prolink.crt" "$ativo/prolink.crt"
ln -sf "$origem/prolink.key" "$ativo/prolink.key"
