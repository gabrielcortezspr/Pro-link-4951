# A API do Desafio

Como falar com a API oficial. Os endpoints em si estão em `endpoints.md`.

## Base e roteamento

```
https://desafio-prolink.crea-am.org.br/api/v1/
```

O roteamento é por query string, não por path. O recurso vai no parâmetro `p`, que pode conter barras. Os filtros vêm depois com `&`:

```
?p=profissionais&cpf=12312300109
?p=profissionais/0412340011/arts&page=1&limit=20
?p=empresas/61859/cao
```

## Autenticação

Toda requisição precisa do header:

```
Authorization: Bearer <TOKEN_DA_EQUIPE>
```

O token da equipe 49/51 fica em `PROLINK_API_TOKEN`, no `.env`, fora do controle de versão. Ele pode ser copiado no topo da página oficial da documentação.

```python
import os, requests

BASE = "https://desafio-prolink.crea-am.org.br/api/v1/"
HEADERS = {"Authorization": f"Bearer {os.environ['PROLINK_API_TOKEN']}"}

r = requests.get(BASE, params={"p": "profissionais", "cpf": "12312300109"}, headers=HEADERS)
r.raise_for_status()
print(r.json())
```

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=profissionais&cpf=12312300109"
```

## Formato dos identificadores

| Campo | Formato | Exemplo |
|---|---|---|
| `pro_cpf` | 11 dígitos | `12312300109` |
| `pro_rnp` | 10 dígitos, zero à esquerda | `0412340011` |
| `pro_registro_crea` | 5 dígitos | `61657` |
| `emp_cnpj` | 14 dígitos, zeros à esquerda | `00123001000123` |
| `emp_registro_crea` | 5 dígitos | `61859` |
| `art_numero` | `AM` + 11 dígitos | `AM20269999001` |
| `cat_numero` | 6 dígitos + `/` + ano | `999001/2026` |
| `tos_codigo` | `TOS_` + hierarquia | `TOS_1.1.2.1` |
| `mod_id` | inteiro de 1 a 25 | `1` |

Três consequências práticas:

1. **Trate tudo como string.** `pro_rnp` e `emp_cnpj` perdem o zero inicial se virarem inteiro, e a busca passa a retornar 404.
2. **URL-encode o número da CAT.** A barra precisa virar `%2F`: `cat_numero=999001%2F2026`.
3. **Registro CREA de profissional e de empresa têm o mesmo formato.** Não dá pra descobrir o tipo pelo número, só pelo endpoint. Guarde o tipo junto do identificador.

## Texto e datas

Nomes e descrições vêm com acento e caixa mista (`JOÃO MIGUEL SANTOS`, `AMAZÔNIA CONSTRUÇÕES E ENGENHARIA LTDA`, `Engenharia Civil`). Normalize antes de comparar:

```python
import unicodedata

def norm(s):
    s = unicodedata.normalize("NFD", s)
    s = "".join(c for c in s if unicodedata.category(c) != "Mn")
    return s.casefold().strip()
```

Datas vêm como string, sem fuso, em dois formatos: `YYYY-MM-DD HH:MM:SS` (`emp_dt_registro`) e
`YYYY-MM-DD` (`cat_dt_emissao`, `cat_dt_validade`, `qut_dt_inicio`, `qut_dt_fim`). Campos de data
nulos vêm como `null`, nunca string vazia — vale para `qut_dt_fim` e `tos_complementar`.

## Paginação

Endpoints paginados aceitam `?page=N&limit=M` e devolvem sempre o mesmo envelope:

```json
{
  "pagina_atual": 1,
  "por_pagina": 20,
  "total_registros": 4,
  "total_paginas": 1,
  "data": [ ... ]
}
```

Os demais devolvem um array direto, ou um objeto no caso do CAO. Quem pagina o quê está na tabela de `endpoints.md`.

## Erros

Erro vem como objeto com a chave `error`:

```json
{ "error": "Profissional não encontrado para o RNP informado." }
```

Dois casos que parecem iguais mas não são:

| Situação | Status | Corpo | Significa |
|---|---|---|---|
| Busca de lista sem resultado | `200` | `[]` | A chave é válida mas não achou nada |
| Identificador inexistente em sub-recurso | `404` | `{"error": "..."}` | O RNP ou registro não existe na base |

Isso importa na validação de documentos: um `200 []` em `?p=arts&rnp=...&art_numero=...` significa "essa ART não pertence a esse profissional", que é uma reprovação legítima. Um `404` significa que o próprio profissional não existe. A interface deve tratar os dois de forma diferente.

Confirmado em 06/09/2026 contra a API real: `?p=arts&rnp=0412340011&art_numero=AM20269999290`
devolve `200 []` (a ART existe, mas não é dessa profissional) e `?p=profissionais/9999999999/arts`
devolve `404 {"error": "Profissional não encontrado para o RNP informado."}`. A interface de
validação da RF03 pode confiar nessa distinção.

Códigos de autenticação e limite de taxa ainda não foram observados — 24 chamadas em sequência,
com 0,5 s de intervalo, passaram sem `429`. Trate `401`, `403` e `429` de forma defensiva, com
backoff, mas não assuma que o limite é generoso.

## Logs

A organização registra toda chamada feita pela equipe, incluindo parâmetros e trechos das respostas, e o histórico fica visível no botão "Ver Logs da API" da página oficial.

Como a API só busca por chave exata e não tem endpoint de listagem geral, não existe motivo
legítimo pra varredura. Além disso o **item 10.4 do edital proíbe coleta automatizada de dados**
e o **item 8.4 veda criar base própria que simule os dados da API**. O padrão certo é: buscar
quando o candidato se cadastra na plataforma, guardar o resultado no índice local como cache da
resposta real, e atualizar sob demanda.

Durante o desenvolvimento, trabalhe sobre `fixtures/`, que já tem uma resposta real de cada
endpoint (ver a tabela em `endpoints.md`), e sobre a massa em `data/csv/`. Não é preciso chamar a
API para escrever parser nenhum.

## Ferramentas da organização

O painel oficial tem um **API Playground** (botão "Testar Endpoint na Prática" em cada card) e exemplos prontos em cURL, PHP, JavaScript e Python. O **Explorador de Dados** (`api/index.php?list=...`) lista a massa por categoria; foi de lá que saiu o conteúdo de `data/`.

Dúvidas técnicas, erros da API e questões de regra de negócio vão no Fórum Oficial do painel da equipe, em tópicos globais ou privativos.
