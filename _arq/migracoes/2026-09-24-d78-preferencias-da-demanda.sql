-- =====================================================================================
-- Migração para bancos criados antes da D78 (24/09/2026).
--
-- Quem sobe o banco do zero pelo `_arq/estrutura.sql` já tem estas colunas e não precisa disto.
-- Serve para o volume Docker de quem já tinha o projeto rodando: `git pull` traz o esquema novo
-- no arquivo, mas não altera o banco que já existe (foi o erro 500 de 24/09, com `man_origem`).
--
--   docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" prolink' \
--     < _arq/migracoes/2026-09-24-d78-preferencias-da-demanda.sql
--
-- Idempotente: `ADD COLUMN IF NOT EXISTS` deixa rodar de novo sem erro.
-- =====================================================================================

ALTER TABLE pro_demandas
  ADD COLUMN IF NOT EXISTS dem_inicio_ate DATE NULL
    COMMENT 'início desejado até esta data; NULL = prazo em aberto (D78)' AFTER dem_tipo_contrato;

ALTER TABLE pro_manifestacoes
  ADD COLUMN IF NOT EXISTS man_aceita_contrato CHAR(1) NULL
    COMMENT 'S = aceita | N = não aceita | C = prefere conversar' AFTER man_mensagem,
  ADD COLUMN IF NOT EXISTS man_atende_local CHAR(1) NULL
    COMMENT 'S = atende a região da demanda | N = não atende' AFTER man_aceita_contrato,
  ADD COLUMN IF NOT EXISTS man_inicio_em DATE NULL
    COMMENT 'quando pode começar' AFTER man_atende_local;
