# Modelo de Dados

Como as entidades da API se ligam. Descreve o banco por trás da API, não o nosso: as respostas já chegam desnormalizadas, com as atividades aninhadas dentro da ART.

## Entidades

**`api_empresa`**: pessoa jurídica. PK `emp_cnpj`. Traz `emp_razao_social`, `emp_nome_fantasia`, `emp_registro_crea` e `emp_dt_registro`.

**`api_profissional`**: pessoa física. PK `pro_cpf`, mas o CPF é só entrada: nunca volta na resposta. O identificador de saída é o `pro_rnp`. Traz também `pro_nome`, `pro_registro_crea` e `pro_status`.

**`api_modalidade`**: modalidade de formação. PK `mod_codigo`, com `mod_nome`. A API expõe os três: `mod_id` (1 a 25), `mod_codigo` (sigla de três letras, `"FLO"`) e `mod_nome`.

**`api_art`**: Anotação de Responsabilidade Técnica. PK `art_numero`. Campos: `art_tipo`, `art_forma_registro`, `art_contratante_nome`, `art_objeto`, `art_local_uf`, `art_local_municipio`, `art_situacao`, e FK pro profissional.

**`api_cat`**: Certidão de Acervo Técnico. PK `cat_numero`. Campos: `cat_tipo`, `cat_dt_emissao`, `cat_dt_validade`, `cat_finalidade`, e FK pro profissional.

**`api_tos`**: Tabela de Obras e Serviços. PK `tos_codigo`, com `tos_grupo`, `tos_subgrupo`, `tos_obra_servico` e `tos_complementar`.

## Ligações

**`api_quadro_tecnico`**: empresa para profissional, com tipo, função e datas de início e fim. Na resposta da API os campos são `qut_tipo` (`"R"`), `qut_funcao` (`"Responsável Técnico"`), `qut_dt_inicio` (date) e `qut_dt_fim` (`null` quando o vínculo está ativo). **A data de fim é exposta** — dá para saber se um vínculo foi encerrado, e a empresa não herda acervo de vínculo encerrado. A regra é binária: filtrar por data de registro da ART seria impossível, porque a API não devolve data de ART em endpoint nenhum (D19).

**`api_prof_modalidade`**: profissional para modalidade, N para N.

**`api_art_cat`**: CAT para ART, N para N. Uma CAT agrupa várias ARTs, e uma ART pode entrar em mais de uma CAT.

**`api_art_atividade`**: ART para TOS, N para N, com `aat_descricao`: o texto livre que o profissional escreveu para aquela atividade, diferente da descrição oficial da TOS.

## Entidade virtual: `api_cao`

A Certidão de Acervo Operacional não é tabela. É o objeto que o endpoint `empresas/{registro}/cao` monta em tempo real, relacionando-se 1 para 1 com a empresa e reunindo quadro técnico, ARTs e atividades numa árvore só.

A árvore é plana na raiz e aninhada por profissional:

```
{ emp_cnpj, emp_razao_social, emp_nome_fantasia, emp_registro_crea,
  quadro_tecnico: [ { pro_nome, pro_rnp, pro_registro_crea, qut_funcao,
                      arts: [ { art_numero, art_tipo, art_objeto, art_situacao,
                                atividades: [ ... ] } ] } ] }
```

O profissional contém as ARTs, e não o contrário. O CAO omite `qut_tipo`, `qut_dt_inicio`,
`qut_dt_fim`, `art_forma_registro`, `art_contratante_nome` e o local da ART — para esses,
é preciso chamar `quadro-tecnico` e `profissionais/{rnp}/arts`.

## Diagrama

```
api_empresa      1 ─── 1  api_cao (virtual)
api_empresa      1 ─── N  api_quadro_tecnico  N ─── 1  api_profissional
api_profissional 1 ─── N  api_prof_modalidade N ─── 1  api_modalidade
api_profissional 1 ─── N  api_art
api_profissional 1 ─── N  api_cat
api_cat          1 ─── N  api_art_cat         N ─── 1  api_art
api_art          1 ─── N  api_art_atividade   N ─── 1  api_tos
```

Em palavras: a empresa possui um quadro técnico; o profissional pertence a esse quadro, possui modalidades, registra ARTs e emite CATs; a CAT agrupa ARTs; a ART se detalha em atividades; e a TOS define cada atividade.

## A cadeia de evidência

É a leitura que interessa ao projeto:

```
Empresa ──quadro técnico──▶ Profissional ──registra──▶ ART ──atividades──▶ TOS
                                          ──emite────▶ CAT ──agrupa────▶ ART
```

Tudo converge no `tos_codigo`. Por isso ele é o eixo do motor de busca.

## Anatomia do código TOS

O código não é um rótulo arbitrário: ele **é** a hierarquia.

```
TOS_<grupo>.<subgrupo>.<obra_servico>[.<complementar>]
```

`TOS_1.1.2.1` significa grupo 1 (Construção Civil), subgrupo 1 (Edificações), obra/serviço 2 (de reforma de edificação), complementar 1 (de alvenaria).

Códigos com três níveis não têm complementar (`TOS_1.1.6`, "de muro"). Códigos com quatro têm.

Consequências:

**Similaridade sai de graça.** Contar quantos componentes iniciais dois códigos compartilham já dá uma medida de proximidade técnica, sem NLP e sem tabela de sinônimos. É a base do ranking descrito em `matching.md`.

**Nunca ordene como texto.** Alfabeticamente `TOS_10.1.1` vem antes de `TOS_2.1.1`. Faça split em `.` e compare como inteiros:

```python
def niveis(codigo):
    return [int(p) for p in codigo.removeprefix("TOS_").split(".")]

sorted(codigos, key=niveis)
```

**Guarde os níveis em colunas separadas.** Uma tabela com `grupo`, `subgrupo`, `obra_servico` e `complementar` como inteiros indexados resolve busca por prefixo com query, sem varrer registro por registro. O arquivo `data/csv/tos.csv` já vem com essas colunas prontas.

## Chaves que usamos

| Entidade | Chave | Onde obter |
|---|---|---|
| Profissional | `pro_rnp` | `?p=profissionais&cpf=` |
| Empresa | `emp_registro_crea` | `?p=empresas&cnpj=` |
| ART | `art_numero` | qualquer endpoint de ART |
| CAT | `cat_numero` | qualquer endpoint de CAT |
| Atividade | `tos_codigo` | `?p=tos` ou dentro de `atividades` |
| Modalidade | `mod_id` | dentro de `modalidades` |

Os `*_id` internos do modelo (`pro_id`, `emp_id`, `art_id`, `tos_id`) não aparecem nas respostas e não devem ser usados como chave do nosso lado.
