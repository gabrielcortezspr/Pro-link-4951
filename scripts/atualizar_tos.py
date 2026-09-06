"""
Baixa a Tabela TOS completa da API e regrava data/csv/tos.csv com as colunas
de nível derivadas do código.

A cópia versionada em data/csv/tos.csv tem 1000 linhas (limite do painel oficial)
e não inclui os grupos 3 a 9. Rode este script uma vez para ter a tabela inteira.

    export PROLINK_API_TOKEN=...
    python scripts/atualizar_tos.py
"""

import csv
import os
import pathlib
import sys
import time

import requests

BASE = "https://desafio-prolink.crea-am.org.br/api/v1/"
DESTINO = pathlib.Path(__file__).resolve().parent.parent / "data" / "csv" / "tos.csv"
LIMIT = 200


def niveis(codigo):
    """TOS_1.1.2.1 -> [1, 1, 2, 1]. Nunca ordene o código como texto."""
    return [int(p) for p in codigo.removeprefix("TOS_").split(".")]


def baixar(token):
    headers = {"Authorization": f"Bearer {token}"}
    linhas, pagina, total_paginas = [], 1, 1

    while pagina <= total_paginas:
        r = requests.get(
            BASE,
            params={"p": "tos", "page": pagina, "limit": LIMIT},
            headers=headers,
            timeout=30,
        )
        r.raise_for_status()
        corpo = r.json()
        total_paginas = corpo.get("total_paginas", 1)
        linhas.extend(corpo.get("data", []))
        print(f"página {pagina}/{total_paginas}: {len(corpo.get('data', []))} linhas")
        pagina += 1
        time.sleep(0.5)  # toda chamada é registrada pela organização

    return linhas


def gravar(linhas):
    saida = []
    for t in linhas:
        cod = t["tos_codigo"]
        n = niveis(cod)
        saida.append(
            [
                cod,
                t.get("tos_grupo", ""),
                t.get("tos_subgrupo", ""),
                t.get("tos_obra_servico", ""),
                t.get("tos_complementar") or "",
                n[0],
                n[1] if len(n) > 1 else "",
                n[2] if len(n) > 2 else "",
                n[3] if len(n) > 3 else "",
                len(n),
            ]
        )

    saida.sort(key=lambda r: (r[5], r[6] or 0, r[7] or 0, r[8] or 0))

    with open(DESTINO, "w", encoding="utf-8", newline="") as f:
        w = csv.writer(f)
        w.writerow(
            [
                "codigo", "grupo", "subgrupo", "obra_servico", "complementar",
                "nivel1", "nivel2", "nivel3", "nivel4", "profundidade",
            ]
        )
        w.writerows(saida)

    grupos = sorted({r[5] for r in saida})
    print(f"\n{len(saida)} linhas gravadas em {DESTINO}")
    print(f"grupos presentes: {grupos}")


if __name__ == "__main__":
    token = os.environ.get("PROLINK_API_TOKEN")
    if not token:
        sys.exit("defina PROLINK_API_TOKEN antes de rodar")
    gravar(baixar(token))
