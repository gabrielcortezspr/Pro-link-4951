# Motor de Compatibilização

O núcleo do produto: dada uma demanda técnica, identificar os profissionais e empresas com
capacidade **comprovada** de atendê-la, e mostrar a prova documental de cada correspondência.

## O princípio

A organização define a TOS como o elo entre necessidade e capacidade:

```
Necessidade → Atividade TOS → Capacidade técnica → Profissional / Empresa
```

Nossos três compromissos em cima disso:

**Evidência, não autodeclaração.** Nada que o usuário digita entra no score sem ser validado
contra os endpoints de ART e CAT.

**Sem pontuação oculta.** Toda correspondência vem acompanhada das ARTs e CATs que a
justificaram, visíveis ao demandante (edital, Anexo VI: "apresenta critérios explicáveis de
compatibilização").

**Sem ranking.** Ver a seção seguinte — é imposição do edital, não escolha de design.

## A restrição que define a arquitetura: item 10.1

> O CREA Pro-Link não realizará contratação automática, intermediação financeira, garantia de
> preço, certificação de qualidade, recomendação institucional, **ranking de profissionais** ou
> reserva de mercado.
>
> 10.2. A eventual correspondência entre perfil e demanda será apenas **indicativa**.

Ordenar candidatos por score é ranking, mesmo que a gente chame de outra coisa. Então o score
não ordena. Ele **filtra**:

```
score multidimensional  →  decide QUEM ENTRA no pool (limiar)
semente da sessão       →  decide EM QUE ORDEM o pool aparece
explicação por critério →  mostra POR QUE cada um entrou
```

O candidato que entrou com score 0.9 e o que entrou com 0.6 aparecem em ordem sorteada, sem
indicação de posição, sem estrelas, sem "melhor match". A empresa vê *que* cada um é compatível
e *por quê*, e decide sozinha — que é exatamente o que o item 10.2 exige.

A semente é gerada por sessão e gravada no log, junto com `empresa_id`, data, `pool_ids` e o
limiar usado. Qualquer sessão passada é reproduzível pelo administrador. Isso atende de uma vez
o item 10.1 (sem ranking), o 12.3 (critérios explicáveis, viés declarado e supervisão humana) e
o requisito de auditoria do 8.5.

**Também vedado pelo item 12.2:** qualquer critério de correspondência ligado a raça, cor, sexo,
gênero, deficiência, idade, religião, origem ou condição social. O motor só olha capacidade
técnica comprovada e requisitos declarados da demanda. Nenhum desses campos deve sequer existir
no modelo de dados do candidato.

## Etapa 1: da necessidade ao código TOS

A demanda chega em linguagem natural ("preciso reformar a fachada de um prédio de quatro andares")
e precisa virar um conjunto de códigos `D = {tos_codigo...}`.

Três abordagens, da mais barata à mais elaborada:

1. **Seleção manual em árvore.** O demandante navega grupo → subgrupo → obra/serviço →
   complementar sobre as 2000 linhas de `data/csv/tos.csv`. Precisa, barata, e já suficiente pra
   demonstrar o fluxo inteiro.
2. **Busca textual local.** Sobre a mesma tabela, com normalização de acento. Os textos começam
   com preposição, então termo isolado funciona melhor que frase.
3. **Classificação assistida.** LLM ou embeddings mapeiam o texto livre pros códigos, com
   confirmação humana antes de virar demanda. Se for por esse caminho, o item 12.3 exige declarar
   o uso de IA, documentar os critérios e manter supervisão humana — a confirmação humana
   obrigatória já resolve isso, mas precisa estar escrita.

Vale permitir peso por código: atividade principal versus secundária.

## Etapa 2: do candidato à capacidade

Pra cada candidato, montar o conjunto `C = {tos_codigo...}` do que ele comprovou.

**Profissional**, via `pro_rnp`: `?p=profissionais/{rnp}/arts` devolve as ARTs já com as
atividades aninhadas, mais o local de cada uma. `?p=profissionais/{rnp}/cats` mais
`?p=cats&rnp=&cat_numero=` diz quais dessas ARTs estão certificadas.

**Empresa**, via `emp_registro_crea`: `?p=empresas/{registro}/cao` devolve a árvore inteira numa
chamada, com o quadro técnico e as ARTs de cada profissional. Complementar com
`?p=empresas/{registro}/quadro-tecnico`, que traz `qut_dt_inicio` e `qut_dt_fim`.

**Regra de herança do acervo da empresa:** a empresa herda a ART de um profissional cujo vínculo
está vigente hoje. `qut_dt_fim` não nulo encerra o vínculo, e o CAO não filtra isso sozinho — o
filtro é nosso.

É binária de propósito, e não por simplificação: "ART registrada durante a vigência" precisaria
de uma data de ART que a API não devolve em endpoint nenhum. Quem tem data são as CATs
(`cat_dt_emissao`, `cat_dt_validade`) e o próprio vínculo (`qut_dt_inicio`, `qut_dt_fim`) —
nenhuma delas diz quando a ART foi registrada. Ver D19.

## Etapa 3: similaridade por prefixo

O coração do motor, e ele é simples porque o código TOS já carrega a hierarquia.

```
TOS_1.1.2.1  →  [1, 1, 2, 1]
TOS_1.1.2.5  →  [1, 1, 2, 5]
```

A afinidade entre um código da demanda e um do acervo é o número de componentes iniciais iguais:

| Iguais | Significado | Exemplo contra `TOS_1.1.2.1` | Peso |
|---|---|---|---|
| 4 | mesma atividade, mesmo material | `TOS_1.1.2.1` | 1.00 |
| 3 | mesma obra/serviço, material diferente | `TOS_1.1.2.5` | 0.75 |
| 2 | mesmo subgrupo, atividade diferente | `TOS_1.1.1.1` | 0.40 |
| 1 | só o grupo em comum | `TOS_1.4.3` | 0.15 |
| 0 | áreas distintas | `TOS_11.10.1.4` | 0.00 |

Os pesos são ponto de partida. Precisam ser calibrados e, sobretudo, **declarados na interface**
e configuráveis pelo administrador (item 12.3, supervisão humana).

```python
def afinidade(a, b, pesos=(0.0, 0.15, 0.40, 0.75, 1.00)):
    na, nb = niveis(a), niveis(b)
    iguais = 0
    for x, y in zip(na, nb):
        if x != y:
            break
        iguais += 1
    if iguais == len(na) == len(nb):
        return pesos[4]
    return pesos[min(iguais, 3)]
```

Detalhe: `TOS_1.1.6` tem três componentes e `TOS_1.1.1.1` tem quatro. Compare até o menor
comprimento, e trate coincidência total como caso separado. Nenhum código da tabela tem cinco
níveis, então três e quatro são os únicos casos.

## Etapa 4: as seis dimensões do item 3.2

> A compatibilização deverá considerar, entre outros elementos, área de atuação, localização,
> competências, experiências declaradas e informações verificáveis relacionadas a ARTs e/ou CATs.

Cada dimensão vira um score de 0 a 1. O score composto é a média ponderada, e o candidato entra
no pool se passar do limiar.

| Dimensão | Origem | Verificado? | Peso sugerido |
|---|---|---|---|
| Competência via ART/CAT | afinidade TOS sobre o acervo | sim, API | 0.40 |
| Área de atuação | modalidade do profissional × grupo TOS da demanda | sim, API | 0.15 |
| Localização | `art_local_uf` / `art_local_municipio` × local da demanda | sim, API | 0.15 |
| Experiências declaradas | texto autodeclarado do perfil | não | 0.10 |
| Tipo de contrato | preferência declarada × demanda | não | 0.10 |
| Disponibilidade geográfica | raio declarado × local da demanda | não | 0.10 |

Três observações que a massa impõe:

**Dado verificado pesa mais que autodeclarado.** As três primeiras dimensões somam 0.70; as
autodeclaradas, 0.30. É a tradução numérica do compromisso de evidência.

**Localização é fraca nesta massa.** Todas as ARTs observadas são de Manaus/AM. A dimensão vale
implementar como regra de negócio, mas não separa candidato nenhum nos dados fictícios.

**Dimensão sem dado não penaliza.** Se o profissional não declarou disponibilidade geográfica,
essa dimensão sai da média em vez de contar zero — senão o perfil incompleto é punido, e o edital
pede explicitamente inclusão de quem está começando.

Sinais de força dentro da dimensão de competência, em ordem:

| Sinal | Origem | Papel |
|---|---|---|
| Código exato em ART coberta por CAT | `?p=cats` | mais forte |
| Código exato em ART registrada | `arts` ou CAO | forte |
| Afinidade parcial (3 ou 2 componentes) | prefixo TOS | proporcional ao peso |
| Modalidade compatível, sem ART nenhuma | `modalidades` | fraco: é atribuição, não execução |

Multiplicidade conta, mas com retorno decrescente: cinco ARTs no mesmo código valem mais que uma,
e menos que cinco vezes uma. Use raiz ou logaritmo pra volume não esmagar precisão — e porque
volume alto de ART não pode virar ranking implícito por antiguidade de carreira.

## O que a API impõe ao desenho

**Não existe busca reversa.** Não dá pra perguntar "quem tem ART no código `TOS_1.1.2.1`". A API
só busca por chave exata: CPF, CNPJ, RNP, registro CREA, número de ART ou CAT. Logo o universo de
candidatos são os que estão cadastrados na nossa plataforma, e o índice de evidência é nosso,
montado no cadastro.

**Nada de varredura.** Toda chamada é logada pela organização, e o item 10.4 do edital proíbe
coleta automatizada de dados. Buscar no cadastro do candidato, atualizar sob demanda, nunca
polling.

**O índice é cache, não base paralela.** O item 8.4 veda "a criação de base própria para simular
os dados disponibilizados pela API oficial". O índice local guarda o que a API respondeu, com
`fetched_at`, e é reconstruível a partir dela; nenhum registro é escrito à mão, e nenhuma consulta
de validação é atendida pelo cache. Vale deixar isso explícito na documentação técnica da entrega,
porque é o tipo de coisa que a banca pergunta.

**A TOS é estática.** 2000 linhas, baixadas uma vez, versionadas em `data/csv/tos.csv`.

**`pro_status` só vem por CPF.** Zerar a visibilidade de profissional com registro suspenso exige
ter guardado o CPF (cifrado). É uma decisão de LGPD consciente, não um descuido.

## Índice local

É a view `crea_evidencias` em `_arq/estrutura.sql`: uma linha por (candidato, código TOS, ART,
CAT), com os quatro níveis do código em colunas. Não é tabela — deriva de `crea_arts`,
`crea_art_atividades`, `crea_tos`, `crea_cat_arts` e `crea_quadro_tecnico`, então nunca sai de
sincronia e não tem código de manutenção. A regra de herança da empresa (vínculo vigente,
`qut_dt_fim IS NULL`, sem recorte temporal — D19) mora na view e em nenhum outro lugar.

Com os níveis em colunas, a seleção do pool vira uma query com `WHERE evi_nivel1 = ? AND
evi_nivel2 = ?` sobre índices existentes, e o motor roda inteiro local. A API só é chamada em
dois momentos: no cadastro ou atualização de um candidato, e na validação de um documento
informado manualmente.

## Cenários de demonstração

O plano anterior era usar a faixa temática de CNPJ da massa — demanda elétrica deveria trazer as
empresas da faixa 21–30. **Isso não funciona.** Os vínculos ART → TOS da massa são aleatórios: a
ELETRONORTE não tem uma única atividade de eletrotécnica no acervo, e a GÊNESIS (software) não tem
nenhuma de computação. O detalhamento está em `massa-de-dados.md`.

O roteiro da demonstração precisa ser escrito na ordem inversa:

1. Cadastrar na plataforma, pelo fluxo real, um punhado de profissionais e empresas.
2. Montar o índice de evidência a partir do que a API devolveu.
3. Consultar o índice para ver quais códigos TOS concentram acervo entre os cadastrados.
4. Escrever as demandas de demonstração a partir desses códigos.

Dá mais trabalho e é honesto: a demanda é escolhida sabendo que existe resposta para ela, sem
inventar dado e sem prometer coerência temática que a massa não tem.

O valor da demonstração não é quem aparece — não há primeiro lugar. É abrir um card do pool e
mostrar qual ART, de qual profissional, com qual código TOS, sustenta aquela correspondência.
