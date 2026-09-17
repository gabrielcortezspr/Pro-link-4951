#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
gerar-mer.py -- gera o MER (Modelo Entidade-Relacionamento) do banco `prolink`.

COMO RODAR
----------
    cd /Users/camilamoi/Documents/Pro-link-4951
    python3 scripts/gerar-mer.py

Pre-requisitos (ja presentes no ambiente, nada novo para instalar):
  - `docker compose` com o servico `mariadb` de pe (mesmo container do dia a dia).
  - `node` + `npx` com Playwright 1.62 e o Chromium ja em cache
    (`npx playwright --version` deve responder sem baixar nada).
  - Python 3.10+ (so biblioteca padrao: subprocess, re, math, base64, json).

O QUE O SCRIPT FAZ
------------------
  1. Le a estrutura REAL do banco via `information_schema` (TABLES, COLUMNS,
     KEY_COLUMN_USAGE + REFERENTIAL_CONSTRAINTS, STATISTICS). So SELECT/SHOW,
     nunca escreve no banco.
  2. Monta um modelo em memoria: tabelas por módulo (prefixo sis_/crea_/pro_/mat_),
     colunas, PK, colunas unicas de coluna-única (para achar relações 1:1),
     FKs declaradas (com nulidade, para cardinalidade), e um pequeno detector
     de "referencia por convencao de nome sem FK declarada" (ver SOFT_REF_RULES
     abaixo -- e uma lista curada, nao 100% automatica, porque nao ha nenhuma
     FK real dessas familias para aprender o padrao a partir dos dados).
  3. Desenha 5 diagramas -- 1 panorama (visão geral dos 4 módulos + a view) e
     4 diagramas de detalhe (um por módulo) -- como HTML+SVG autocontido, no
     design system do Pro-Link (tokens de public/assets/css/prolink.css,
     fontes Inter/Space Grotesk embutidas em base64 a partir dos .woff2 ja
     versionados no repo). Nao usa Mermaid nem nenhuma lib externa -- ver
     _arq/mer/README.md para o motivo da escolha.
  4. Usa `npx playwright screenshot --full-page` e `npx playwright pdf` (CLI
     que ja vem com o Playwright, sem precisar escrever driver Node algum)
     para exportar:
       _arq/mer/mer.png        panorama (visão geral, os 4 módulos + a view)
       _arq/mer/mer-sis.png    módulo sis_ (detalhado)
       _arq/mer/mer-crea.png   módulo crea_ (detalhado) + view crea_evidencias
       _arq/mer/mer-pro.png    módulo pro_ (detalhado)
       _arq/mer/mer-mat.png    módulo mat_ (detalhado)
       _arq/mer/mer.pdf        os 5 diagramas acima, um por pagina, A2 paisagem

  Todo HTML intermediario e escrito num diretorio temporario (tempfile) e
  descartado; nada alem dos arquivos finais entra em `_arq/mer/`.

REPRODUTIBILIDADE
------------------
Quando o schema mudar, rode de novo -- o script le o banco ao vivo, nao
`_arq/estrutura.sql`. Se uma tabela/coluna/FK for renomeada ou criada, o
diagrama e a lista de "referencia sem FK" (SOFT_REF_RULES) devem ser
revisados a mao: a detecção de FK declarada é 100% automática, mas a lista de
padroes de referencia informal (RNP, registro CREA) e curada porque o banco
nao tem nenhum exemplo positivo (FK real) desses padroes para generalizar.
"""

import base64
import html
import json
import math
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path

# --------------------------------------------------------------------------
# 0. Caminhos e constantes
# --------------------------------------------------------------------------

REPO = Path(__file__).resolve().parents[1]
OUT_DIR = REPO / "_arq" / "mer"
FONTS_DIR = REPO / "public" / "assets" / "vendor" / "fontes"

DOCKER_CMD = [
    "docker", "compose", "exec", "-T", "mariadb", "sh", "-c",
    'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" prolink -e "{query}"',
]

# Tokens de public/assets/css/prolink.css (secao 1) -- fonte de verdade do
# design system. Nao duplicar valores "de cabeca": se o CSS mudar, atualize
# aqui tambem.
TOKENS = {
    "page": "#F2EEE7", "app": "#FBFAF6", "card": "#FFFFFF",
    "border": "#EDE8DE", "border2": "#F7F3EC",
    "ink": "#0E1726", "muted": "#5B6B82", "faint": "#8A9BB4",
    "navy": "#0B2A4A", "navy2": "#12315A",
    "blue": "#2E6BE6", "blue2": "#63A0F0", "blue100": "#EAF1FE",
    "seal": "#0E7C86", "seal_bg": "#E3F3F5", "seal_border": "#BFE4E8",
    "data": "#1B4079",
    "ok": "#1FA971", "warn": "#E8A317", "danger": "#E24A4A",
}

# Cor por módulo (adendo da Cami): laranja (--pl-accent) fica de fora do MER
# de propósito -- é a única ação preenchida da interface, um diagrama não tem
# acao. Verde-agua (--pl-seal) e reservado a verificacao no resto do produto;
# aqui a excecao autorizada e o módulo crea_ (cache verificado pela API
# oficial) -- registrado tambem em _arq/mer/README.md.
MODULE_COLOR = {
    "sis": TOKENS["navy"],
    "pro": TOKENS["data"],
    "crea": TOKENS["seal"],
    "mat": TOKENS["blue"],
}
MODULE_LABEL = {
    "sis": "SIS · identidade, privacidade e auditoria",
    "pro": "PRO · domínio Pro-Link",
    "crea": "CREA · cache da API oficial",
    "mat": "MAT · motor de compatibilização",
}
MODULE_TAG = {"sis": "SIS", "pro": "PRO", "crea": "CREA", "mat": "MAT"}

FONT_FILES = {
    "Inter-400": "Inter-400-latin.woff2",
    "Inter-500": "Inter-500-latin.woff2",
    "Inter-600": "Inter-600-latin.woff2",
    "Inter-700": "Inter-700-latin.woff2",
    "SpaceGrotesk-500": "SpaceGrotesk-500-latin.woff2",
    "SpaceGrotesk-600": "SpaceGrotesk-600-latin.woff2",
    "SpaceGrotesk-700": "SpaceGrotesk-700-latin.woff2",
}

# Pagina do PDF: A2 paisagem a 96dpi (confirmado empiricamente com
# LIMITACAO CONHECIDA DO PDF: no panorama, a altura que o layout calcula para o fragmento fica
# abaixo da altura renderizada, e a pagina corta o rodape do cartao da view e a legenda. O scale
# e calculado a partir da altura declarada, entao o conteudo excedente sai fora da pagina fisica
# e `overflow:visible` nao resolve. O `mer.png` do panorama esta completo e correto, e o edital
# (8.3.2b) aceita PDF, PNG ou .mwb: o PNG e a fonte para o panorama, o PDF para os modulos.
# Conserto de verdade: medir a altura renderizada no navegador antes de montar a pagina.
#
# `npx playwright pdf --paper-format A2` + `@page{size:landscape}` --
# ver decisão no relatório). O CLI do Playwright não expõe largura/altura
# customizada nem --landscape; forcar a orientacao via CSS e o unico jeito
# sem escrever um driver Node à parte.
PDF_PAGE_W = 2245
PDF_PAGE_H = 1587
PDF_PAGE_PAD = 56

ROW_H = 24
HEADER_H = 36
FOOTER_H = 20
PAD_V = 14
CARD_W_DETAIL = 340
CARD_W_STUB = 210
CARD_W_MINI = 258
STUB_ROWS = 1  # so a PK aparece no stub


# --------------------------------------------------------------------------
# 1. Extracao do banco (so leitura)
# --------------------------------------------------------------------------

def run_sql(query: str) -> str:
    q = query.replace('"', '\\"')
    cmd_str = DOCKER_CMD[-1].format(query=q)
    cmd = DOCKER_CMD[:-1] + [cmd_str]
    res = subprocess.run(cmd, cwd=REPO, capture_output=True, text=True, timeout=60)
    if res.returncode != 0:
        raise RuntimeError(f"Falha ao consultar o banco:\n{res.stderr}")
    return res.stdout


def parse_tsv(text: str):
    lines = [l for l in text.split("\n") if l != ""]
    if not lines:
        return []
    header = lines[0].split("\t")
    rows = []
    for line in lines[1:]:
        cells = line.split("\t")
        if len(cells) < len(header):
            cells = cells + [""] * (len(header) - len(cells))
        rows.append(dict(zip(header, cells)))
    return rows


def extract_schema():
    tables_raw = parse_tsv(run_sql(
        "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES "
        "WHERE TABLE_SCHEMA='prolink' ORDER BY TABLE_NAME;"
    ))
    columns_raw = parse_tsv(run_sql(
        "SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, "
        "COLUMN_KEY, COLUMN_DEFAULT, EXTRA, COLUMN_COMMENT FROM information_schema.COLUMNS "
        "WHERE TABLE_SCHEMA='prolink' ORDER BY TABLE_NAME, ORDINAL_POSITION;"
    ))
    fks_raw = parse_tsv(run_sql(
        "SELECT k.TABLE_NAME, k.COLUMN_NAME, k.CONSTRAINT_NAME, "
        "k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE "
        "FROM information_schema.KEY_COLUMN_USAGE k "
        "JOIN information_schema.REFERENTIAL_CONSTRAINTS r "
        "ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME "
        "WHERE k.TABLE_SCHEMA='prolink' AND k.REFERENCED_TABLE_NAME IS NOT NULL "
        "ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME;"
    ))
    idx_raw = parse_tsv(run_sql(
        "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME "
        "FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='prolink' "
        "ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;"
    ))

    tables = {}
    for t in tables_raw:
        name = t["TABLE_NAME"]
        tables[name] = {
            "name": name,
            "type": "view" if t["TABLE_TYPE"] == "VIEW" else "table",
            "module": name.split("_")[0],
            "columns": [],
            "pk": [],
            "unique_single": set(),
        }

    col_nullable = {}
    for c in columns_raw:
        t = c["TABLE_NAME"]
        if t not in tables:
            continue
        col = {
            "name": c["COLUMN_NAME"],
            "type": c["COLUMN_TYPE"],
            "nullable": c["IS_NULLABLE"] == "YES",
            "key": c["COLUMN_KEY"],
            "extra": c["EXTRA"],
        }
        tables[t]["columns"].append(col)
        col_nullable[(t, c["COLUMN_NAME"])] = col["nullable"]
        if c["COLUMN_KEY"] == "PRI":
            tables[t]["pk"].append(c["COLUMN_NAME"])

    # indices de coluna unica isolada (para achar 1:1 de verdade)
    idx_cols = {}  # (table, index_name) -> [col,...] em ordem
    idx_unique = {}  # (table, index_name) -> bool (NON_UNIQUE==0)
    for r in idx_raw:
        key = (r["TABLE_NAME"], r["INDEX_NAME"])
        idx_cols.setdefault(key, []).append(r["COLUMN_NAME"])
        idx_unique[key] = (r["NON_UNIQUE"] == "0")
    for (t, iname), cols in idx_cols.items():
        if iname == "PRIMARY":
            continue
        if idx_unique.get((t, iname)) and len(cols) == 1 and t in tables:
            tables[t]["unique_single"].add(cols[0])

    fks = []
    for f in fks_raw:
        t, c = f["TABLE_NAME"], f["COLUMN_NAME"]
        fks.append({
            "table": t,
            "column": c,
            "constraint": f["CONSTRAINT_NAME"],
            "ref_table": f["REFERENCED_TABLE_NAME"],
            "ref_column": f["REFERENCED_COLUMN_NAME"],
            "update_rule": f["UPDATE_RULE"],
            "delete_rule": f["DELETE_RULE"],
            "nullable": col_nullable.get((t, c), False),
            "unique": c in tables.get(t, {}).get("unique_single", set()),
        })

    return {"tables": tables, "fks": fks}


# --------------------------------------------------------------------------
# 2. Referencias por convencao de nome sem FK declarada ("soft refs")
#    Lista curada -- ver aviso no docstring do módulo.
# --------------------------------------------------------------------------

SOFT_REF_RULES = [
    {
        "pattern": re.compile(r"_usu_id$"),
        "ref_table": "sis_usuarios", "ref_column": "usu_id",
        "rationale": (
            "convencao `<prefixo>_usu_id -> sis_usuarios.usu_id` usada por outras "
            "12 colunas com FK declarada nesta base; esta é a única que quebra o "
            "padrao sem FK."
        ),
    },
    {
        "pattern": re.compile(r"_pro_rnp$"),
        "ref_table": "pro_profissionais", "ref_column": "prf_rnp",
        "rationale": (
            "RNP é chave natural da API (não PK interna); o módulo crea_ é cache "
            "reconstruivel e foi desacoplado de pro_ de propósito (comentario no "
            "topo do módulo, em _arq/estrutura.sql)."
        ),
    },
    {
        "pattern": re.compile(r"emp_registro_crea$"),
        "ref_table": "pro_empresas", "ref_column": "emp_registro_crea",
        "rationale": "mesmo motivo acima, para o registro CREA da empresa.",
    },
]


def detect_soft_refs(model):
    tables = model["tables"]
    declared = {(f["table"], f["column"]) for f in model["fks"]}
    found = []
    for tname, t in tables.items():
        if t["type"] != "table":
            continue
        for col in t["columns"]:
            cname = col["name"]
            if (tname, cname) in declared:
                continue
            for rule in SOFT_REF_RULES:
                if not rule["pattern"].search(cname):
                    continue
                if (tname, cname) == (rule["ref_table"], rule["ref_column"]):
                    continue  # nao contar a propria coluna-alvo como referencia a si
                if rule["ref_table"] not in tables:
                    continue
                found.append({
                    "table": tname, "column": cname,
                    "ref_table": rule["ref_table"], "ref_column": rule["ref_column"],
                    "rationale": rule["rationale"],
                })
    return found


def detect_missing_control_columns(model):
    """Toda tabela deveria ter _dt_registro, _log e _status (edital 8.6)."""
    missing = []
    for tname, t in model["tables"].items():
        if t["type"] != "table":
            continue
        names = {c["name"] for c in t["columns"]}
        prefix = tname.split("_")[-1][:3] if False else None  # nao usado
        has_dt = any(c["name"].endswith("_dt_registro") for c in t["columns"])
        has_log = any(c["name"].endswith("_log") for c in t["columns"])
        has_status = any(c["name"].endswith("_status") for c in t["columns"])
        if not (has_dt and has_log and has_status):
            missing.append({
                "table": tname, "has_dt": has_dt, "has_log": has_log, "has_status": has_status,
            })
    return missing


# --------------------------------------------------------------------------
# 3. Utilidades de tipo e geometria
# --------------------------------------------------------------------------

def abbrev_type(t: str) -> str:
    """Normaliza o COLUMN_TYPE do information_schema para exibicao nos
    cartoes: forma SQL valida, em maiusculas, preservando parenteses e o
    modificador UNSIGNED por extenso (BIGINT UNSIGNED, CHAR(32),
    DECIMAL(4,3), VARCHAR(45), TINYINT(1)...). Ate 17/09/2026 esta funcao
    cortava os parenteses e colava "u" no fim do nome do tipo (virava
    "bigintu", "char32", "dec4.3": nenhum tipo SQL valido) -- corrigido no
    achado da tarefa de 17/09/2026, junto com a remocao do alias "bool" pra
    tinyint(1), que tambem nao e a forma literal devolvida pelo banco."""
    t = t.strip()
    t = t.replace(" /* mariadb-5.3 */", "")
    t = re.sub(r"\s+unsigned\b", " UNSIGNED", t, flags=re.IGNORECASE)
    t = re.sub(r"\s+zerofill\b", " ZEROFILL", t, flags=re.IGNORECASE)
    m = re.match(r"^([a-zA-Z]+)(.*)$", t, flags=re.DOTALL)
    if m:
        t = m.group(1).upper() + m.group(2)
    return t


def boundary_point(box, tx, ty):
    cx, cy = box["x"] + box["w"] / 2, box["y"] + box["h"] / 2
    dx, dy = tx - cx, ty - cy
    if dx == 0 and dy == 0:
        return (cx, cy)
    hw, hh = box["w"] / 2, box["h"] / 2
    candidates = []
    if dx != 0:
        candidates.append(hw / abs(dx))
    if dy != 0:
        candidates.append(hh / abs(dy))
    s = min(candidates)
    return (cx + dx * s, cy + dy * s)


def marker_svg(kind, x, y, angle_deg, color):
    if kind == "one":
        local = (f'<line x1="5" y1="-7" x2="5" y2="7" stroke="{color}" stroke-width="1.7"/>'
                  f'<line x1="11" y1="-7" x2="11" y2="7" stroke="{color}" stroke-width="1.7"/>')
    elif kind == "zero_or_one":
        local = (f'<line x1="5" y1="-7" x2="5" y2="7" stroke="{color}" stroke-width="1.7"/>'
                  f'<circle cx="16" cy="0" r="5" fill="#FFFFFF" stroke="{color}" stroke-width="1.7"/>')
    elif kind == "zero_or_many":
        local = (f'<path d="M5,0 L17,-8 M5,0 L17,8" fill="none" stroke="{color}" stroke-width="1.7"/>'
                  f'<circle cx="24" cy="0" r="5" fill="#FFFFFF" stroke="{color}" stroke-width="1.7"/>')
    elif kind == "arrow":
        local = f'<path d="M2,-6 L12,0 L2,6" fill="none" stroke="{color}" stroke-width="1.7"/>'
    else:
        local = ""
    return f'<g transform="translate({x:.1f},{y:.1f}) rotate({angle_deg:.1f})">{local}</g>'


def connector_svg(box_a, box_b, color, end_a=None, end_b=None, dash=None,
                   bulge=24, top_arc_y=None, bulge_sign=1):
    ca = (box_a["x"] + box_a["w"] / 2, box_a["y"] + box_a["h"] / 2)
    cb = (box_b["x"] + box_b["w"] / 2, box_b["y"] + box_b["h"] / 2)
    pa = boundary_point(box_a, cb[0], cb[1])
    pb = boundary_point(box_b, ca[0], ca[1])

    if top_arc_y is not None:
        c1 = (pa[0], top_arc_y)
        c2 = (pb[0], top_arc_y)
    else:
        dx, dy = pb[0] - pa[0], pb[1] - pa[1]
        length = math.hypot(dx, dy) or 1
        px, py = -dy / length, dx / length
        b = bulge * bulge_sign
        c1 = (pa[0] + dx * 0.33 + px * b, pa[1] + dy * 0.33 + py * b)
        c2 = (pa[0] + dx * 0.67 + px * b, pa[1] + dy * 0.67 + py * b)

    ang_a = math.degrees(math.atan2(c1[1] - pa[1], c1[0] - pa[0]))
    ang_b = math.degrees(math.atan2(c2[1] - pb[1], c2[0] - pb[0]))

    dash_attr = ""
    if dash == "dashed":
        dash_attr = ' stroke-dasharray="8,6"'
    elif dash == "dotted":
        dash_attr = ' stroke-dasharray="2,4"'

    path = (f"M {pa[0]:.1f},{pa[1]:.1f} C {c1[0]:.1f},{c1[1]:.1f} "
            f"{c2[0]:.1f},{c2[1]:.1f} {pb[0]:.1f},{pb[1]:.1f}")
    svg = f'<path d="{path}" fill="none" stroke="{color}" stroke-width="1.6"{dash_attr}/>'
    if end_a:
        svg += marker_svg(end_a, pa[0], pa[1], ang_a, color)
    if end_b:
        svg += marker_svg(end_b, pb[0], pb[1], ang_b, color)
    return svg


def fk_cardinality_kinds(fk):
    """Retorna (kind_no_lado_pai, kind_no_lado_filho) em notacao pé-de-corvo."""
    parent_kind = "zero_or_one" if fk["nullable"] else "one"
    child_kind = "zero_or_one" if fk["unique"] else "zero_or_many"
    return parent_kind, child_kind


# --------------------------------------------------------------------------
# 4. Fontes embutidas + CSS base
# --------------------------------------------------------------------------

def font_face_css():
    faces = []
    specs = [
        ("Inter", 400, "Inter-400"), ("Inter", 500, "Inter-500"),
        ("Inter", 600, "Inter-600"), ("Inter", 700, "Inter-700"),
        ("Space Grotesk", 500, "SpaceGrotesk-500"),
        ("Space Grotesk", 600, "SpaceGrotesk-600"),
        ("Space Grotesk", 700, "SpaceGrotesk-700"),
    ]
    for family, weight, key in specs:
        fpath = FONTS_DIR / FONT_FILES[key]
        data = base64.b64encode(fpath.read_bytes()).decode("ascii")
        faces.append(
            f"@font-face{{font-family:'{family}';font-style:normal;font-weight:{weight};"
            f"src:url(data:font/woff2;base64,{data}) format('woff2');}}"
        )
    return "\n".join(faces)


BASE_CSS = """
* { box-sizing: border-box; }
html, body { margin:0; padding:0; }
body {
  background: var(--pl-page);
  color: var(--pl-ink);
  font-family: 'Inter', system-ui, sans-serif;
  -webkit-font-smoothing: antialiased;
}
.canvas { position:relative; }
.lane-bg { position:absolute; border-radius: 16px; }
.lane-title {
  position:absolute; font-family:'Space Grotesk', sans-serif; font-weight:700;
  font-size: 15px; letter-spacing:.04em; text-transform:uppercase;
}
.diagram-title {
  position:absolute; font-family:'Space Grotesk', sans-serif; font-weight:700;
  color: var(--pl-ink); font-size: 26px; letter-spacing:-.01em;
}
.diagram-subtitle {
  position:absolute; font-family:'Inter', sans-serif; font-weight:500;
  color: var(--pl-muted); font-size: 13px;
}
.card {
  position:absolute; background: var(--pl-card); border:1px solid var(--pl-border);
  border-top-width:4px; border-radius:12px; box-shadow:0 1px 2px rgba(14,23,38,.08);
  overflow:hidden;
}
.card.stub { background: var(--pl-app); box-shadow:none; }
.card.view { background: var(--pl-card); }
.card-head { display:flex; align-items:center; gap:8px; height:36px; padding:0 12px;
  border-bottom:1px solid var(--pl-border2); }
.tag { font-family:'Space Grotesk', sans-serif; font-weight:700; font-size:10px;
  letter-spacing:.06em; border:1px solid; border-radius:6px; padding:2px 6px; line-height:1.4; }
.title { font-family:'Space Grotesk', sans-serif; font-weight:600; font-size:13.5px;
  color: var(--pl-ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis; flex:1; }
.card-body { padding:6px 10px 4px; }
.row { display:flex; align-items:center; height:24px; gap:6px; font-size:12px; }
.badge { flex:0 0 26px; font-size:9px; font-weight:700; text-align:center; border-radius:4px;
  padding:2px 0; font-family:'Inter',sans-serif; }
.badge.pk { background: var(--pl-ink); color:#fff; }
.badge.fk { border:1.4px solid; background:#fff; }
.badge.uq { border:1.4px solid var(--pl-muted); color:var(--pl-muted); background:#fff; }
.colname { flex:1 1 auto; color: var(--pl-ink); overflow:hidden; text-overflow:ellipsis;
  white-space:nowrap; font-weight:500; }
.colname.muted { color: var(--pl-muted); font-weight:400; }
.coltype { flex:0 0 auto; color: var(--pl-faint); font-size:10.5px; white-space:nowrap; }
.fk-target { flex:0 0 auto; font-size:10px; white-space:nowrap; font-weight:600; }
.footer-row { color: var(--pl-faint); font-style:italic; font-size:10.5px; padding:3px 10px 8px; }
.footer-row.warn { color: var(--pl-warn); font-style:normal; font-weight:600; }
.legend { position:absolute; background: var(--pl-app); border:1px solid var(--pl-border);
  border-radius:14px; padding:14px 20px; display:flex; flex-wrap:wrap; gap:20px 28px;
  align-items:center; }
.legend-group { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.legend-item { display:flex; align-items:center; gap:6px; font-size:11.5px; color: var(--pl-muted); }
.legend-title { font-family:'Space Grotesk', sans-serif; font-weight:700; font-size:10.5px;
  color: var(--pl-ink); text-transform:uppercase; letter-spacing:.05em; margin-right:2px; }
.swatch { width:12px; height:12px; border-radius:4px; display:inline-block; }
"""


# --------------------------------------------------------------------------
# 5. Renderizacao de cartoes (entidade / view / stub)
# --------------------------------------------------------------------------

def module_of(table_name):
    return table_name.split("_")[0]


def card_rows_for_table(model, table, mini=False):
    """Monta as linhas visiveis de uma tabela: PK primeiro, depois FKs,
    depois (se nao for mini) as demais colunas 'relevantes', com o trio de
    controle (_dt_registro/_log/_status) resumido numa linha de rodape."""
    t = model["tables"][table]
    fks_by_col = {f["column"]: f for f in model["fks"] if f["table"] == table}
    pk = set(t["pk"])
    control_suffixes = ("_dt_registro", "_log", "_status")
    rows = []
    control_present = {"dt": False, "log": False, "status": False}

    ordered = [c for c in t["columns"] if c["name"] in pk]
    ordered += [c for c in t["columns"] if c["name"] in fks_by_col and c["name"] not in pk]
    if not mini:
        for c in t["columns"]:
            if c["name"] in pk or c["name"] in fks_by_col:
                continue
            if c["name"].endswith("_dt_registro"):
                control_present["dt"] = True
                continue
            if c["name"].endswith("_log"):
                control_present["log"] = True
                continue
            if c["name"].endswith("_status"):
                control_present["status"] = True
                continue
            ordered.append(c)

    for c in ordered:
        name = c["name"]
        is_pk = name in pk
        fk = fks_by_col.get(name)
        row = {"name": name, "muted": c["nullable"] and not is_pk}
        if is_pk:
            row["badge"], row["badge_cls"] = "PK", "pk"
            row["badge_style"] = ""
        elif fk:
            row["badge"], row["badge_cls"] = "FK", "fk"
            tgt_mod = module_of(fk["ref_table"])
            color = MODULE_COLOR.get(tgt_mod, TOKENS["muted"])
            row["badge_style"] = f"border-color:{color};color:{color};"
            row["fk_target"] = fk["ref_table"]
            row["fk_color"] = color
        elif name in t["unique_single"]:
            row["badge"], row["badge_cls"] = "UQ", "uq"
            row["badge_style"] = ""
        else:
            row["badge"], row["badge_cls"] = "", ""
            row["badge_style"] = ""
        row["type"] = abbrev_type(c["type"])
        rows.append(row)

    footer = None
    if not mini:
        parts = []
        if control_present["dt"]:
            parts.append("dt_registro")
        if control_present["log"]:
            parts.append("log")
        if control_present["status"]:
            parts.append("status")
        if len(parts) == 3:
            footer = (" · ".join(parts), False)
        elif parts:
            footer = (f"campos de controle incompletos: {', '.join(parts)} (ver README)", True)
        else:
            footer = ("sem _dt_registro / _log / _status (ver README)", True)
    return rows, footer


def render_card(box, title, module_key, rows, footer=None, dashed=False, stub=False,
                 mini=False, view=False):
    accent = MODULE_COLOR.get(module_key, TOKENS["muted"])
    classes = "card"
    if stub:
        classes += " stub"
    if view:
        classes += " view"
    border_style = "dashed" if dashed else "solid"
    tag = "VIEW" if view else MODULE_TAG.get(module_key, module_key.upper())

    row_html = []
    for r in rows:
        badge = (f'<span class="badge {r["badge_cls"]}" style="{r["badge_style"]}">{r["badge"]}</span>'
                  if r["badge"] else '<span class="badge"></span>')
        name_cls = "colname muted" if r.get("muted") else "colname"
        right = ""
        if not mini:
            right = f'<span class="coltype">{html.escape(r["type"])}</span>'
        elif r.get("fk_target"):
            right = f'<span class="fk-target" style="color:{r["fk_color"]}">→ {html.escape(r["fk_target"])}</span>'
        row_html.append(
            f'<div class="row">{badge}<span class="{name_cls}">{html.escape(r["name"])}</span>{right}</div>'
        )
    footer_html = ""
    if footer:
        text, is_warn = footer
        cls = "footer-row warn" if is_warn else "footer-row"
        footer_html = f'<div class="{cls}">{html.escape(text)}</div>'

    return f'''<div class="{classes}" style="left:{box['x']:.0f}px;top:{box['y']:.0f}px;
width:{box['w']:.0f}px;height:{box['h']:.0f}px;border-style:{border_style};border-top-color:{accent};">
  <div class="card-head"><span class="tag" style="color:{accent};border-color:{accent};">{tag}</span>
  <span class="title">{html.escape(title)}</span></div>
  <div class="card-body">{''.join(row_html)}</div>
  {footer_html}
</div>'''


def card_height(n_rows, has_footer=True):
    body_pad = 10  # .card-body padding top(6)+bottom(4)
    footer_block = 30 if has_footer else 0  # .footer-row padding + line height, com folga
    return HEADER_H + body_pad + n_rows * ROW_H + footer_block + 8


# --------------------------------------------------------------------------
# 6. Legenda
# --------------------------------------------------------------------------

def render_legend(box, show_soft=True, show_view=True, show_crowfoot=True):
    items_modules = "".join(
        f'<div class="legend-item"><span class="swatch" style="background:{MODULE_COLOR[m]}"></span>{html.escape(MODULE_LABEL[m])}</div>'
        for m in ("sis", "pro", "crea", "mat")
    )
    items_badges = (
        '<div class="legend-item"><span class="badge pk" style="width:22px;display:inline-block;">PK</span> chave primária</div>'
        '<div class="legend-item"><span class="badge fk" style="width:22px;display:inline-block;border-color:'
        + TOKENS["muted"] + ';color:' + TOKENS["muted"] + ';">FK</span> chave estrangeira (cor = módulo referenciado)</div>'
        '<div class="legend-item"><span class="badge uq" style="width:22px;display:inline-block;">UQ</span> única (não-chave)</div>'
    )
    lines = []
    line_w, line_h = 34, 16
    svg = (f'<svg width="{line_w}" height="{line_h}" viewBox="0 0 {line_w} {line_h}">'
           f'<line x1="0" y1="8" x2="{line_w}" y2="8" stroke="{TOKENS["muted"]}" stroke-width="1.6"/></svg>')
    fk_label = ("FK declarada (cardinalidade em pé-de-galinha)" if show_crowfoot else
                'FK declarada, só entre tabelas do mesmo módulo (seta = direção; '
                'entre módulos e cardinalidade: ver "→ tabela" no cartão e o diagrama do módulo)')
    lines.append(f'<div class="legend-item">{svg} {fk_label}</div>')
    if show_view:
        svg = (f'<svg width="{line_w}" height="{line_h}" viewBox="0 0 {line_w} {line_h}">'
               f'<line x1="0" y1="8" x2="{line_w}" y2="8" stroke="{TOKENS["seal"]}" stroke-width="1.6" stroke-dasharray="8,6"/></svg>')
        lines.append(f'<div class="legend-item">{svg} view (SELECT sobre as tabelas)</div>')
    if show_soft:
        svg = (f'<svg width="{line_w}" height="{line_h}" viewBox="0 0 {line_w} {line_h}">'
               f'<line x1="0" y1="8" x2="{line_w}" y2="8" stroke="{TOKENS["warn"]}" stroke-width="1.6" stroke-dasharray="2,4"/></svg>')
        soft_label = ("referência por nome, sem FK no banco (achado · ver relatório)" if show_crowfoot else
                      "referência por nome, sem FK no banco (achado · as outras 4, entre módulos, estão no README)")
        lines.append(f'<div class="legend-item">{svg} {soft_label}</div>')

    groups = [
        f'<div class="legend-group"><span class="legend-title">Módulos</span>{items_modules}</div>',
        f'<div class="legend-group"><span class="legend-title">Colunas</span>{items_badges}</div>',
        f'<div class="legend-group"><span class="legend-title">Linhas</span>{"".join(lines)}</div>',
    ]
    if show_crowfoot:
        samples = [("one", "exatamente um"), ("zero_or_one", "zero ou um"), ("zero_or_many", "zero ou muitos")]
        parts = []
        for kind, label in samples:
            w, h = 46, 20
            svg = f'<svg width="{w}" height="{h}" viewBox="0 0 {w} {h}">'
            svg += f'<line x1="0" y1="{h/2}" x2="{w}" y2="{h/2}" stroke="{TOKENS["ink"]}" stroke-width="1.5"/>'
            svg += marker_svg(kind, w - 4, h / 2, 180, TOKENS["ink"])
            svg += "</svg>"
            parts.append(f'<div class="legend-item">{svg} {label}</div>')
        groups.append(f'<div class="legend-group"><span class="legend-title">Cardinalidade</span>{"".join(parts)}</div>')

    return f'''<div class="legend" style="left:{box['x']:.0f}px;top:{box['y']:.0f}px;width:{box['w']:.0f}px;">
  {"".join(groups)}
</div>'''


LEGEND_H = 92


# --------------------------------------------------------------------------
# 7. Layout: panorama
# --------------------------------------------------------------------------

def topo_order(model, table_names):
    """Ordena as tabelas de um conjunto colocando quem-e-referenciado antes
    de quem-referencia (so olhando FKs internas ao proprio conjunto)."""
    names = list(table_names)
    edges = {n: set() for n in names}  # n -> depende de
    for f in model["fks"]:
        if f["table"] in edges and f["ref_table"] in edges and f["ref_table"] != f["table"]:
            edges[f["table"]].add(f["ref_table"])
    ordered, seen = [], set()

    def visit(n, stack):
        if n in seen or n in stack:
            return
        stack.add(n)
        for dep in sorted(edges[n]):
            visit(dep, stack)
        stack.discard(n)
        seen.add(n)
        ordered.append(n)

    for n in sorted(names):
        visit(n, set())
    return ordered


def pack_columns(items, n_cols, gap_y=26):
    """items: lista de dicts com 'h' (altura). Distribui em n_cols colunas
    pelo criterio guloso da coluna mais curta. Retorna lista de (col_idx, y)."""
    heights = [0.0] * n_cols
    placement = []
    for it in items:
        col = min(range(n_cols), key=lambda i: heights[i])
        y = heights[col]
        placement.append((col, y))
        heights[col] = y + it["h"] + gap_y
    return placement, heights


def build_panorama(model):
    tables = model["tables"]
    by_module = {"sis": [], "pro": [], "crea": [], "mat": []}
    view_name = None
    for name, t in tables.items():
        if t["type"] == "view":
            view_name = name
            continue
        by_module[t["module"]].append(name)

    LANE_ORDER = ["sis", "pro", "crea", "mat"]
    LANE_GAP = 90
    LANE_PAD = 22
    LANE_W = CARD_W_MINI + LANE_PAD * 2
    TOP = 128

    boxes = {}
    lane_x = {}
    x_cursor = 40
    lane_heights = {}
    for m in LANE_ORDER:
        lane_x[m] = x_cursor
        names = topo_order(model, by_module[m])
        y_cursor = TOP
        for name in names:
            rows, _ = card_rows_for_table(model, name, mini=True)
            h = card_height(len(rows), has_footer=False)
            boxes[name] = {"x": lane_x[m] + LANE_PAD, "y": y_cursor, "w": CARD_W_MINI, "h": h}
            y_cursor += h + 20
        lane_heights[m] = y_cursor
        x_cursor += LANE_W + LANE_GAP

    total_w = x_cursor - LANE_GAP + 40
    content_bottom = max(lane_heights.values())

    # view: colocada abaixo da fronteira crea/pro, dependencias calculadas
    view_deps = []
    if view_name:
        deps = sorted(_view_dependencies(model, view_name))
        rows = [{"name": d, "badge": "", "badge_cls": "", "badge_style": "", "muted": False, "type": ""} for d in deps]
        h = card_height(len(rows), has_footer=True)
        vx = lane_x["pro"] + LANE_PAD
        vy = content_bottom + 34
        boxes[view_name] = {"x": vx, "y": vy, "w": CARD_W_MINI, "h": h}
        view_deps = deps
        content_bottom = vy + h

    legend_y = content_bottom + 30
    total_h = legend_y + LEGEND_H + 30

    return {
        "boxes": boxes, "lane_x": lane_x, "lane_w": LANE_W, "lane_top": TOP,
        "lane_bottom": {m: lane_heights[m] for m in LANE_ORDER},
        "view_name": view_name, "view_deps": view_deps,
        "total_w": total_w, "total_h": total_h, "legend_y": legend_y,
        "by_module": by_module,
    }


def _view_dependencies(model, view_name):
    """Dependencias conhecidas da view crea_evidencias (checadas contra
    SHOW CREATE VIEW ao vivo -- ver relatorio). Mantido explicito porque
    MariaDB 10.11 nao expoe information_schema.VIEW_TABLE_USAGE."""
    if view_name == "crea_evidencias":
        return [
            "crea_arts", "crea_art_atividades", "crea_tos", "pro_profissionais",
            "crea_cat_arts", "crea_cats", "crea_quadro_tecnico", "pro_empresas",
        ]
    return []


def render_panorama_fragment(model):
    layout = build_panorama(model)
    boxes = layout["boxes"]
    parts = []

    parts.append(
        f'<div class="diagram-title" style="left:40px;top:24px;">MER · Pro-Link · panorama</div>'
        f'<div class="diagram-subtitle" style="left:40px;top:60px;">'
        f'28 tabelas + 1 view · chaves primárias e estrangeiras, agrupadas por módulo · '
        f'linhas só dentro do módulo (FK entre módulos: "→ tabela" no cartão) · '
        f'gerado a partir do banco `prolink` ao vivo, não de estrutura.sql</div>'
    )

    for m in ["sis", "pro", "crea", "mat"]:
        x = layout["lane_x"][m]
        parts.append(
            f'<div class="lane-bg" style="left:{x}px;top:{layout["lane_top"]-46}px;'
            f'width:{layout["lane_w"]}px;height:{layout["lane_bottom"][m]-layout["lane_top"]+70}px;'
            f'background:{_hex_to_rgba(MODULE_COLOR[m],0.045)};"></div>'
        )
        parts.append(
            f'<div class="lane-title" style="left:{x+22}px;top:{layout["lane_top"]-40}px;color:{MODULE_COLOR[m]};">'
            f'{html.escape(MODULE_LABEL[m])}</div>'
        )

    # Conectores -- só ENTRE tabelas do mesmo módulo (achado de 17/09/2026:
    # antes disso, toda FK e toda referencia por nome eram desenhadas aqui
    # tambem entre módulos diferentes, e a linha cruzava o panorama inteiro
    # sem acrescentar informacao que o proprio cartao ja nao desse -- toda
    # coluna FK mostra "→ tabela_alvo" na cor do módulo referenciado (ver
    # render_card/mini abaixo), entao a linha entre módulos so pagava custo
    # de cruzamento sem ganho de leitura. Dentro do mesmo módulo as linhas
    # sao curtas -- ficam. Cardinalidade (pé-de-galinha) tambem fica só nos
    # 4 diagramas de módulo: aqui e so seta de direcao, pra nao repetir um
    # marcador que fica ilegível quando varias linhas convergem no mesmo
    # cartao (ver README, "O que o panorama esconde de propósito").
    conn_svg = []
    for f in model["fks"]:
        if f["table"] not in boxes or f["ref_table"] not in boxes:
            continue
        if model["tables"][f["ref_table"]]["module"] != model["tables"][f["table"]]["module"]:
            continue
        bulge_sign = 1 if (hash(f["constraint"]) % 2 == 0) else -1
        conn_svg.append(connector_svg(
            boxes[f["ref_table"]], boxes[f["table"]], TOKENS["muted"],
            end_a=None, end_b="arrow", bulge=26, bulge_sign=bulge_sign,
        ))

    for sr in detect_soft_refs(model):
        if sr["table"] not in boxes or sr["ref_table"] not in boxes:
            continue
        if model["tables"][sr["ref_table"]]["module"] != model["tables"][sr["table"]]["module"]:
            continue
        conn_svg.append(connector_svg(
            boxes[sr["ref_table"]], boxes[sr["table"]], TOKENS["warn"],
            end_a=None, end_b="arrow", dash="dotted", bulge=34,
        ))

    # Dependencias da view crea_evidencias: todas as 8 cruzam crea_/pro_, e o
    # proprio cartao da view ja lista as 8 como linhas (mesmo motivo acima --
    # nao desenhar aqui). Detalhe completo, com as linhas, em mer-crea.png.

    parts.append(
        f'<svg class="edges" style="position:absolute;left:0;top:0;width:{layout["total_w"]}px;'
        f'height:{layout["total_h"]}px;pointer-events:none;">{"".join(conn_svg)}</svg>'
    )

    for name, box in boxes.items():
        t = model["tables"][name]
        if t["type"] == "view":
            rows = [{"name": d, "badge": "", "badge_cls": "", "badge_style": "", "muted": False, "type": ""}
                    for d in layout["view_deps"]]
            parts.append(render_card(box, name, "crea", rows, dashed=True, view=True, mini=True,
                                      footer=("as 8 dependências acima · detalhe com linhas em mer-crea.png", False)))
        else:
            rows, _ = card_rows_for_table(model, name, mini=True)
            parts.append(render_card(box, name, t["module"], rows, mini=True))

    parts.append(render_legend({"x": 40, "y": layout["legend_y"], "w": layout["total_w"] - 80},
                                show_crowfoot=False))

    return "".join(parts), layout["total_w"], layout["total_h"]


def _hex_to_rgba(hexcolor, alpha):
    h = hexcolor.lstrip("#")
    r, g, b = int(h[0:2], 16), int(h[2:4], 16), int(h[4:6], 16)
    return f"rgba({r},{g},{b},{alpha})"


# --------------------------------------------------------------------------
# 8. Layout: diagrama por módulo (detalhado)
# --------------------------------------------------------------------------

def module_external_targets(model, module):
    """(tabela_alvo, [fks que apontam pra ela]) para FKs que saem do módulo
    apontando pra fora, mais os soft-refs (marcados a parte)."""
    targets = {}
    for f in model["fks"]:
        if model["tables"][f["table"]]["module"] != module:
            continue
        if model["tables"][f["ref_table"]]["module"] == module:
            continue
        targets.setdefault(f["ref_table"], {"hard": [], "soft": []})["hard"].append(f)
    for sr in detect_soft_refs(model):
        if model["tables"][sr["table"]]["module"] != module:
            continue
        if model["tables"][sr["ref_table"]]["module"] == module:
            continue  # alvo já aparece como cartão completo neste mesmo diagrama
        targets.setdefault(sr["ref_table"], {"hard": [], "soft": []})["soft"].append(sr)
    return targets


def build_module_layout(model, module):
    own = [n for n, t in model["tables"].items() if t["module"] == module and t["type"] == "table"]
    own = topo_order(model, own)
    externals = module_external_targets(model, module)

    N_COLS = {"sis": 3, "pro": 4, "crea": 3, "mat": 2}[module]
    COL_W = CARD_W_DETAIL
    COL_GAP = 60
    ROW_GAP = 28
    LEFT = 50
    TOP_STUBS = 118
    boxes = {}

    stub_names = sorted(externals.keys())
    for i, tgt in enumerate(stub_names):
        col = i % N_COLS
        x = LEFT + col * (COL_W + COL_GAP)
        boxes[tgt] = {"x": x, "y": TOP_STUBS, "w": CARD_W_STUB, "h": card_height(1, has_footer=False), "stub": True}

    if stub_names:
        grid_top = TOP_STUBS + card_height(1, has_footer=False) + 50
    else:
        grid_top = 104  # sem fileira de stubs; só precisa liberar o subtítulo (2 linhas)
    items = []
    for name in own:
        rows, footer = card_rows_for_table(model, name, mini=False)
        h = card_height(len(rows), has_footer=bool(footer))
        items.append({"name": name, "h": h, "rows": rows, "footer": footer})

    placement, col_heights = pack_columns(items, N_COLS, gap_y=ROW_GAP)
    for it, (col, y) in zip(items, placement):
        x = LEFT + col * (COL_W + COL_GAP)
        boxes[it["name"]] = {
            "x": x, "y": grid_top + y, "w": COL_W, "h": it["h"], "stub": False,
            "rows": it["rows"], "footer": it["footer"],
        }

    content_bottom = grid_top + max(col_heights) if col_heights else grid_top
    total_w = LEFT + N_COLS * COL_W + (N_COLS - 1) * COL_GAP + 50
    legend_y = content_bottom + 26
    total_h = legend_y + LEGEND_H + 30

    return {
        "boxes": boxes, "own": own, "externals": externals, "total_w": total_w,
        "total_h": total_h, "legend_y": legend_y, "view_in_module": module == "crea",
    }


def render_module_fragment(model, module):
    layout = build_module_layout(model, module)
    boxes = layout["boxes"]
    parts = []
    accent = MODULE_COLOR[module]

    parts.append(
        f'<div class="diagram-title" style="left:40px;top:24px;color:{accent};">'
        f'MER · módulo {module}_ · {html.escape(MODULE_LABEL[module].split(" · ",1)[1])}</div>'
        f'<div class="diagram-subtitle" style="left:40px;top:62px;">'
        f'Todas as colunas relevantes de cada tabela · cartões tracejados = tabelas de outro '
        f'módulo, referenciadas a partir daqui (detalhe completo no diagrama do módulo delas)</div>'
    )

    conn_svg = []
    for f in model["fks"]:
        if model["tables"][f["table"]]["module"] != module:
            continue
        if f["table"] not in boxes or f["ref_table"] not in boxes:
            continue
        pk, ck = fk_cardinality_kinds(f)
        conn_svg.append(connector_svg(boxes[f["ref_table"]], boxes[f["table"]], TOKENS["muted"],
                                       end_a=pk, end_b=ck, bulge=22))

    soft_here = [sr for sr in detect_soft_refs(model) if model["tables"][sr["table"]]["module"] == module]
    for sr in soft_here:
        if sr["table"] in boxes and sr["ref_table"] in boxes:
            conn_svg.append(connector_svg(boxes[sr["ref_table"]], boxes[sr["table"]], TOKENS["warn"],
                                           end_b="arrow", dash="dotted", bulge=26))

    view_name = None
    view_deps = []
    if layout["view_in_module"]:
        for n, t in model["tables"].items():
            if t["type"] == "view":
                view_name = n
                view_deps = _view_dependencies(model, n)
        if view_name:
            rows = [{"name": d, "badge": "", "badge_cls": "", "badge_style": "", "muted": False, "type": ""}
                    for d in view_deps]
            h = card_height(len(rows), has_footer=False)
            vx = layout["total_w"] - CARD_W_STUB - 50
            vy = 118
            boxes[view_name] = {"x": vx, "y": vy, "w": CARD_W_STUB, "h": h, "stub": False, "rows": rows, "footer": None}
            for dep in view_deps:
                if dep in boxes:
                    conn_svg.append(connector_svg(boxes[dep], boxes[view_name], TOKENS["seal"], dash="dashed", bulge=18))

    parts.append(
        f'<svg class="edges" style="position:absolute;left:0;top:0;width:{layout["total_w"]}px;'
        f'height:{layout["total_h"]}px;pointer-events:none;">{"".join(conn_svg)}</svg>'
    )

    for tgt, box in [(k, v) for k, v in boxes.items() if v.get("stub") and k != view_name]:
        tmod = model["tables"][tgt]["module"]
        rows, _ = card_rows_for_table(model, tgt, mini=True)
        rows = rows[:1]
        parts.append(render_card(box, tgt, tmod, rows, dashed=True, stub=True, mini=True))

    for name in layout["own"]:
        box = boxes[name]
        parts.append(render_card(box, name, module, box["rows"], footer=box["footer"]))

    if view_name and view_name in boxes:
        vbox = boxes[view_name]
        parts.append(render_card(vbox, view_name, module, vbox["rows"], dashed=True, view=True, mini=True))

    show_soft = len(soft_here) > 0
    show_view = view_name is not None
    parts.append(render_legend({"x": 40, "y": layout["legend_y"], "w": layout["total_w"] - 80},
                                show_soft=show_soft, show_view=show_view))

    return "".join(parts), layout["total_w"], layout["total_h"]


# --------------------------------------------------------------------------
# 9. Montagem de pagina HTML
# --------------------------------------------------------------------------

def page_html(body_fragment, width, height, extra_style=""):
    return f"""<!doctype html><html><head><meta charset="utf-8">
<style>
:root {{
  --pl-page:{TOKENS['page']}; --pl-app:{TOKENS['app']}; --pl-card:{TOKENS['card']};
  --pl-border:{TOKENS['border']}; --pl-border2:{TOKENS['border2']};
  --pl-ink:{TOKENS['ink']}; --pl-muted:{TOKENS['muted']}; --pl-faint:{TOKENS['faint']};
  --pl-navy:{TOKENS['navy']}; --pl-navy2:{TOKENS['navy2']};
  --pl-blue:{TOKENS['blue']}; --pl-blue2:{TOKENS['blue2']}; --pl-blue100:{TOKENS['blue100']};
  --pl-seal:{TOKENS['seal']}; --pl-seal-bg:{TOKENS['seal_bg']}; --pl-seal-border:{TOKENS['seal_border']};
  --pl-data:{TOKENS['data']}; --pl-ok:{TOKENS['ok']}; --pl-warn:{TOKENS['warn']}; --pl-danger:{TOKENS['danger']};
}}
{font_face_css()}
{BASE_CSS}
{extra_style}
</style></head>
<body>
<div class="canvas" style="width:{width}px;height:{height}px;">{body_fragment}</div>
</body></html>"""


def combined_pdf_html(fragments):
    sections = []
    for frag, w, h in fragments:
        scale = min((PDF_PAGE_W - 2 * PDF_PAGE_PAD) / w, (PDF_PAGE_H - 2 * PDF_PAGE_PAD) / h, 1.0)
        sw, sh = w * scale, h * scale
        left = (PDF_PAGE_W - sw) / 2
        top = (PDF_PAGE_H - sh) / 2
        sections.append(
            f'<section style="position:relative;width:{PDF_PAGE_W}px;height:{PDF_PAGE_H}px;'
            f'background:var(--pl-page);break-after:page;overflow:hidden;">'
            f'<div class="canvas" style="position:absolute;left:{left:.0f}px;top:{top:.0f}px;'
            f'width:{w}px;height:{h}px;transform:scale({scale:.4f});transform-origin:top left;">'
            f'{frag}</div></section>'
        )
    body = "".join(sections)
    extra = "@page { size: landscape; margin:0; } section:last-child{break-after:auto;}"
    return page_html(body, PDF_PAGE_W, PDF_PAGE_H, extra_style=extra)


# --------------------------------------------------------------------------
# 10. Exportacao via Playwright CLI
# --------------------------------------------------------------------------

def export_png(html_path: Path, out_path: Path, viewport):
    cmd = [
        "npx", "playwright", "screenshot", "--full-page",
        "--viewport-size", f"{viewport[0]},{viewport[1]}",
        f"file://{html_path}", str(out_path),
    ]
    res = subprocess.run(cmd, cwd=REPO, capture_output=True, text=True, timeout=120)
    if res.returncode != 0:
        raise RuntimeError(f"Falha ao exportar PNG {out_path.name}:\n{res.stderr}")


def export_pdf(html_path: Path, out_path: Path):
    """Exporta o PDF com as cores de fundo.

    O `npx playwright pdf` nao expoe `printBackground`, e sem ela o Chromium imprime so texto,
    bordas e linhas: o PDF saia em fundo branco, sem o tingimento que distingue um modulo do
    outro. `scripts/mer-pdf.mjs` e um driver minimo que usa a API, com o Playwright que a suite
    de ponta a ponta ja instalou em `e2e/node_modules`.
    """
    driver = REPO / "scripts" / "mer-pdf.mjs"
    cmd = ["node", str(driver), str(html_path), str(out_path)]
    res = subprocess.run(cmd, cwd=REPO, capture_output=True, text=True, timeout=180)
    if res.returncode != 0:
        raise RuntimeError(f"Falha ao exportar PDF:\n{res.stderr}")


# --------------------------------------------------------------------------
# 11. Main
# --------------------------------------------------------------------------

def main():
    print("Lendo estrutura real do banco `prolink` via information_schema...")
    model = extract_schema()
    n_tables = sum(1 for t in model["tables"].values() if t["type"] == "table")
    n_views = sum(1 for t in model["tables"].values() if t["type"] == "view")
    print(f"  {n_tables} tabelas, {n_views} view(s), {len(model['fks'])} FKs declaradas.")

    soft = detect_soft_refs(model)
    missing_ctrl = detect_missing_control_columns(model)
    print(f"  {len(soft)} referencia(s) por nome sem FK declarada (soft refs).")
    print(f"  {len(missing_ctrl)} tabela(s) com trio de controle incompleto.")

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    tmpdir = Path(tempfile.mkdtemp(prefix="prolink-mer-"))
    print(f"Diretorio temporario: {tmpdir}")

    fragments = {}

    print("Desenhando panorama...")
    frag, w, h = render_panorama_fragment(model)
    fragments["mer"] = (frag, w, h)
    html_path = tmpdir / "mer.html"
    html_path.write_text(page_html(frag, w, h), encoding="utf-8")
    export_png(html_path, OUT_DIR / "mer.png", (w, h))

    for module in ["sis", "crea", "pro", "mat"]:
        print(f"Desenhando módulo {module}...")
        frag, w, h = render_module_fragment(model, module)
        fragments[f"mer-{module}"] = (frag, w, h)
        html_path = tmpdir / f"mer-{module}.html"
        html_path.write_text(page_html(frag, w, h), encoding="utf-8")
        export_png(html_path, OUT_DIR / f"mer-{module}.png", (w, h))

    print("Montando PDF combinado (A2 paisagem, 5 paginas)...")
    order = ["mer", "mer-sis", "mer-crea", "mer-pro", "mer-mat"]
    pdf_html = combined_pdf_html([fragments[k] for k in order])
    pdf_html_path = tmpdir / "mer-combined.html"
    pdf_html_path.write_text(pdf_html, encoding="utf-8")
    export_pdf(pdf_html_path, OUT_DIR / "mer.pdf")

    summary = {
        "tables": n_tables, "views": n_views, "fks": len(model["fks"]),
        "soft_refs": soft, "missing_control_columns": missing_ctrl,
    }
    (tmpdir / "summary.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(summary, indent=2, ensure_ascii=False))
    print(f"\nArquivos finais em {OUT_DIR}")
    print(f"HTML/temp preservados em {tmpdir} (pode apagar).")


if __name__ == "__main__":
    main()
