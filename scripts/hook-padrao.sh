#!/usr/bin/env bash
# Hook PostToolUse: confere o padrão visual do arquivo que acabou de ser escrito.
#
# A verificação completa é scripts/verificar-padrao.php, que precisa do contêiner e renderiza as
# telas. Aqui mora só a parte que dá para conferir no texto do arquivo, sem Docker, em
# milissegundos: é o que permite rodar a cada edição em vez de no fim, quando o erro já se
# espalhou por cinco telas.
#
# Sai com 2 e escreve no stderr quando encontra violação: o agente recebe isso como retorno e
# corrige na hora.
#
# Ligado em .claude/settings.json, matcher Write|Edit.

set -uo pipefail

entrada=$(cat)

# jq não é dependência garantida; o caminho do arquivo sai por recorte simples do JSON.
arquivo=$(printf '%s' "$entrada" | sed -n 's/.*"file_path"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' | head -1)

[ -z "$arquivo" ] && exit 0
[ -f "$arquivo" ] || exit 0

case "$arquivo" in
    *.twig|*/prolink.css) ;;
    *) exit 0 ;;
esac

# Comentário Twig e comentário HTML saem: a regra vale para o que a pessoa lê na tela.
texto=$(perl -0777 -pe 's/\{#.*?#\}//gs; s/<!--.*?-->//gs' "$arquivo" 2>/dev/null) || exit 0

achados=""
anotar() { achados="${achados}  - $1"$'\n'; }

if printf '%s' "$texto" | perl -CSD -0777 -ne 'exit(/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/ ? 0 : 1)'; then
    anotar "emoji na interface. Ícone é SVG monocromático inline, ou nada."
fi

if printf '%s' "$texto" | grep -q '—'; then
    anotar "travessão em texto de interface. Separador de título é \" · \"; valor ausente é \"Não informado\"."
fi

case "$arquivo" in
    */email/*) ;;  # cliente de e-mail ignora folha externa e variável CSS: lá o estilo embutido é a única saída
    *.twig)
        if printf '%s' "$texto" | grep -Eq 'style="[^"]*(#[0-9a-fA-F]{3,8}|rgb\()'; then
            anotar "cor fora dos tokens. Use var(--pl-*) numa classe, não style inline."
        fi
        ;;
esac

if printf '%s' "$texto" | grep -Eq '<table(?![^>]*pl-table)' 2>/dev/null \
   || printf '%s' "$texto" | grep -E '<table[^>]*>' 2>/dev/null | grep -qv 'pl-table'; then
    anotar "tabela fora do design system. Use class=\"pl-table\"."
fi

if printf '%s' "$texto" | grep -Eq '\|[[:space:]]*u\.[a-z]'; then
    anotar "filtro de twig/string-extra, que não é dependência do projeto."
fi

# Identificador de banco impresso sem passar por rótulo. Só o caso direto; o vazamento que vem de
# dado é pego na verificação renderizada.
if printf '%s' "$texto" | grep -Eq '\{\{[^}]*\b(aud_acao|aud_entidade|aud_campo|dem_tipo_contrato|prf_tipo_contrato|prf_disponibilidade)\b[^}]*\}\}' \
   && ! printf '%s' "$texto" | grep -q 'rotulo_'; then
    anotar "identificador de sistema impresso cru. Use |rotulo_acao, |rotulo_entidade, |rotulo_campo, |rotulo_contrato, |rotulo_abrangencia."
fi

[ -z "$achados" ] && exit 0

{
    printf 'Padrão visual violado em %s:\n\n%s\n' "${arquivo##*/}" "$achados"
    printf 'Regra em CLAUDE.md, "Padrão visual". Corrija antes de seguir.\n'
    printf 'Verificação completa: docker compose exec -T php php scripts/verificar-padrao.php\n'
} >&2

exit 2
