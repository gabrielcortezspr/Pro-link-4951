-- =====================================================================================
-- Pro-Link — estrutura do banco de dados
-- Desafio CREA Pro-Link · II CENATEC 2026 · Equipe 49/51
--
-- MariaDB 10.11+ · utf8mb4 · utf8mb4_unicode_ci   (edital, Anexo I, 8.1.2)
--
-- Convenções do item 8.6 do edital:
--   tabela  <modulo>_<entidade>          sis_usuarios, pro_demandas
--   campo   <prefixo3>_<atributo>        usu_id, usu_nome
--   PK      pk_<prefixo>_id  ·  FK  fk_<tabela>_<referencia>
--   controle em toda tabela: _dt_registro, _log, _status CHAR(1) DEFAULT 'A'
--   exclusão lógica: _status = 'X' (8.6j). Nada é apagado fisicamente.
--
-- Módulos:
--   sis_   identidade, privacidade, auditoria e parâmetros
--   pro_   domínio Pro-Link: perfis, demandas, manifestações, moderação
--   crea_  cache das respostas da API oficial (reconstruível — ver docs/matching.md)
--          + a view crea_evidencias, que o motor consulta
--   mat_   sessões do motor de compatibilização (semente e pool auditáveis)
-- =====================================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS prolink
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE prolink;


-- =====================================================================================
-- MÓDULO sis — identidade, privacidade e auditoria
-- =====================================================================================

-- Perfis de acesso (edital, Anexo I, item 3)
CREATE TABLE sis_perfis (
  per_id          TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  per_codigo      VARCHAR(20)  NOT NULL COMMENT 'PUBLICO|PROFISSIONAL|EMPRESA|TERCEIRO|ADMIN',
  per_nome        VARCHAR(60)  NOT NULL,
  per_descricao   VARCHAR(255) NULL,
  per_dt_registro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  per_log         TEXT         NULL,
  per_status      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_per_id PRIMARY KEY (per_id),
  CONSTRAINT uq_per_codigo UNIQUE (per_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Usuários. CPF/CNPJ ficam cifrados em repouso (AES-256-GCM, chave em APP_KEY).
-- O hash cego permite buscar pelo documento sem decifrar a coluna inteira.
CREATE TABLE sis_usuarios (
  usu_id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  usu_per_id          TINYINT UNSIGNED NOT NULL,
  usu_nome            VARCHAR(150)     NOT NULL,
  usu_email           VARCHAR(190)     NOT NULL,
  usu_senha_hash      VARCHAR(255)     NOT NULL COMMENT 'password_hash(), Argon2id — edital 8.5b',
  usu_tipo_pessoa     CHAR(1)          NOT NULL DEFAULT 'F' COMMENT 'F=física, J=jurídica',
  usu_documento_cif   VARBINARY(255)   NULL COMMENT 'CPF/CNPJ cifrado (AES-256-GCM)',
  usu_documento_hash  CHAR(64)         NULL COMMENT 'SHA-256 com pepper, só para busca exata',
  usu_telefone        VARCHAR(20)      NULL,
  usu_email_verificado TINYINT(1)      NOT NULL DEFAULT 0,
  usu_dt_ultimo_login DATETIME         NULL,
  usu_tentativas      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  usu_bloqueado_ate   DATETIME         NULL COMMENT 'bloqueio temporário por força bruta (A07)',
  usu_dt_registro     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
  usu_log             TEXT             NULL,
  usu_status          CHAR(1)          NOT NULL DEFAULT 'A',
  CONSTRAINT pk_usu_id PRIMARY KEY (usu_id),
  CONSTRAINT uq_usu_email UNIQUE (usu_email),
  CONSTRAINT fk_usu_per_id FOREIGN KEY (usu_per_id) REFERENCES sis_perfis (per_id),
  INDEX ix_usu_documento_hash (usu_documento_hash),
  INDEX ix_usu_status (usu_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Sessões ativas. Guardar só o hash do token permite revogação sem armazenar credencial.
CREATE TABLE sis_sessoes (
  ses_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ses_usu_id       BIGINT UNSIGNED NOT NULL,
  ses_token_hash   CHAR(64)        NOT NULL,
  ses_ip           VARCHAR(45)     NULL,
  ses_user_agent   VARCHAR(255)    NULL,
  ses_dt_expiracao DATETIME        NOT NULL,
  ses_dt_revogacao DATETIME        NULL,
  ses_dt_registro  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ses_log          TEXT            NULL,
  ses_status       CHAR(1)         NOT NULL DEFAULT 'A',
  CONSTRAINT pk_ses_id PRIMARY KEY (ses_id),
  CONSTRAINT uq_ses_token_hash UNIQUE (ses_token_hash),
  CONSTRAINT fk_ses_usu_id FOREIGN KEY (ses_usu_id) REFERENCES sis_usuarios (usu_id),
  INDEX ix_ses_expiracao (ses_dt_expiracao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Recuperação de senha por e-mail (RF01, RF07)
CREATE TABLE sis_recuperacoes (
  rec_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  rec_usu_id       BIGINT UNSIGNED NOT NULL,
  rec_token_hash   CHAR(64)        NOT NULL,
  rec_dt_expiracao DATETIME        NOT NULL,
  rec_dt_uso       DATETIME        NULL,
  rec_ip_solicitante VARCHAR(45)   NULL,
  rec_dt_registro  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  rec_log          TEXT            NULL,
  rec_status       CHAR(1)         NOT NULL DEFAULT 'A',
  CONSTRAINT pk_rec_id PRIMARY KEY (rec_id),
  CONSTRAINT uq_rec_token_hash UNIQUE (rec_token_hash),
  CONSTRAINT fk_rec_usu_id FOREIGN KEY (rec_usu_id) REFERENCES sis_usuarios (usu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Versões dos Termos de Uso e da Política de Privacidade
CREATE TABLE sis_termos (
  ter_id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ter_tipo         VARCHAR(20)  NOT NULL COMMENT 'USO|PRIVACIDADE',
  ter_versao       VARCHAR(20)  NOT NULL,
  ter_conteudo     MEDIUMTEXT   NOT NULL,
  ter_dt_vigencia  DATE         NOT NULL,
  ter_dt_registro  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ter_log          TEXT         NULL,
  ter_status       CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_ter_id PRIMARY KEY (ter_id),
  CONSTRAINT uq_ter_tipo_versao UNIQUE (ter_tipo, ter_versao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Consentimentos LGPD, granulares e revogáveis (RF01, edital 11.3)
CREATE TABLE sis_consentimentos (
  con_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  con_usu_id        BIGINT UNSIGNED NOT NULL,
  con_ter_id        INT UNSIGNED    NULL,
  con_finalidade    VARCHAR(80)     NOT NULL COMMENT 'CONSULTA_API|EXIBICAO_PERFIL|NOTIFICACOES|...',
  con_concedido     TINYINT(1)      NOT NULL DEFAULT 1,
  con_dt_concessao  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  con_dt_revogacao  DATETIME        NULL,
  con_ip            VARCHAR(45)     NULL,
  con_dt_registro   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  con_log           TEXT            NULL,
  con_status        CHAR(1)         NOT NULL DEFAULT 'A',
  CONSTRAINT pk_con_id PRIMARY KEY (con_id),
  CONSTRAINT fk_con_usu_id FOREIGN KEY (con_usu_id) REFERENCES sis_usuarios (usu_id),
  CONSTRAINT fk_con_ter_id FOREIGN KEY (con_ter_id) REFERENCES sis_termos (ter_id),
  INDEX ix_con_usu_finalidade (con_usu_id, con_finalidade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Trilha de auditoria. INSERT-ONLY: os triggers no fim do arquivo bloqueiam UPDATE e DELETE,
-- inclusive para o administrador (edital 8.5g; OWASP A09).
CREATE TABLE sis_auditoria (
  aud_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  aud_usu_id         BIGINT UNSIGNED NULL COMMENT 'nulo em ação anônima',
  aud_ip             VARCHAR(45)     NULL,
  aud_acao           VARCHAR(60)     NOT NULL COMMENT 'LOGIN|CRIAR|EDITAR|EXCLUIR|BLOQUEAR|...',
  aud_entidade       VARCHAR(60)     NULL,
  aud_entidade_id    BIGINT UNSIGNED NULL,
  aud_campo          VARCHAR(60)     NULL,
  aud_valor_anterior TEXT            NULL,
  aud_valor_novo     TEXT            NULL,
  aud_user_agent     VARCHAR(255)    NULL,
  aud_dt_registro    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  CONSTRAINT pk_aud_id PRIMARY KEY (aud_id),
  INDEX ix_aud_usu_dt (aud_usu_id, aud_dt_registro),
  INDEX ix_aud_entidade (aud_entidade, aud_entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Fila e histórico de notificações por e-mail (RF07)
CREATE TABLE sis_notificacoes (
  not_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  not_usu_id      BIGINT UNSIGNED NOT NULL,
  not_tipo        VARCHAR(60)     NOT NULL COMMENT 'CADASTRO|RECUPERACAO_SENHA|MANIFESTACAO|DEMANDA|DENUNCIA',
  not_destinatario VARCHAR(190)   NOT NULL,
  not_assunto     VARCHAR(190)    NOT NULL,
  not_corpo       MEDIUMTEXT      NOT NULL,
  not_dt_envio    DATETIME        NULL,
  not_tentativas  TINYINT UNSIGNED NOT NULL DEFAULT 0,
  not_erro        VARCHAR(255)    NULL,
  not_dt_registro DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  not_log         TEXT            NULL,
  not_status      CHAR(1)         NOT NULL DEFAULT 'A',
  CONSTRAINT pk_not_id PRIMARY KEY (not_id),
  CONSTRAINT fk_not_usu_id FOREIGN KEY (not_usu_id) REFERENCES sis_usuarios (usu_id),
  INDEX ix_not_pendente (not_dt_envio, not_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Parâmetros editáveis pelo administrador: SMTP, pesos e limiar do motor.
-- O item 12.3 do edital exige supervisão humana sobre os critérios de recomendação.
CREATE TABLE sis_parametros (
  par_id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  par_chave       VARCHAR(80)  NOT NULL,
  par_valor       TEXT         NULL,
  par_tipo        VARCHAR(20)  NOT NULL DEFAULT 'TEXTO' COMMENT 'TEXTO|NUMERO|BOOLEANO|JSON',
  par_grupo       VARCHAR(40)  NOT NULL DEFAULT 'GERAL' COMMENT 'SMTP|MATCHING|SEGURANCA|GERAL',
  par_descricao   VARCHAR(255) NULL,
  par_sensivel    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1 = não exibir valor no painel',
  par_dt_registro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  par_log         TEXT         NULL,
  par_status      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_par_id PRIMARY KEY (par_id),
  CONSTRAINT uq_par_chave UNIQUE (par_chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================================
-- MÓDULO crea — cache das respostas da API oficial
--
-- Não é base paralela: cada linha é resposta real da API, com data de consulta e hash de
-- integridade, reconstruível a qualquer momento. O item 8.4 do edital veda base própria que
-- SIMULE os dados da API; cache de resposta real, documentado como tal, é outra coisa.
-- Nenhum registro aqui é escrito à mão ou por seed.
-- =====================================================================================

-- Tabela de Obras e Serviços — 2000 linhas, 46 grupos, estática durante o desafio.
-- Os quatro níveis em colunas indexadas fazem a busca por prefixo virar query.
CREATE TABLE crea_tos (
  tos_id            SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tos_codigo        VARCHAR(30)  NOT NULL COMMENT 'TOS_1.1.2.1',
  tos_grupo         VARCHAR(150) NOT NULL,
  tos_subgrupo      VARCHAR(150) NULL,
  tos_obra_servico  VARCHAR(255) NULL,
  tos_complementar  VARCHAR(255) NULL COMMENT 'NULL quando o código tem 3 níveis',
  tos_nivel1        SMALLINT UNSIGNED NOT NULL,
  tos_nivel2        SMALLINT UNSIGNED NULL,
  tos_nivel3        SMALLINT UNSIGNED NULL,
  tos_nivel4        SMALLINT UNSIGNED NULL,
  tos_profundidade  TINYINT UNSIGNED  NOT NULL,
  tos_dt_registro   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  tos_log           TEXT     NULL,
  tos_status        CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_tos_id PRIMARY KEY (tos_id),
  CONSTRAINT uq_tos_codigo UNIQUE (tos_codigo),
  INDEX ix_tos_hierarquia (tos_nivel1, tos_nivel2, tos_nivel3, tos_nivel4),
  INDEX ix_tos_grupo (tos_nivel1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Modalidades: tabela fechada de 25, vem junto do cadastro do profissional
CREATE TABLE crea_modalidades (
  mod_id          TINYINT UNSIGNED NOT NULL,
  mod_codigo      VARCHAR(10)  NULL COMMENT 'sigla de 3 letras: FLO, CIV, ELE',
  mod_nome        VARCHAR(120) NOT NULL,
  mod_dt_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mod_log         TEXT     NULL,
  mod_status      CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_mod_id PRIMARY KEY (mod_id),
  CONSTRAINT uq_mod_codigo UNIQUE (mod_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ARTs validadas pela API. art_hash é o Selo ART: assinado server-side sobre a resposta
-- canonicalizada, nunca uma flag editável no banco (proposta, cenário 03A; OWASP A08).
CREATE TABLE crea_arts (
  art_id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  art_numero           VARCHAR(20)  NOT NULL COMMENT 'AM20269999001',
  art_pro_rnp          VARCHAR(10)  NOT NULL COMMENT 'string: tem zero à esquerda',
  art_tipo             VARCHAR(30)  NULL,
  art_forma_registro   VARCHAR(30)  NULL,
  art_contratante_nome VARCHAR(190) NULL,
  art_objeto           VARCHAR(255) NULL,
  art_local_uf         CHAR(2)      NULL,
  art_local_municipio  VARCHAR(120) NULL,
  art_situacao         VARCHAR(30)  NULL COMMENT 'REGISTRADA|...',
  art_hash             CHAR(64)     NOT NULL COMMENT 'HMAC-SHA256 da resposta canonicalizada',
  art_dt_consulta      DATETIME     NOT NULL COMMENT 'quando a API respondeu isto',
  art_dt_registro      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  art_log              TEXT         NULL,
  art_status           CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_art_id PRIMARY KEY (art_id),
  CONSTRAINT uq_art_numero UNIQUE (art_numero),
  INDEX ix_art_rnp (art_pro_rnp),
  INDEX ix_art_situacao (art_situacao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Atividades TOS de cada ART: o nível granular onde mora a evidência
CREATE TABLE crea_art_atividades (
  ata_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ata_art_id      BIGINT UNSIGNED NOT NULL,
  ata_tos_codigo  VARCHAR(30)  NOT NULL,
  ata_descricao   VARCHAR(255) NULL COMMENT 'aat_descricao: texto livre do profissional',
  ata_dt_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ata_log         TEXT     NULL,
  ata_status      CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_ata_id PRIMARY KEY (ata_id),
  CONSTRAINT uq_ata_art_tos UNIQUE (ata_art_id, ata_tos_codigo),
  CONSTRAINT fk_ata_art_id FOREIGN KEY (ata_art_id) REFERENCES crea_arts (art_id),
  INDEX ix_ata_tos (ata_tos_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- CATs validadas pela API
CREATE TABLE crea_cats (
  cat_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cat_numero       VARCHAR(20) NOT NULL COMMENT '999001/2026',
  cat_pro_rnp      VARCHAR(10) NOT NULL,
  cat_tipo         VARCHAR(30) NULL,
  cat_dt_emissao   DATE        NULL,
  cat_dt_validade  DATE        NULL,
  cat_finalidade   VARCHAR(80) NULL,
  cat_hash         CHAR(64)    NOT NULL,
  cat_dt_consulta  DATETIME    NOT NULL,
  cat_dt_registro  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cat_log          TEXT        NULL,
  cat_status       CHAR(1)     NOT NULL DEFAULT 'A',
  CONSTRAINT pk_cat_id PRIMARY KEY (cat_id),
  CONSTRAINT uq_cat_numero UNIQUE (cat_numero),
  INDEX ix_cat_rnp (cat_pro_rnp),
  INDEX ix_cat_validade (cat_dt_validade)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Quais ARTs cada CAT agrupa (N para N)
CREATE TABLE crea_cat_arts (
  cta_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  cta_cat_id      BIGINT UNSIGNED NOT NULL,
  cta_art_id      BIGINT UNSIGNED NOT NULL,
  cta_dt_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cta_log         TEXT     NULL,
  cta_status      CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_cta_id PRIMARY KEY (cta_id),
  CONSTRAINT uq_cta_cat_art UNIQUE (cta_cat_id, cta_art_id),
  CONSTRAINT fk_cta_cat_id FOREIGN KEY (cta_cat_id) REFERENCES crea_cats (cat_id),
  CONSTRAINT fk_cta_art_id FOREIGN KEY (cta_art_id) REFERENCES crea_arts (art_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Quadro técnico da empresa. qut_dt_fim NÃO nulo encerra o vínculo: a empresa deixa de
-- herdar o acervo daquele profissional (ver docs/matching.md, etapa 2).
CREATE TABLE crea_quadro_tecnico (
  qut_id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  qut_emp_registro_crea VARCHAR(10) NOT NULL,
  qut_pro_rnp           VARCHAR(10) NOT NULL,
  qut_tipo              CHAR(1)     NULL COMMENT 'R = responsável técnico',
  qut_funcao            VARCHAR(80) NULL,
  qut_dt_inicio         DATE        NULL,
  qut_dt_fim            DATE        NULL COMMENT 'NULL = vínculo vigente',
  qut_dt_consulta       DATETIME    NOT NULL,
  qut_dt_registro       DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  qut_log               TEXT        NULL,
  qut_status            CHAR(1)     NOT NULL DEFAULT 'A',
  CONSTRAINT pk_qut_id PRIMARY KEY (qut_id),
  CONSTRAINT uq_qut_emp_pro UNIQUE (qut_emp_registro_crea, qut_pro_rnp, qut_dt_inicio),
  INDEX ix_qut_emp (qut_emp_registro_crea),
  INDEX ix_qut_pro (qut_pro_rnp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================================
-- MÓDULO pro — domínio Pro-Link
-- =====================================================================================

-- Perfil de profissional registrado no CREA-AM
CREATE TABLE pro_profissionais (
  prf_id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  prf_usu_id           BIGINT UNSIGNED NOT NULL,
  prf_rnp              VARCHAR(10)  NOT NULL COMMENT 'string: zero à esquerda',
  prf_registro_crea    VARCHAR(10)  NULL,
  prf_nome_api         VARCHAR(150) NULL COMMENT 'nome como a API devolve; não editável',
  prf_status_api       CHAR(1)      NULL COMMENT 'pro_status; != A zera a visibilidade',
  prf_resumo           TEXT         NULL COMMENT 'autodeclarado',
  prf_tipo_contrato    VARCHAR(40)  NULL COMMENT 'autodeclarado: CLT|PJ|OBRA_CERTA|...',
  prf_disponibilidade  VARCHAR(120) NULL COMMENT 'autodeclarado: raio ou municípios',
  prf_em_construcao    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'início de carreira; nunca exclui do pool',
  prf_dt_sincronizacao DATETIME     NULL,
  prf_dt_registro      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  prf_log              TEXT         NULL,
  prf_status           CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_prf_id PRIMARY KEY (prf_id),
  CONSTRAINT uq_prf_rnp UNIQUE (prf_rnp),
  CONSTRAINT fk_prf_usu_id FOREIGN KEY (prf_usu_id) REFERENCES sis_usuarios (usu_id),
  INDEX ix_prf_status_api (prf_status_api)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE pro_prof_modalidades (
  pmo_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  pmo_prf_id      BIGINT UNSIGNED NOT NULL,
  pmo_mod_id      TINYINT UNSIGNED NOT NULL,
  pmo_dt_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pmo_log         TEXT     NULL,
  pmo_status      CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_pmo_id PRIMARY KEY (pmo_id),
  CONSTRAINT uq_pmo_prf_mod UNIQUE (pmo_prf_id, pmo_mod_id),
  CONSTRAINT fk_pmo_prf_id FOREIGN KEY (pmo_prf_id) REFERENCES pro_profissionais (prf_id),
  CONSTRAINT fk_pmo_mod_id FOREIGN KEY (pmo_mod_id) REFERENCES crea_modalidades (mod_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Perfil de empresa registrada no CREA-AM. É candidata do motor, não só demandante:
-- Terceiros podem registrar interesse em empresa (edital, Anexo I, item 3).
CREATE TABLE pro_empresas (
  emp_id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  emp_usu_id           BIGINT UNSIGNED NOT NULL,
  emp_registro_crea    VARCHAR(10)  NOT NULL,
  emp_razao_social     VARCHAR(190) NULL,
  emp_nome_fantasia    VARCHAR(190) NULL,
  emp_dt_registro_crea DATETIME     NULL,
  emp_resumo           TEXT         NULL COMMENT 'autodeclarado',
  emp_dt_sincronizacao DATETIME     NULL,
  emp_dt_registro      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  emp_log              TEXT         NULL,
  emp_status           CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_emp_id PRIMARY KEY (emp_id),
  CONSTRAINT uq_emp_registro_crea UNIQUE (emp_registro_crea),
  CONSTRAINT fk_emp_usu_id FOREIGN KEY (emp_usu_id) REFERENCES sis_usuarios (usu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Experiência autodeclarada, com ou sem ART (edital, Anexo I, item 3: "com ou sem ART/CAT").
-- Quando aponta para uma ART validada, a interface mostra as duas coisas lado a lado sem
-- misturá-las: dado verificado e dado declarado nunca se confundem visualmente.
CREATE TABLE pro_experiencias (
  exp_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  exp_prf_id      BIGINT UNSIGNED NOT NULL,
  exp_titulo      VARCHAR(190) NOT NULL,
  exp_descricao   TEXT         NULL,
  exp_art_id      BIGINT UNSIGNED NULL COMMENT 'NULL = experiência sem ART',
  exp_dt_inicio   DATE         NULL,
  exp_dt_fim      DATE         NULL,
  exp_dt_registro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  exp_log         TEXT         NULL,
  exp_status      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_exp_id PRIMARY KEY (exp_id),
  CONSTRAINT fk_exp_prf_id FOREIGN KEY (exp_prf_id) REFERENCES pro_profissionais (prf_id),
  CONSTRAINT fk_exp_art_id FOREIGN KEY (exp_art_id) REFERENCES crea_arts (art_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Visibilidade granular por campo e por ART. Nada é público por padrão (RF01, RF03).
CREATE TABLE pro_visibilidade (
  vis_id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  vis_usu_id       BIGINT UNSIGNED NOT NULL,
  vis_entidade     VARCHAR(40) NOT NULL COMMENT 'PERFIL|ART|CAT|EXPERIENCIA',
  vis_entidade_id  BIGINT UNSIGNED NULL,
  vis_campo        VARCHAR(60) NULL COMMENT 'NULL = a entidade inteira',
  vis_nivel        VARCHAR(20) NOT NULL DEFAULT 'PRIVADO' COMMENT 'PRIVADO|AUTENTICADO|PUBLICO',
  vis_dt_registro  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vis_log          TEXT     NULL,
  vis_status       CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_vis_id PRIMARY KEY (vis_id),
  CONSTRAINT uq_vis_alvo UNIQUE (vis_usu_id, vis_entidade, vis_entidade_id, vis_campo),
  CONSTRAINT fk_vis_usu_id FOREIGN KEY (vis_usu_id) REFERENCES sis_usuarios (usu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Demandas técnicas publicadas por empresa ou terceiro
CREATE TABLE pro_demandas (
  dem_id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dem_usu_id          BIGINT UNSIGNED NOT NULL,
  dem_titulo          VARCHAR(190) NOT NULL,
  dem_escopo          TEXT         NOT NULL,
  dem_local_uf        CHAR(2)      NULL,
  dem_local_municipio VARCHAR(120) NULL,
  dem_tipo_contrato   VARCHAR(40)  NULL,
  dem_alvo            CHAR(1)      NOT NULL DEFAULT 'P' COMMENT 'P = profissional, E = empresa, A = ambos',
  dem_situacao        VARCHAR(20)  NOT NULL DEFAULT 'ABERTA' COMMENT 'ABERTA|COM_INTERESSADOS|ENCERRADA',
  dem_dt_publicacao   DATETIME     NULL,
  dem_dt_encerramento DATETIME     NULL,
  dem_dt_registro     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dem_log             TEXT         NULL,
  dem_status          CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_dem_id PRIMARY KEY (dem_id),
  CONSTRAINT fk_dem_usu_id FOREIGN KEY (dem_usu_id) REFERENCES sis_usuarios (usu_id),
  INDEX ix_dem_situacao (dem_situacao, dem_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Códigos TOS que traduzem a necessidade. Peso distingue atividade principal de secundária.
CREATE TABLE pro_demanda_tos (
  dts_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  dts_dem_id      BIGINT UNSIGNED NOT NULL,
  dts_tos_codigo  VARCHAR(30) NOT NULL,
  dts_peso        DECIMAL(3,2) NOT NULL DEFAULT 1.00,
  dts_dt_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  dts_log         TEXT     NULL,
  dts_status      CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_dts_id PRIMARY KEY (dts_id),
  CONSTRAINT uq_dts_dem_tos UNIQUE (dts_dem_id, dts_tos_codigo),
  CONSTRAINT fk_dts_dem_id FOREIGN KEY (dts_dem_id) REFERENCES pro_demandas (dem_id),
  INDEX ix_dts_tos (dts_tos_codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Manifestação de interesse. O snapshot congela o perfil no momento do envio: alteração
-- posterior não muda o que o demandante viu (proposta, cenário 04).
CREATE TABLE pro_manifestacoes (
  man_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  man_dem_id         BIGINT UNSIGNED NOT NULL,
  man_usu_id         BIGINT UNSIGNED NOT NULL COMMENT 'quem manifestou',
  man_candidato_tipo CHAR(1)      NOT NULL DEFAULT 'P',
  man_mensagem       TEXT         NULL,
  man_snapshot       LONGTEXT     NOT NULL COMMENT 'JSON do perfil no instante do envio',
  man_snapshot_hash  CHAR(64)     NOT NULL,
  man_situacao       VARCHAR(20)  NOT NULL DEFAULT 'ENVIADA' COMMENT 'ENVIADA|VISUALIZADA|RESPONDIDA|ARQUIVADA',
  man_dt_visualizacao DATETIME    NULL,
  man_dt_registro    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  man_log            TEXT         NULL,
  man_status         CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_man_id PRIMARY KEY (man_id),
  CONSTRAINT uq_man_dem_usu UNIQUE (man_dem_id, man_usu_id),
  CONSTRAINT fk_man_dem_id FOREIGN KEY (man_dem_id) REFERENCES pro_demandas (dem_id),
  CONSTRAINT fk_man_usu_id FOREIGN KEY (man_usu_id) REFERENCES sis_usuarios (usu_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Canal de comunicação inicial, sem expor contato direto (RF05)
CREATE TABLE pro_mensagens (
  msg_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  msg_man_id      BIGINT UNSIGNED NOT NULL,
  msg_usu_id      BIGINT UNSIGNED NOT NULL COMMENT 'remetente',
  msg_corpo       TEXT     NOT NULL,
  msg_dt_leitura  DATETIME NULL,
  msg_dt_registro DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  msg_log         TEXT     NULL,
  msg_status      CHAR(1)  NOT NULL DEFAULT 'A',
  CONSTRAINT pk_msg_id PRIMARY KEY (msg_id),
  CONSTRAINT fk_msg_man_id FOREIGN KEY (msg_man_id) REFERENCES pro_manifestacoes (man_id),
  CONSTRAINT fk_msg_usu_id FOREIGN KEY (msg_usu_id) REFERENCES sis_usuarios (usu_id),
  INDEX ix_msg_man (msg_man_id, msg_dt_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Denúncias: sempre vinculadas a um alvo, nunca genéricas (RF06)
CREATE TABLE pro_denuncias (
  den_id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  den_usu_id        BIGINT UNSIGNED NOT NULL COMMENT 'denunciante autenticado',
  den_entidade      VARCHAR(40) NOT NULL COMMENT 'USUARIO|DEMANDA|MENSAGEM|EXPERIENCIA',
  den_entidade_id   BIGINT UNSIGNED NOT NULL,
  den_tipo          VARCHAR(60) NOT NULL,
  den_descricao     TEXT        NOT NULL,
  den_evidencia     VARCHAR(255) NULL COMMENT 'caminho de anexo, opcional',
  den_situacao      VARCHAR(20) NOT NULL DEFAULT 'PENDENTE' COMMENT 'PENDENTE|EM_ANALISE|RESOLVIDA',
  den_providencia   VARCHAR(60) NULL COMMENT 'ADVERTIR|BLOQUEAR|REMOVER|IMPROCEDENTE',
  den_usu_moderador BIGINT UNSIGNED NULL,
  den_dt_tratamento DATETIME    NULL,
  den_dt_registro   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  den_log           TEXT        NULL,
  den_status        CHAR(1)     NOT NULL DEFAULT 'A',
  CONSTRAINT pk_den_id PRIMARY KEY (den_id),
  CONSTRAINT fk_den_usu_id FOREIGN KEY (den_usu_id) REFERENCES sis_usuarios (usu_id),
  CONSTRAINT fk_den_moderador FOREIGN KEY (den_usu_moderador) REFERENCES sis_usuarios (usu_id),
  INDEX ix_den_situacao (den_situacao),
  INDEX ix_den_alvo (den_entidade, den_entidade_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================================
-- MÓDULO mat — sessões do motor de compatibilização
--
-- O item 10.1 do edital veda ranking de profissionais. O score decide QUEM entra no pool;
-- a semente decide EM QUE ORDEM o pool aparece. Guardar semente, limiar, pesos e pool_ids
-- torna qualquer sessão reproduzível pelo administrador (itens 10.1, 12.3 e 8.5g).
-- =====================================================================================

CREATE TABLE mat_sessoes (
  mts_id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  mts_dem_id      BIGINT UNSIGNED NOT NULL,
  mts_usu_id      BIGINT UNSIGNED NOT NULL COMMENT 'quem viu o feed',
  mts_semente     CHAR(32)     NOT NULL COMMENT 'semente da ordenação, pública por rodada',
  mts_limiar      DECIMAL(4,3) NOT NULL,
  mts_pesos       JSON         NOT NULL COMMENT 'pesos por dimensão vigentes na sessão',
  mts_total_pool  INT UNSIGNED NOT NULL DEFAULT 0,
  mts_ip          VARCHAR(45)  NULL,
  mts_dt_registro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  mts_log         TEXT         NULL,
  mts_status      CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_mts_id PRIMARY KEY (mts_id),
  CONSTRAINT fk_mts_dem_id FOREIGN KEY (mts_dem_id) REFERENCES pro_demandas (dem_id),
  CONSTRAINT fk_mts_usu_id FOREIGN KEY (mts_usu_id) REFERENCES sis_usuarios (usu_id),
  INDEX ix_mts_dem_dt (mts_dem_id, mts_dt_registro)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Um registro por candidato que entrou no pool, com os critérios que o justificaram.
-- msp_criterios alimenta a explicação mostrada ao demandante (Anexo VI: "critérios
-- explicáveis de compatibilização") e a auditoria do administrador.
CREATE TABLE mat_sessao_pool (
  msp_id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  msp_mts_id         BIGINT UNSIGNED NOT NULL,
  msp_candidato_tipo CHAR(1)      NOT NULL COMMENT 'P|E',
  msp_candidato_id   BIGINT UNSIGNED NOT NULL,
  msp_score          DECIMAL(4,3) NOT NULL,
  msp_criterios      JSON         NOT NULL COMMENT 'score por dimensão + ARTs/CATs que sustentam',
  msp_dt_registro    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  msp_log            TEXT         NULL,
  msp_status         CHAR(1)      NOT NULL DEFAULT 'A',
  CONSTRAINT pk_msp_id PRIMARY KEY (msp_id),
  CONSTRAINT uq_msp_sessao_candidato UNIQUE (msp_mts_id, msp_candidato_tipo, msp_candidato_id),
  CONSTRAINT fk_msp_mts_id FOREIGN KEY (msp_mts_id) REFERENCES mat_sessoes (mts_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================================
-- VIEW crea_evidencias — o que o motor de compatibilização consulta
--
-- Uma linha por (candidato, código TOS, ART, CAT). Não é tabela: é derivada das tabelas
-- crea_* e pro_* acima, logo nunca sai de sincronia e não precisa de código de manutenção.
-- Os índices ix_ata_tos, ix_tos_hierarquia, ix_art_rnp e ix_qut_pro servem a ela.
--
-- A regra de herança da empresa mora aqui e só aqui: a empresa vê a ART de um profissional
-- apenas enquanto o vínculo de quadro técnico está vigente (qut_dt_fim IS NULL).
-- =====================================================================================

CREATE OR REPLACE VIEW crea_evidencias AS
SELECT
  'P'                     AS evi_candidato_tipo,
  p.prf_id                AS evi_candidato_id,
  t.tos_codigo            AS evi_tos_codigo,
  t.tos_nivel1            AS evi_nivel1,
  t.tos_nivel2            AS evi_nivel2,
  t.tos_nivel3            AS evi_nivel3,
  t.tos_nivel4            AS evi_nivel4,
  a.art_numero            AS evi_art_numero,
  a.art_situacao          AS evi_art_situacao,
  a.art_local_uf          AS evi_art_local_uf,
  a.art_local_municipio   AS evi_art_local_municipio,
  c.cat_numero            AS evi_cat_numero,
  c.cat_dt_validade       AS evi_cat_dt_validade,
  a.art_pro_rnp           AS evi_pro_rnp,
  a.art_dt_consulta       AS evi_dt_consulta
FROM crea_arts a
JOIN crea_art_atividades ata ON ata.ata_art_id = a.art_id AND ata.ata_status = 'A'
JOIN crea_tos t              ON t.tos_codigo = ata.ata_tos_codigo
JOIN pro_profissionais p     ON p.prf_rnp = a.art_pro_rnp AND p.prf_status = 'A'
LEFT JOIN crea_cat_arts cta  ON cta.cta_art_id = a.art_id AND cta.cta_status = 'A'
LEFT JOIN crea_cats c        ON c.cat_id = cta.cta_cat_id AND c.cat_status = 'A'
WHERE a.art_status = 'A'

UNION ALL

SELECT
  'E',
  e.emp_id,
  t.tos_codigo,
  t.tos_nivel1, t.tos_nivel2, t.tos_nivel3, t.tos_nivel4,
  a.art_numero, a.art_situacao, a.art_local_uf, a.art_local_municipio,
  c.cat_numero, c.cat_dt_validade,
  a.art_pro_rnp,
  a.art_dt_consulta
FROM crea_arts a
JOIN crea_art_atividades ata ON ata.ata_art_id = a.art_id AND ata.ata_status = 'A'
JOIN crea_tos t              ON t.tos_codigo = ata.ata_tos_codigo
JOIN crea_quadro_tecnico q   ON q.qut_pro_rnp = a.art_pro_rnp AND q.qut_status = 'A' AND q.qut_dt_fim IS NULL
JOIN pro_empresas e          ON e.emp_registro_crea = q.qut_emp_registro_crea AND e.emp_status = 'A'
LEFT JOIN crea_cat_arts cta  ON cta.cta_art_id = a.art_id AND cta.cta_status = 'A'
LEFT JOIN crea_cats c        ON c.cat_id = cta.cta_cat_id AND c.cat_status = 'A'
WHERE a.art_status = 'A';


-- =====================================================================================
-- Imutabilidade da trilha de auditoria (edital 8.5g; OWASP A09)
-- Nem o administrador altera ou apaga. A tabela é insert-only no próprio banco.
-- =====================================================================================

DELIMITER //

CREATE TRIGGER trg_aud_bloqueia_update
BEFORE UPDATE ON sis_auditoria
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'sis_auditoria e insert-only: alteracao vedada pelo item 8.5g do edital';
END//

CREATE TRIGGER trg_aud_bloqueia_delete
BEFORE DELETE ON sis_auditoria
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'sis_auditoria e insert-only: exclusao vedada pelo item 8.5g do edital';
END//

DELIMITER ;

SET FOREIGN_KEY_CHECKS = 1;
