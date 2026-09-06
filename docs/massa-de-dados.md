# Massa de Dados

Todos os identificadores da competição, exportados do Explorador de Dados oficial e guardados em `data/`. São dados **fictícios**, gerados pela organização para simular o ecossistema Confea/CREA. Não têm validade legal e não devem sair do escopo do desafio.

Use estes valores em todo teste: são eles que fazem a API responder.

## Arquivos

| Arquivo | Registros |
|---|---|
| `data/csv/profissionais.csv` | 100 |
| `data/csv/empresas.csv` | 100 |
| `data/csv/arts.csv` | 290 |
| `data/csv/cats.csv` | 100 |
| `data/csv/modalidades.csv` | 25 |
| `data/csv/tos.csv` | 2000 |

Ao importar, force o tipo string nas colunas de identificador:

```python
import pandas as pd

prof = pd.read_csv("data/csv/profissionais.csv", dtype=str)
emp  = pd.read_csv("data/csv/empresas.csv", dtype=str)
tos  = pd.read_csv("data/csv/tos.csv", dtype={"codigo": str})
```

---

## Profissionais

O CPF cresce em passos irregulares a partir de `12312300109`. O RNP é sequencial em `0412340011`, `0412340020`, `0412340038`, com incremento de 8 ou 9, típico de numeração com dígito verificador. O registro CREA é aleatório de 5 dígitos.

| CPF | Nome | RNP | Registro CREA |
|---|---|---|---|
| `12312300109` | ANA CLARA COSTA | `0412340011` | 61657 |
| `12312300290` | JOÃO MIGUEL SANTOS | `0412340020` | 27876 |
| `12312300370` | MARIA EDUARDA LIMA | `0412340038` | 70991 |
| `12312302233` | GABRIEL FARIAS | `0412340224` | 71234 |
| `12312310090` | MÁRCIO BITTENCOURT | `0412341000` | 81559 |

---

## Empresas

CNPJ sequencial de `00123001000123` a `00123100000105`. A massa é **temática por faixa**, o que dá cenários de teste prontos: uma demanda elétrica deveria rankear a faixa 21 a 30 acima da faixa 31 a 40, e o motor precisa conseguir mostrar por quê.

| Faixa | Área |
|---|---|
| 1 a 10 | Construção civil e edificações |
| 11 a 20 | Ambiental, agronomia, florestal |
| 21 a 30 | Elétrica, mecânica, industrial |
| 31 a 40 | Topografia, geodesia, agrimensura |
| 41 a 50 | Segurança do trabalho, consultoria, projetos |
| 51 a 60 | Construtoras regionais (Tapajós, Baré, Tucumã, Samaúma) |
| 61 a 70 | Saneamento, climatização, energia, pavimentação |
| 71 a 80 | Agronomia e meio ambiente |
| 81 a 90 | Infraestrutura, redes, sistemas prediais |
| 91 a 100 | Software, telecom, naval, perícias |

A faixa é o par de dígitos em `001230NN0001XX`.

| CNPJ | Razão Social | Registro CREA |
|---|---|---|
| `00123001000123` | AMAZÔNIA CONSTRUÇÕES E ENGENHARIA LTDA | 61859 |
| `00123021000104` | ELETRONORTE INSTALAÇÕES INDUSTRIAIS LTDA | 66897 |
| `00123031000195` | GEOMAPEAR TOPOGRAFIA E GEODESIA LTDA | 75605 |
| `00123092000100` | GÊNESIS ENGENHARIA DE SOFTWARES E AUTOMAÇÃO S.A. | 82940 |
| `00123100000105` | OMEGA CONSULTORIA, PERÍCIAS E AVALIAÇÕES LTDA | 70575 |

---

## ARTs

Numeração sequencial de `AM20269999001` a `AM20269999290`. Todas com `art_tipo` igual a `OBRA`.

O contratante tem só 5 valores possíveis: João Silva LTDA, Prefeitura Municipal, Indústria ABC S.A., Governo do Estado, Condomínio Residencial.

O objeto também tem só 5: Reforma Estrutural, Manutenção Preventiva, Construção de Edifício, Projeto Elétrico e Execução, Laudo Técnico de Instalação.

**Isso define a arquitetura do motor.** Com 290 ARTs distribuídas em 5 textos genéricos, o campo `art_objeto` não distingue ninguém de ninguém. Um matching baseado no texto da ART daria empate geral. A informação discriminante está nas atividades TOS vinculadas a cada ART, que é onde o motor precisa olhar.

O mesmo vale para `aat_descricao`, a descrição livre de cada atividade: veio
`"Execução de obra/serviço"` em todas as atividades observadas. Nenhum campo de texto livre desta
massa discrimina candidato. Só o `tos_codigo` discrimina.

### Os vínculos ART → TOS são aleatórios

Verificado em 06/09/2026 contra a API. As atividades TOS de cada ART **não** têm relação com o
`art_objeto`, com a modalidade do profissional nem com o ramo da empresa:

| Onde | O que a massa diz | O que o acervo tem |
|---|---|---|
| ANA CLARA COSTA | modalidade Engenharia Florestal, RT de construtora | têxtil, hidrocarbonetos, aeroespacial, agronomia, química |
| ART "Reforma Estrutural" | construção civil | `TOS_25.2.1`, indústria têxtil |
| ELETRONORTE INSTALAÇÕES INDUSTRIAIS | faixa 21–30, elétrica | 5 atividades, nenhuma de eletrotécnica |
| GÊNESIS ENGENHARIA DE SOFTWARES | faixa 91–100, software | 5 atividades, nenhuma de computação |

A faixa temática de CNPJ é temática **apenas na razão social**. O acervo por trás dela foi sorteado.

Duas consequências. Primeira: os cenários de demonstração que assumiam coerência temática não
funcionam, e `matching.md` foi corrigido. Segunda, mais importante: o roteiro da demonstração
precisa ser escrito **depois** de montar o índice de evidência, escolhendo demandas a partir dos
códigos TOS que os candidatos cadastrados realmente têm. Escolher o cenário olhando o dado, não
antes dele.

---

## CATs

100 certidões, de `999001/2026` a `999100/2026`. Todas com emissão em `2026-01-15` e validade em `2026-12-31`.

Duas consequências: hoje todas estão vigentes, e todas têm a mesma recência. Qualquer peso de ranking baseado em "CAT vigente" ou "CAT mais recente" empata todos os candidatos nesta massa. O critério continua valendo como regra de negócio e vale implementar, mas quem realmente separa candidatos é **quais ARTs cada CAT agrupa**.

---

## Modalidades

Tabela fechada de 25. Vale embutir como seed no banco.

| ID | Nome | ID | Nome |
|---|---|---|---|
| 1 | Engenharia Civil | 14 | Engenharia de Alimentos |
| 2 | Engenharia Elétrica | 15 | Engenharia de Petróleo |
| 3 | Engenharia Mecânica | 16 | Engenharia de Materiais |
| 4 | Agronomia | 17 | Engenharia Metalúrgica |
| 5 | Engenharia Florestal | 18 | Engenharia Naval |
| 6 | Engenharia Química | 19 | Engenharia Aeronáutica |
| 7 | Engenharia de Minas | 20 | Engenharia Agrícola |
| 8 | Engenharia de Produção | 21 | Engenharia de Pesca |
| 9 | Engenharia de Segurança do Trabalho | 22 | Geologia |
| 10 | Engenharia Ambiental | 23 | Geografia |
| 11 | Engenharia de Computação | 24 | Meteorologia |
| 12 | Engenharia de Telecomunicações | 25 | Engenharia de Controle e Automação |
| 13 | Engenharia Eletrônica | | |

---

## Tabela TOS

**2000 registros, 46 grupos** (1 a 46, sem lacunas), baixados da API em 06/09/2026 por
`scripts/atualizar_tos.py`. O arquivo já vem com as colunas `nivel1` a `nivel4` e `profundidade`
derivadas do código, e ordenado numericamente pela hierarquia. A anatomia do código está em
`modelo-de-dados.md`.

Profundidade: 1136 códigos com quatro níveis, 864 com três. Nenhum com cinco — a lógica de
prefixo do motor cobre a tabela inteira.

O Explorador de Dados exporta só 1000 linhas e ordena alfabeticamente; como `TOS_10` vem antes de
`TOS_2` na ordem de texto, o corte deixava de fora metade da tabela e 29 grupos, incluindo
Têxtil (25), Hidrocarbonetos (30), Meio Ambiente, Geografia e Agronomia (39). Todos aparecem na
massa de ARTs, então a cópia antiga de 1000 linhas era insuficiente de fato, não só na teoria.

Amostra:

| Código | Grupo | Subgrupo | Obra/Serviço | Complementar |
|---|---|---|---|---|
| `TOS_1.1.1.1` | Construção Civil | Edificações | de edificação | de alvenaria |
| `TOS_1.1.2.1` | Construção Civil | Edificações | de reforma de edificação | de alvenaria |
| `TOS_1.1.6` | Construção Civil | Edificações | de muro | |
| `TOS_2.9.2.3` | Estruturas | Fundações | de fundações profundas | em estacas de concreto moldadas in loco |
| `TOS_11.10.1.4` | Eletrotécnica | Instalações Elétricas | de instalações elétricas em baixa tensão | para fins industriais |
| `TOS_14.3.2` | Computação | Programação | de desenvolvimento de software | |

### Grupos presentes

Os 46 grupos, com a contagem de linhas de cada um, saem direto do arquivo:

```bash
cut -d, -f6 data/csv/tos.csv | tail -n +2 | sort -n | uniq -c
```

Os nomes de grupo têm formatação inconsistente ("Química" ao lado de "Atividades na Área da Engenharia Nuclear"). Não use o nome como chave; use o número extraído do código.

---

## O que ainda não está aqui

O painel exporta as entidades, mas não os vínculos entre elas. Não sabemos, sem chamar a API:

- qual ART pertence a qual profissional
- quais ARTs cada CAT agrupa
- quais atividades TOS estão em cada ART
- quem compõe o quadro técnico de cada empresa
- quais modalidades cada profissional tem

Esses vínculos são justamente o insumo do motor — mas **não** os obtenha varrendo a massa.
O item 10.4 do edital proíbe coleta automatizada de dados, e o item 8.4 veda criar base própria
que simule os dados da API. Percorrer os 200 identificadores para montar um espelho local seria,
na melhor das hipóteses, discutível na banca.

O padrão correto, que já era a regra do `CLAUDE.md`, é o mesmo que a aplicação usa em produção:
buscar quando o candidato se cadastra na plataforma, gravar o resultado no índice local como
cache da resposta real, e atualizar sob demanda. Para a demonstração, cadastre à mão um punhado
de profissionais e empresas pelo próprio fluxo de cadastro — o que também exercita a RF01 e a
RF02 de ponta a ponta.

As fixtures em `fixtures/` cobrem um profissional e três empresas, o suficiente para desenvolver
e testar os parsers sem tocar na API.
