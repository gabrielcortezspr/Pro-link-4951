-- =====================================================================================
-- Migração para bancos criados antes da D88 (25/09/2026): o modelo de mensagem de convite.
--
--   docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" prolink' \
--     < _arq/migracoes/2026-09-25-d88-modelo-convite.sql
--
-- Coluna nova em tabela que já existe: o privilégio do usuário da aplicação é por tabela (D82),
-- então não é preciso rodar o _arq/usuarios.sh de novo. Idempotente.
-- =====================================================================================

ALTER TABLE sis_usuarios
  ADD COLUMN IF NOT EXISTS usu_modelo_convite TEXT NULL
    COMMENT 'modelo da mensagem de convite, com {nome}, {demanda} e {local}; NULL = o padrão da plataforma (D88)'
    AFTER usu_telefone;
