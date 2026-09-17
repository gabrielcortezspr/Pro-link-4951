# MER · Pro-Link

Modelo Entidade-Relacionamento do banco `prolink`, exigência do edital (item 8.3.2b).
Gerado em **17/09/2026** a partir do banco **real** (container `prolink-mariadb`), via
`information_schema` · não a partir de `_arq/estrutura.sql`. Nenhuma tabela foi escrita;
o script só faz `SELECT`/`SHOW`.

## Arquivos

| Arquivo | O que é |
|---|---|
| `mer.png` | Panorama: os 4 módulos (sis_/pro_/crea_/mat_) + a view `crea_evidencias`, com chaves primárias e estrangeiras. Linhas só dentro do mesmo módulo; FK, referência sem FK e dependência de view entre módulos diferentes aparecem só como `→ tabela` no próprio cartão, sem linha (ver "O que o panorama esconde de propósito" abaixo). |
| `mer-sis.png` | Módulo `sis_` (identidade, privacidade, auditoria): todas as colunas relevantes, cardinalidade completa em pé-de-galinha. |
| `mer-crea.png` | Módulo `crea_` (cache da API oficial): idem, mais o detalhe da view `crea_evidencias` e suas 8 tabelas de origem. |
| `mer-pro.png` | Módulo `pro_` (domínio Pro-Link): idem. |
| `mer-mat.png` | Módulo `mat_` (motor de compatibilização): idem. |
| `mer.pdf` | Os 5 diagramas acima, um por página, A2 paisagem (maior que A4 de propósito: 28 tabelas não cabem legíveis numa folha só). |

Tabelas de outro módulo que aparecem referenciadas a partir de um diagrama de módulo
entram como **cartão tracejado, só com PK** (é um lembrete de para onde a linha vai, não
uma tabela nova); o detalhe completo dela está no diagrama do módulo dela.

## Como regerar

```
cd /Users/camilamoi/Documents/Pro-link-4951
python3 scripts/gerar-mer.py
```

Precisa do `docker compose` com o serviço `mariadb` de pé, e de `node`/`npx` com
Playwright (Chromium já em cache, nada é baixado). Sem dependência nova: o script usa só
a biblioteca padrão do Python e o CLI `npx playwright screenshot`/`pdf`, que já vem com o
Playwright instalado. Roda de novo sempre que o schema mudar · ele lê o banco ao vivo,
então o diagrama nunca fica desincronizado do `.sql`.

## Decisão de ferramenta: por que não Mermaid

O caminho sugerido inicialmente era Mermaid `erDiagram` renderizado por Playwright. Depois
de desenhar o primeiro rascunho, a Cami pediu explicitamente que o MER saísse no design
system do Pro-Link (tokens de `public/assets/css/prolink.css`, Space Grotesk/Inter,
**cor por módulo**, inclusive no panorama com os 4 módulos juntos no mesmo diagrama).

Mermaid permite personalizar tema e fonte (`theme:'base'` + `themeVariables`, CSS na
página), mas só **por diagrama inteiro**, não por entidade dentro do mesmo diagrama. Como
o panorama precisa de 4 cores diferentes convivendo num só diagrama (uma por módulo) e da
view com estilo distinto no meio disso, Mermaid não alcança: teria que forçar um resultado
monocromático justo no diagrama que mais precisa da cor por módulo (a visão geral) para
"usar Mermaid mesmo assim". Optei por não forçar: o script desenha os 5 diagramas como
HTML+SVG autocontido (cartões em HTML, conectores com pé-de-galinha em SVG, tudo gerado em
Python a partir do modelo extraído do banco), o que dá controle por entidade e fecha 100%
nos tokens (cor, raio de 12px, tipografia) em vez de aproximar via `themeVariables`.
Playwright continua fazendo a exportação, exatamente como sugerido. Custo dessa escolha:
mais código no gerador do que apontar para uma lib pronta; o trade-off valeu porque o
pedido de design era uma restrição dura, não uma preferência.

## Onde a paleta tem uma exceção deliberada

Verde-água (`--pl-seal`) é, no resto do produto, reservado a "verificado". Aqui ele é
usado também para identificar o módulo `crea_`, autorizado pela Cami porque o significado
é o mesmo (`crea_` é exatamente o cache verificado pela API oficial). Nenhum outro módulo
usa essa cor. Laranja (`--pl-accent`) não entra em nenhum dos 5 diagramas: é a cor da
única ação preenchida da interface, e um diagrama não tem ação. As linhas pontilhadas
âmbar (referência sem FK) usam `--pl-warn`, que é um token de status diferente do
`--pl-accent` e já carrega esse mesmo sentido de atenção no resto do produto; não é a
mesma exceção, é um uso comum do token certo.

Fontes: os `.woff2` de Inter/Space Grotesk já vendorizados em
`public/assets/vendor/fontes/` (licença SIL OFL 1.1, ver `_arq/dependencias.md`) foram
reaproveitados, só os subconjuntos **latin** (cobrem todos os acentos do português;
**latin-ext** não foi embutido, para não inflar os HTML intermediários à toa), embutidos
em base64 direto no HTML gerado, para o diagrama não depender de rede nem de caminho
relativo ao repositório.

Legibilidade em preto e branco: nenhuma informação depende só de cor. PK/FK/UQ são
etiquetas de texto, o módulo tem sigla (SIS/PRO/CREA/MAT) escrita no cartão, a view tem
badge "VIEW" e borda tracejada, e toda linha tem uma legenda com o traço correspondente.

## Como ler a cardinalidade (pé-de-galinha)

| Símbolo (lado do traço) | Significado |
|---|---|
| duas marcas curtas | exatamente um (lado obrigatório, FK `NOT NULL`) |
| marca curta + círculo | zero ou um (lado opcional: FK `NULL`, ou o lado "1" de uma relação 1:1) |
| garfo + círculo | zero ou muitos (lado "N" comum) |

As únicas relações **1:1** do banco são `sis_usuarios` com `pro_profissionais` e
`sis_usuarios` com `pro_empresas` (a FK, além de `NOT NULL`, também é `UNIQUE`:
`uq_prf_usu` e `uq_emp_usu`). Todo o resto é 1:N. Não há N:N direto no banco: toda relação
muitos-para-muitos do domínio (profissional x modalidade, CAT x ART) passa por tabela
associativa própria (`pro_prof_modalidades`, `crea_cat_arts`), que aparece como entidade
normal, não como atalho N:N: é o que reflete a tabela física de verdade.

**O que o panorama esconde de propósito**: até 17/09/2026 o panorama desenhava as 27 FKs, as
5 referências sem FK e as 8 dependências da view também **entre módulos diferentes**: a linha
cruzava o diagrama inteiro, cruzava com outras linhas no meio do caminho, e não acrescentava
informação que o próprio cartão já não desse (toda coluna FK mostra `→ tabela_alvo` na cor do
módulo referenciado; o cartão da view já lista as 8 dependências como linhas). Corrigido: o
panorama agora desenha linha **só entre tabelas do mesmo módulo** (15 das 27 FKs, e 1 das 5
referências sem FK: `sis_auditoria.aud_usu_id`, intra-`sis_`) e não desenha nenhuma
dependência da view. O que passou a ficar só no cartão, sem linha: toda FK entre módulos
(rótulo colorido `→ tabela`); as outras 4 referências sem FK, todas entre `crea_` e `pro_`,
listadas uma a uma em "Achados de modelagem" abaixo; e as 8 dependências da view (linhas
completas em `mer-crea.png`). Pé-de-galinha também ficou só nos 4 diagramas de módulo: no
panorama a linha que sobra é só direção (seta simples), pra não repetir um marcador que fica
ilegível quando várias convergem no mesmo cartão. Ver legenda de cada arquivo.

## Limitação conhecida do PDF

No `mer.pdf`, a primeira página (o panorama) corta o rodapé do cartão da view e a legenda: a
altura que o layout calcula para aquele fragmento fica abaixo da altura que ele renderiza, e a
escala da página é decidida a partir da altura calculada. As quatro páginas de módulo estão
completas.

**O `mer.png` do panorama está completo e correto**, e é a fonte para ele. O item 8.3.2b do edital
pede o MER "em PDF, PNG e/ou `.mwb`": os dois formatos juntos cobrem tudo, e cada um é usado onde
comporta. O conserto de verdade é medir a altura renderizada no navegador antes de montar a
página, e está anotado no cabeçalho de `scripts/gerar-mer.py`.

As cores de fundo, que antes sumiam no PDF inteiro, passaram a ser impressas: o `npx playwright
pdf` não expõe `printBackground`, e `scripts/mer-pdf.mjs` é o driver mínimo que usa a API para
isso, com o Playwright que a suíte de ponta a ponta já instalou.

## Achados de modelagem (resumo, detalhe completo no relatório da tarefa)

1. **5 referências por convenção de nome sem FK declarada** (linha pontilhada âmbar nos 4
   diagramas de módulo; no panorama só `sis_auditoria.aud_usu_id` aparece desenhada, por ser
   intra-`sis_`: as outras 4 cruzam módulo e ficam só aqui e no diagrama do módulo delas, ver
   "O que o panorama esconde de propósito" acima): `crea_arts.art_pro_rnp`, `crea_cats.cat_pro_rnp`,
   `crea_quadro_tecnico.qut_pro_rnp` e `.qut_emp_registro_crea` (as 4 por desenho: o
   módulo `crea_` é cache reconstruível, comentário no topo do módulo em
   `estrutura.sql`) e **`sis_auditoria.aud_usu_id`**, que quebra sem justificativa
   documentada o padrão `*_usu_id → sis_usuarios.usu_id` usado por outras 12 colunas.
2. **`sis_auditoria` é a única tabela sem `_log`/`_status`** (tem só `_dt_registro`).
   Coerente com ela ser insert-only (triggers bloqueiam `UPDATE`/`DELETE` no fim de
   `estrutura.sql`), mas é desvio literal do padrão do item 8.6 do edital, sinalizado no
   próprio cartão do diagrama (rodapé em âmbar), não só aqui.
3. **`crea_evidencias` é view**, não tabela: confirmado por `TABLE_TYPE` em
   `information_schema.TABLES` e pelo `SHOW CREATE VIEW` ao vivo, batendo com
   `_arq/estrutura.sql`. Sem divergência entre o `.sql` e o banco real em tabela, coluna,
   PK, FK ou tipo · ver relatório da tarefa para a evidência.

## Convenção de módulo x cor neste diagrama

| Prefixo | Módulo | Cor |
|---|---|---|
| `sis_` | identidade, privacidade e auditoria | navy `--pl-navy` |
| `pro_` | domínio Pro-Link | azul-dado `--pl-data` |
| `crea_` | cache da API oficial | verde-água `--pl-seal` (exceção, ver acima) |
| `mat_` | motor de compatibilização | azul `--pl-blue` |
