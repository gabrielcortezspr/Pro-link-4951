# nginx do Pro-Link, com HTTPS garantido desde a primeira subida (D81).
#
# A imagem oficial não traz o openssl de linha de comando, e sem ele não há como fabricar um
# certificado. Ele entra aqui só para gerar, no build, um autoassinado de reserva: é o que permite
# a um clone limpo subir com um único "docker compose up" (edital, item 8.8) sem nenhum arquivo de
# certificado no repositório. A chave nasce dentro da imagem de cada máquina e nunca é versionada.
#
# Quem quer o navegador sem alerta gera um certificado do mkcert (scripts/gerar-certificado-local.sh);
# o escolher-certificado.sh dá preferência a ele na subida.
FROM nginx:1.27-alpine

RUN apk add --no-cache openssl \
 && mkdir -p /etc/nginx/tls-autoassinado /etc/nginx/tls \
 && openssl req -x509 -nodes -newkey rsa:2048 -days 825 \
      -subj "/CN=localhost/O=Pro-Link (autoassinado local)" \
      -addext "subjectAltName=DNS:localhost,IP:127.0.0.1,IP:::1,DNS:nginx" \
      -keyout /etc/nginx/tls-autoassinado/prolink.key \
      -out /etc/nginx/tls-autoassinado/prolink.crt \
 && chmod 600 /etc/nginx/tls-autoassinado/prolink.key

# A imagem oficial roda, antes de iniciar o nginx, todo script executável em /docker-entrypoint.d/.
COPY escolher-certificado.sh /docker-entrypoint.d/40-escolher-certificado.sh
RUN chmod 755 /docker-entrypoint.d/40-escolher-certificado.sh
