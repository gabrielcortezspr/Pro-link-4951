#!/usr/bin/env bash
#
# Empacota a entrega, recusando empacotar o que não pode sair daqui.
#
# O pacote sai de `git archive`, e não de `zip` na pasta de trabalho, por um motivo só: o que não
# está versionado não entra. Isso elimina de uma vez o `.env` com credencial real, o `vendor/`
# baixado, os vídeos da suíte de testes e o volume do banco. O item 5 do Anexo VI reprova na
# triagem quem entrega credencial, e a forma mais barata de nunca entregar é nunca ter como.
#
# Antes de escrever o arquivo, o script confere o que a triagem confere. Qualquer recusa aborta:
# um pacote que sai com problema conhecido é pior que nenhum pacote, porque parece pronto.
#
# Uso:  scripts/empacotar-entrega.sh [referência]
#       referência é a etiqueta, ramo ou commit a empacotar (padrão: HEAD).

set -euo pipefail

cd "$(dirname "$0")/.."

REFERENCIA="${1:-HEAD}"
NOME="prolink-equipe-49-51"
SAIDA="entrega/${NOME}.zip"

vermelho() { printf '\033[31m%s\033[0m\n' "$1"; }
verde()    { printf '\033[32m%s\033[0m\n' "$1"; }

recusar() {
  vermelho "RECUSADO  $1"
  exit 1
}

echo "Empacotando ${REFERENCIA}"
echo

# 1. Trabalho não commitado não entra no pacote, e descobrir isso depois do upload é tarde.
if [ -n "$(git status --porcelain)" ]; then
  git status --short
  recusar "há alteração não commitada: ela ficaria de fora do pacote sem aviso"
fi

# 2. O que o Anexo VI reprova na triagem.
if git ls-files | grep -qiE '^\.env$|^\.env\.local$'; then
  recusar "o .env está versionado"
fi

if ! git show "${REFERENCIA}:.env.example" >/dev/null 2>&1; then
  recusar "não há .env.example na referência: o edital pede o modelo (8.3.1)"
fi

PREENCHIDOS=$(git show "${REFERENCIA}:.env.example" \
  | grep -E '^(APP_KEY|DB_PASSWORD|DB_ROOT_PASSWORD|PROLINK_API_TOKEN|MAIL_PASSWORD)=.+' || true)

if [ -n "${PREENCHIDOS}" ]; then
  echo "${PREENCHIDOS}"
  recusar "o .env.example traz valor em campo sensível"
fi

# 3. O mínimo que o item 8.3.2 exige dentro de `_arq/`.
for OBRIGATORIO in _arq/estrutura.sql _arq/README.md _arq/arquitetura.md \
                   _arq/dependencias.md _arq/estrutura-diretorios.md; do
  git show "${REFERENCIA}:${OBRIGATORIO}" >/dev/null 2>&1 \
    || recusar "falta ${OBRIGATORIO}, que o item 8.3.2 exige"
done

# O MER é exigido em PDF, PNG ou .mwb, e qualquer um dos três serve.
if ! git ls-tree -r --name-only "${REFERENCIA}" -- _arq/mer | grep -qiE '\.(pdf|png|mwb)$'; then
  recusar "não há MER em PDF, PNG ou .mwb dentro de _arq/mer"
fi

# 4. O `_config.php` na raiz, que o item 8.3.1 exige literalmente.
git show "${REFERENCIA}:_config.php" >/dev/null 2>&1 \
  || recusar "falta o _config.php na raiz (item 8.3.1)"

verde "As conferências passaram."
echo

mkdir -p entrega
git archive --format=zip --prefix="${NOME}/" -o "${SAIDA}" "${REFERENCIA}"

# 5. Última rede: olhar o que de fato entrou, e não o que se espera que tenha entrado.
if unzip -Z1 "${SAIDA}" | grep -qE '/\.env$|/vendor/|/node_modules/|\.webm$|\.zip$'; then
  unzip -Z1 "${SAIDA}" | grep -E '/\.env$|/vendor/|/node_modules/|\.webm$|\.zip$' | head
  rm -f "${SAIDA}"
  recusar "o pacote levou arquivo que não devia; ele foi apagado"
fi

TAMANHO=$(du -h "${SAIDA}" | cut -f1)
ARQUIVOS=$(unzip -Z1 "${SAIDA}" | wc -l | tr -d ' ')

verde "Pacote pronto: ${SAIDA}  (${TAMANHO}, ${ARQUIVOS} arquivos)"
echo
echo "Confira antes de enviar:"
echo "  unzip -Z1 ${SAIDA} | head -30"
