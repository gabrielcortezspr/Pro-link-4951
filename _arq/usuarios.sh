#!/usr/bin/env bash
# =====================================================================================
# Usuário de aplicação com menor privilégio (D82).
#
# Por que existe: até a D82 a aplicação conectava com o usuário que o próprio contêiner do
# MariaDB cria (MARIADB_USER), que tem ALL PRIVILEGES em prolink.*. Uma injeção de SQL que
# escapasse dos prepared statements, ou um controller comprometido, poderia então apagar a
# trilha de auditoria com DROP TRIGGER seguido de DELETE, ou mudar a estrutura. O trigger só
# protege a trilha contra quem não pode removê-lo.
#
# O que faz: cria (ou atualiza) o usuário da aplicação e concede, tabela por tabela:
#   - SELECT, INSERT, UPDATE em toda tabela do banco;
#   - SELECT e INSERT, e nada mais, em sis_auditoria (insert-only também por privilégio, não
#     só por trigger);
#   - SELECT nas views (crea_evidencias).
# Nenhum privilégio de estrutura (CREATE, ALTER, DROP, INDEX, TRIGGER, REFERENCES) e nenhum
# DELETE: o src/ não tem DELETE em lugar nenhum, porque exclusão é lógica (_status = 'X', 8.6j).
#
# Por que por tabela e não GRANT ... ON prolink.*: o MariaDB não revoga parte de um privilégio
# de banco. Um GRANT UPDATE ON prolink.* valeria para sis_auditoria também, e não há REVOKE
# que tire só aquela tabela. A lista de tabelas sai do information_schema, então tabela nova no
# estrutura.sql já nasce com o privilégio padrão sem editar este arquivo.
#
# Quem roda:
#   1. Clone limpo: o docker-compose monta este arquivo como
#      /docker-entrypoint-initdb.d/03-usuarios.sh, que roda depois da estrutura e da carga.
#      O arquivo é versionado com bit de execução (755) e shebang, de propósito: no Docker
#      Desktop do macOS o entrypoint vê qualquer arquivo montado como executável e tenta rodá-lo
#      direto, e um .sh sem o bit falha com "Permission denied" (conferido em 25/09). No Linux,
#      sem o bit ele seria lido com "source"; o script funciona dos dois jeitos.
#      Precisa de fim de linha LF: com CRLF o shebang quebra.
#   2. Banco que já existe, ou depois de uma migração que crie tabela (o GRANT é por tabela,
#      então tabela criada depois não tem privilégio até rodar de novo). Idempotente:
#        DB_APP_PASSWORD="<a do .env>" docker compose exec -T -e DB_APP_PASSWORD \
#          -e DB_APP_USERNAME=prolink_app mariadb bash -s < _arq/usuarios.sh
#
# Senha: vem de DB_APP_PASSWORD no ambiente, nunca deste arquivo (Anexo VI). Sem senha o
# script recusa, em vez de criar um usuário sem senha.
# =====================================================================================

prolink_provisionar_usuario_app() {
    local banco="${MARIADB_DATABASE:-prolink}"
    local usuario="${DB_APP_USERNAME:-prolink_app}"
    local senha="${DB_APP_PASSWORD:-}"

    # Tabelas insert-only por privilégio. Separadas por espaço.
    local somente_insercao="sis_auditoria"

    if [ -z "$senha" ]; then
        echo "usuarios.sh: DB_APP_PASSWORD vazio. Defina no .env antes de subir (D82)." >&2
        return 1
    fi

    # Nome de usuário e de banco entram no SQL como identificador, que não aceita placeholder.
    # Por isso a lista branca estreita, em vez de escapar.
    if ! [[ "$usuario" =~ ^[A-Za-z0-9_]{1,32}$ ]] || ! [[ "$banco" =~ ^[A-Za-z0-9_]{1,64}$ ]]; then
        echo "usuarios.sh: DB_APP_USERNAME ou banco com caractere fora de [A-Za-z0-9_]." >&2
        return 1
    fi

    # A senha entra como literal de string: escapar barra invertida e aspas simples.
    local senha_sql="${senha//\\/\\\\}"
    senha_sql="${senha_sql//\'/\\\'}"

    # Mesmo cliente que o entrypoint usa: root pelo socket, senha pelo ambiente (MYSQL_PWD),
    # para ela não aparecer na lista de processos.
    local cliente=(mariadb --protocol=socket -uroot -hlocalhost)
    if [ -n "${SOCKET:-}" ]; then
        cliente+=(--socket="$SOCKET")
    fi

    local lista_insercao=""
    local t
    for t in $somente_insercao; do
        lista_insercao+="${lista_insercao:+,}'${t}'"
    done

    local grants
    grants=$(MYSQL_PWD="$MARIADB_ROOT_PASSWORD" "${cliente[@]}" -N -B -e "
        SELECT CONCAT(
                 'GRANT ',
                 CASE
                   WHEN TABLE_TYPE = 'VIEW'                    THEN 'SELECT'
                   WHEN TABLE_NAME IN (${lista_insercao})      THEN 'SELECT, INSERT'
                   ELSE                                             'SELECT, INSERT, UPDATE'
                 END,
                 ' ON \`', TABLE_SCHEMA, '\`.\`', TABLE_NAME, '\` TO \`${usuario}\`@\`%\`;')
          FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = '${banco}'
         ORDER BY TABLE_NAME") || return 1

    if [ -z "$grants" ]; then
        echo "usuarios.sh: o banco ${banco} não tem tabela. Rode depois de estrutura.sql." >&2
        return 1
    fi

    # REVOKE ALL antes do GRANT deixa o resultado igual ao desta lista, mesmo que alguém tenha
    # concedido algo a mais à mão: rodar de novo é conferir, não acumular.
    MYSQL_PWD="$MARIADB_ROOT_PASSWORD" "${cliente[@]}" <<SQL || return 1
CREATE USER IF NOT EXISTS \`${usuario}\`@\`%\` IDENTIFIED BY '${senha_sql}';
ALTER USER \`${usuario}\`@\`%\` IDENTIFIED BY '${senha_sql}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM \`${usuario}\`@\`%\`;
${grants}
FLUSH PRIVILEGES;
SQL

    echo "usuarios.sh: ${usuario} provisionado em ${banco} com $(printf '%s\n' "$grants" | wc -l | tr -d ' ') concessões por tabela (D82)."
}

# Executado ou lido com "source" pelo entrypoint, o exit derruba a inicialização, e é o que se quer.
# Um banco que sobe sem o usuário da aplicação só falharia depois, na primeira requisição.
prolink_provisionar_usuario_app || exit 1
