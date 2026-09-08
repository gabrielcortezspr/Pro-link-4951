# Endpoints

Todos são `GET` e todos exigem o header de autorização. Base e regras gerais em `api.md`.

**Os blocos JSON deste arquivo são respostas reais**, capturadas em 06/09/2026 e gravadas em
`fixtures/`. Onde o exemplo mostra um valor, é o valor que a API devolveu.

## Tabela geral

| Endpoint | Entrada | Devolve | Pagina? | Fixture |
|---|---|---|---|---|
| `profissionais` | `cpf` | cadastro + modalidades | não | `profissional_cpf.json` |
| `profissionais/{rnp}/arts` | RNP | ARTs com atividades TOS | sim (20) | `profissional_arts.json` |
| ↳ mesma chamada com `limit=2` | RNP | as 4 ARTs em 2 páginas | — | `profissional_arts_limite2_p1.json`, `_p2.json` |
| `profissionais/{rnp}/cats` | RNP | CATs, sem as ARTs | sim (20) | `profissional_cats.json` |
| `empresas` | `cnpj` | cadastro | não | `empresa_cnpj.json` |
| `empresas/{registro}/quadro-tecnico` | registro CREA | profissionais + função + datas | não | `empresa_quadro_tecnico.json` |
| `empresas/{registro}/cao` | registro CREA | árvore completa da empresa | não | `empresa_cao.json` |
| `arts` | `rnp` + `art_numero` | ART, sem atividades | não | `art_validacao.json` |
| `arts/{numero}/atividades` | número da ART | atividades TOS | não | `art_atividades.json` |
| ↳ número inexistente | — | `404 {"error": ...}` | — | `erro_art_inexistente.json` |
| `cats` | `rnp` + `cat_numero` | CAT com ARTs e atividades | não | `cat_validacao.json` |
| `tos` | `search` | dicionário TOS | sim (50, máx 200) | `tos_amostra.json` |

---

## Profissionais

### `?p=profissionais&cpf=`

Busca exata por CPF, só dígitos. CPF que não existe devolve `200 []`.

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=profissionais&cpf=12312300109"
```

```json
[
  {
    "pro_nome": "ANA CLARA COSTA",
    "pro_rnp": "0412340011",
    "pro_registro_crea": "61657",
    "pro_status": "A",
    "modalidades": [
      { "mod_id": 5, "mod_codigo": "FLO", "mod_nome": "Engenharia Florestal" }
    ]
  }
]
```

O CPF não volta na resposta. Guarde o `pro_rnp`: é ele que abre os dois endpoints seguintes.

`pro_status` chega como `"A"`. É o único lugar da API onde a situação do profissional aparece,
e a busca é por CPF — ou seja, **sincronizar status (RF02) exige ter guardado o CPF**. Os endpoints
por RNP não devolvem status.

`mod_codigo` é a sigla de três letras da modalidade (`"FLO"`), que acompanha o `mod_id` numérico.

### `?p=profissionais/{pro_rnp}/arts`

ARTs do profissional, com o escopo de cada uma em códigos TOS. Aceita `page` e `limit` (padrão 1 e 20).
RNP inexistente devolve `404`.

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=profissionais/0412340011/arts&page=1&limit=20"
```

```json
{
  "pagina_atual": 1,
  "por_pagina": 20,
  "total_registros": 4,
  "total_paginas": 1,
  "data": [
    {
      "art_numero": "AM20269999001",
      "art_tipo": "OBRA",
      "art_forma_registro": "INICIAL",
      "art_contratante_nome": "João Silva LTDA",
      "art_objeto": "Reforma Estrutural",
      "art_local_uf": "AM",
      "art_local_municipio": "Manaus",
      "art_situacao": "REGISTRADA",
      "atividades": [
        {
          "tos_codigo": "TOS_25.2.1",
          "tos_descricao": "Atividades na Área da Engenharia Têxtil - Planejamento e Projeto na Indústria Têxtil de indústria têxtil",
          "tos_grupo": "Atividades na Área da Engenharia Têxtil",
          "tos_subgrupo": "Planejamento e Projeto na Indústria Têxtil",
          "tos_obra_servico": "de indústria têxtil",
          "tos_complementar": null,
          "aat_descricao": "Execução de obra/serviço"
        }
      ]
    }
  ]
}
```

Este é o endpoint mais importante pro motor: é dele que sai a evidência de um profissional.
É também o **único** que traz `art_local_uf` e `art_local_municipio` — o CAO não traz.

**A paginação foi confirmada em 08/09/2026** com `&limit=2`: página 1 traz `AM20269999001` e
`AM20269999101`, página 2 traz `AM20269999102` e `AM20269999103`, e as duas repetem
`total_registros: 4` e `total_paginas: 2`. Ou seja, `pagina_atual` e `total_paginas` são
confiáveis para encerrar o laço, e `data` vazio não é a única parada. Na mesma data, a captura de
06/09 do endpoint de CPF foi reconferida contra a API e voltou idêntica.

### `?p=profissionais/{pro_rnp}/cats`

CATs emitidas pra ele. Mesmos parâmetros de paginação.

```json
{
  "pagina_atual": 1,
  "por_pagina": 20,
  "total_registros": 1,
  "total_paginas": 1,
  "data": [
    {
      "cat_numero": "999001/2026",
      "cat_tipo": "INICIAL",
      "cat_dt_emissao": "2026-01-15",
      "cat_dt_validade": "2026-12-31",
      "cat_finalidade": "Licitação"
    }
  ]
}
```

Não traz as ARTs que a CAT agrupa. Pra isso, chame `?p=cats` com o número.

---

## Empresas

### `?p=empresas&cnpj=`

Busca exata por CNPJ, só dígitos, com os zeros à esquerda.

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=empresas&cnpj=00123001000123"
```

```json
[
  {
    "emp_cnpj": "00123001000123",
    "emp_razao_social": "AMAZÔNIA CONSTRUÇÕES E ENGENHARIA LTDA",
    "emp_nome_fantasia": "AMAZÔNIA",
    "emp_registro_crea": "61859",
    "emp_dt_registro": "2026-08-12 14:38:08"
  }
]
```

Não existe campo de status da empresa, ao contrário do profissional.

### `?p=empresas/{emp_registro_crea}/quadro-tecnico`

Profissionais vinculados à empresa. Sem parâmetros.

```json
[
  {
    "qut_tipo": "R",
    "qut_funcao": "Responsável Técnico",
    "qut_dt_inicio": "2020-01-01",
    "qut_dt_fim": null,
    "pro_nome": "ANA CLARA COSTA",
    "pro_rnp": "0412340011",
    "pro_registro_crea": "61657"
  }
]
```

**`qut_dt_fim` existe e vem `null` quando o vínculo está ativo.** Dá para saber pela API se um
vínculo foi encerrado, e a empresa não herda acervo de vínculo encerrado.

**A regra só pode ser binária, e não temporal.** "Herda a ART registrada enquanto o vínculo
estava vigente" exigiria comparar `qut_dt_fim` com a data de registro da ART, e **nenhum endpoint
da API devolve data de ART** — nem esta lista, nem o CAO, nem `?p=arts`. Os campos de ART são
`art_numero`, `art_tipo`, `art_forma_registro`, `art_contratante_nome`, `art_objeto`,
`art_local_uf`, `art_local_municipio` e `art_situacao`. Sem data não há o que comparar. Duas
versões anteriores deste documento erraram aqui, uma em cada direção; o registro está na D19.

Note o prefixo: os campos são `qut_*`, não `eqt_*`. E `qut_dt_inicio` é `date` (`2020-01-01`),
não `datetime`.

### `?p=empresas/{emp_registro_crea}/cao`

Endpoint dinâmico: monta a Certidão de Acervo Operacional na hora, cruzando empresa, quadro
técnico, ARTs desses profissionais e as atividades TOS de cada ART.

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=empresas/61859/cao"
```

```json
{
  "emp_cnpj": "00123001000123",
  "emp_razao_social": "AMAZÔNIA CONSTRUÇÕES E ENGENHARIA LTDA",
  "emp_nome_fantasia": "AMAZÔNIA",
  "emp_registro_crea": "61859",
  "quadro_tecnico": [
    {
      "pro_nome": "ANA CLARA COSTA",
      "pro_rnp": "0412340011",
      "pro_registro_crea": "61657",
      "qut_funcao": "Responsável Técnico",
      "arts": [
        {
          "art_numero": "AM20269999001",
          "art_tipo": "OBRA",
          "art_objeto": "Reforma Estrutural",
          "art_situacao": "REGISTRADA",
          "atividades": [
            {
              "tos_codigo": "TOS_25.2.1",
              "tos_descricao": "Atividades na Área da Engenharia Têxtil - ...",
              "tos_grupo": "Atividades na Área da Engenharia Têxtil",
              "tos_subgrupo": "Planejamento e Projeto na Indústria Têxtil",
              "tos_obra_servico": "de indústria têxtil",
              "tos_complementar": null,
              "aat_descricao": "Execução de obra/serviço"
            }
          ]
        }
      ]
    }
  ]
}
```

**Atenção ao formato.** O objeto é plano — os campos da empresa ficam na raiz, não dentro de uma
chave `empresa`. E o aninhamento é `quadro_tecnico` → `profissional` → `arts` → `atividades`,
ou seja, profissional contém ARTs. Não existe chave `cao_arts`, e não existe objeto `profissional`
dentro de cada ART. Uma versão anterior deste documento descrevia a estrutura invertida.

Uma chamada devolve a árvore inteira da empresa e economiza dezenas de requisições. O que o CAO
**não** traz, e o `quadro-tecnico` traz: `qut_tipo`, `qut_dt_inicio` e `qut_dt_fim`. O que ele não
traz e o endpoint de ARTs do profissional traz: `art_forma_registro`, `art_contratante_nome`,
`art_local_uf` e `art_local_municipio`.

---

## Validação de documentos

Estes existem pra confirmar que uma ART ou CAT informada por alguém realmente existe e pertence
àquela pessoa. Array vazio significa que a combinação não confere.

### `?p=arts&rnp=&art_numero=`

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=arts&rnp=0412340011&art_numero=AM20269999001"
```

```json
[
  {
    "pro_nome": "ANA CLARA COSTA",
    "pro_rnp": "0412340011",
    "art_numero": "AM20269999001",
    "art_tipo": "OBRA",
    "art_forma_registro": "INICIAL",
    "art_contratante_nome": "João Silva LTDA",
    "art_objeto": "Reforma Estrutural",
    "art_situacao": "REGISTRADA"
  }
]
```

Não traz as atividades nem o local. Pra as atividades, use o endpoint abaixo.

### `?p=arts/{art_numero}/atividades`

```json
[
  {
    "tos_codigo": "TOS_25.2.1",
    "tos_descricao": "Atividades na Área da Engenharia Têxtil - Planejamento e Projeto na Indústria Têxtil de indústria têxtil",
    "tos_grupo": "Atividades na Área da Engenharia Têxtil",
    "tos_subgrupo": "Planejamento e Projeto na Indústria Têxtil",
    "tos_obra_servico": "de indústria têxtil",
    "tos_complementar": null,
    "aat_descricao": "Execução de obra/serviço"
  }
]
```

Este endpoint não pede RNP: qualquer número de ART válido devolve as atividades. Para o fluxo de
validação da RF03, chame `?p=arts&rnp=&art_numero=` **primeiro**, para confirmar a titularidade,
e só depois busque as atividades.

Número de ART inexistente devolve **`404`**, não `200 []` — observado em 08/09/2026:

```json
{ "error": "ART não encontrada para o número informado." }
```

Faz sentido com a regra geral: aqui o número está no caminho do recurso, como o RNP em
`profissionais/{rnp}/arts`, e recurso inexistente é 404. O `200 []` fica para as buscas por
filtro (`?p=arts&rnp=&art_numero=`), onde a chave é válida e a combinação é que não casa.

### `?p=cats&rnp=&cat_numero=`

Diferente do endpoint de lista de CATs, este traz as ARTs agrupadas pela certidão, cada uma com
suas atividades. É a forma de saber o que uma CAT cobre.

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=cats&rnp=0412340011&cat_numero=999001%2F2026"
```

```json
[
  {
    "pro_nome": "ANA CLARA COSTA",
    "pro_rnp": "0412340011",
    "cat_numero": "999001/2026",
    "cat_tipo": "INICIAL",
    "cat_dt_emissao": "2026-01-15",
    "cat_dt_validade": "2026-12-31",
    "cat_finalidade": "Licitação",
    "arts": [
      {
        "art_numero": "AM20269999001",
        "art_tipo": "OBRA",
        "art_contratante_nome": "João Silva LTDA",
        "art_objeto": "Reforma Estrutural",
        "art_situacao": "REGISTRADA",
        "atividades": [ { "tos_codigo": "TOS_25.2.1", "...": "..." } ]
      }
    ]
  }
]
```

Lembre do `%2F` na barra. Na massa, a CAT `999001/2026` agrupa as 4 ARTs da profissional.

---

## Tabela TOS

### `?p=tos`

Dicionário de obras e serviços. Aceita `search` (busca textual nos campos de grupo e obra/serviço),
`page` e `limit` (padrão 50, máximo 200).

```bash
curl -H "Authorization: Bearer $PROLINK_API_TOKEN" \
  "https://desafio-prolink.crea-am.org.br/api/v1/?p=tos&search=edificação&page=1&limit=200"
```

```json
{
  "pagina_atual": 1,
  "por_pagina": 1,
  "total_registros": 2000,
  "total_paginas": 2000,
  "data": [
    {
      "tos_codigo": "TOS_1.1.1.1",
      "tos_grupo": "Construção Civil",
      "tos_subgrupo": "Edificações",
      "tos_obra_servico": "de edificação",
      "tos_complementar": "de alvenaria"
    }
  ]
}
```

**A tabela tem 2000 registros e 46 grupos** — o dobro do que o Explorador de Dados exporta.
`data/csv/tos.csv` já está com a tabela completa, baixada por `scripts/atualizar_tos.py`.
Ela não muda durante o desafio; não precisa baixar de novo.

Note que o endpoint `tos` **não** devolve `tos_descricao`, mas os blocos `atividades` dentro de
ART, CAT e CAO devolvem. Se precisar do texto pronto para exibição a partir da tabela, monte-o
concatenando grupo, subgrupo e obra/serviço.

Dica de busca: os textos de obra/serviço começam com preposição ("de reforma de edificação"),
então termo isolado funciona melhor que frase.

---

## Campos confirmados em 06/09/2026

Todos os campos que estavam pendentes foram observados com valor real:

| Campo | Valor observado | Observação |
|---|---|---|
| `pro_status` | `"A"` | só na busca por CPF |
| `mod_codigo` | `"FLO"` | sigla de 3 letras, junto do `mod_id` |
| `art_situacao` | `"REGISTRADA"` | |
| `art_forma_registro` | `"INICIAL"` | |
| `art_local_uf` / `art_local_municipio` | `"AM"` / `"Manaus"` | só em `profissionais/{rnp}/arts` |
| `cat_tipo` / `cat_finalidade` | `"INICIAL"` / `"Licitação"` | |
| `tos_complementar` vazio | `null` | nunca string vazia; 864 dos 2000 códigos |
| `tos_descricao` | string concatenada | não documentado antes; ausente em `?p=tos` |
| `emp_dt_registro` | `"2026-08-12 14:38:08"` | |
| `qut_dt_fim` | `null` | existe; `null` = vínculo ativo |
