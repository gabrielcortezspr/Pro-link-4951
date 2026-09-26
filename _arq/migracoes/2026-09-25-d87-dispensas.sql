-- =====================================================================================
-- Migração para bancos criados antes da D87 (25/09/2026): a tabela das dispensas do feed.
--
-- Quem sobe do `_arq/estrutura.sql` já tem a tabela. Para o volume que já existia:
--
--   docker compose exec -T mariadb sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" prolink' \
--     < _arq/migracoes/2026-09-25-d87-dispensas.sql
--
-- **Depois, rode de novo o provisionamento do usuário da aplicação** (D82): o privilégio é por
-- tabela, e sem isso a tela de compatíveis recebe "command denied" na tabela nova.
--
--   DB_APP_PASSWORD="$(grep '^DB_APP_PASSWORD=' .env | cut -d= -f2-)" \
--     docker compose exec -T -e DB_APP_PASSWORD -e DB_APP_USERNAME=prolink_app mariadb bash -s \
--     < _arq/usuarios.sh
--
-- Idempotente: `CREATE TABLE IF NOT EXISTS`.
-- =====================================================================================

CREATE TABLE IF NOT EXISTS pro_dispensas (
  dsp_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dsp_dem_id      BIGINT UNSIGNED NOT NULL,
  dsp_usu_id      BIGINT UNSIGNED NOT NULL COMMENT 'titular do perfil dispensado',
  dsp_usu_autor   BIGINT UNSIGNED NOT NULL COMMENT 'quem dispensou: a dona da demanda',
  dsp_dt_registro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dsp_log         TEXT         NULL,
  dsp_status      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_dsp_id PRIMARY KEY (dsp_id),
  CONSTRAINT uq_dsp_dem_usu UNIQUE (dsp_dem_id, dsp_usu_id),
  CONSTRAINT fk_dsp_dem_id FOREIGN KEY (dsp_dem_id) REFERENCES pro_demandas (dem_id),
  CONSTRAINT fk_dsp_usu_id FOREIGN KEY (dsp_usu_id) REFERENCES sis_usuarios (usu_id),
  CONSTRAINT fk_dsp_usu_autor FOREIGN KEY (dsp_usu_autor) REFERENCES sis_usuarios (usu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
