#!/usr/bin/env bash
#
# Gera o certificado HTTPS confiável do ambiente local, com o mkcert (D81).
#
# Por que existe: o nginx sobe sozinho com um certificado autoassinado quando não acha outro, para
# que um clone limpo suba com um único "docker compose up" (item 8.8). Esse autoassinado funciona,
# mas o navegador mostra o alerta de conexão não segura, e alerta vermelho no meio da demonstração
# desmente o slide de segurança. O mkcert cria uma autoridade certificadora local, e o certificado
# assinado por ela o navegador desta máquina aceita sem alerta.
#
# Pré-requisitos, uma vez por máquina:
#   brew install mkcert
#   mkcert -install        pede a senha do sistema: instala a autoridade local no chaveiro do macOS
#
# A chave privada vai para docker/nginx/certs/, que o .gitignore recusa. Ela NUNCA entra no
# repositório (Anexo VI, item 5): quem clona gera a própria, ou fica com o autoassinado.
#
# Uso:  ./scripts/gerar-certificado-local.sh
set -euo pipefail

cd "$(dirname "$0")/.."

if ! command -v mkcert >/dev/null 2>&1; then
  echo "erro: mkcert não está instalado. Rode: brew install mkcert && mkcert -install" >&2
  exit 1
fi

destino="docker/nginx/certs"
mkdir -p "$destino"

# "nginx" entra na lista porque é o nome do serviço dentro da rede do Docker: os verificadores
# que rodam no contêiner php chegam ao servidor por esse nome.
mkcert -cert-file "$destino/prolink.crt" -key-file "$destino/prolink.key" \
  localhost 127.0.0.1 ::1 nginx

chmod 600 "$destino/prolink.key"

# A escolha do certificado acontece na subida do contêiner (docker/nginx/escolher-certificado.sh),
# então basta reiniciar o nginx para ele passar a usar este.
if docker compose ps --status running nginx 2>/dev/null | grep -q nginx; then
  docker compose restart nginx
  echo "nginx reiniciado com o certificado do mkcert: https://localhost:8443"
else
  echo "certificado gerado. Suba o ambiente com: docker compose up -d --build"
fi

# O mkcert 1.4 não tem como conferir, sem pedir senha, se a autoridade já está no chaveiro.
echo "lembrete: se o navegador ainda alertar, falta rodar uma vez 'mkcert -install' (pede a senha do sistema)."
