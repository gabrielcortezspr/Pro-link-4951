# Roteiro da demonstração

Os seis cenários do Anexo I, item 7, no caminho exato, com os identificadores que funcionam.

Serve para dois momentos diferentes: a **entrega de 17/09**, onde a banca pode rodar sozinha a
partir daqui, e o **Demo Day de 26/09**, onde alguém apresenta ao vivo. O caminho é o mesmo; o que
muda é quem conduz.

A versão executável deste roteiro é `e2e/specs/demonstracao.spec.js`, que percorre os seis
cenários no navegador e grava o vídeo da jornada completa. Quando os dois divergirem, o código está
certo e este documento está velho.

Há mais três vídeos, em `e2e/specs/jornadas.spec.js`: um por perfil (profissional, empresa e
administração), cada um com uma conta só, do login ao logout, percorrendo o que o Anexo I, item 3,
atribui àquele perfil. Este roteiro é o da narrativa dos seis cenários; os outros três respondem
"o que um profissional faz aqui?" sem trocar de papel no meio.

## Antes de começar

```bash
docker compose up -d
docker compose exec php composer install
curl -s http://localhost:8080/saude          # tem que responder, com tos_carregada = 2000
```

**A ordem de preparo do dado importa**, e está no `estado.md`. Resumida:

1. `php scripts/criar-admin.php` (interativo, ou com `--nome --email --senha`)
2. `php scripts/semear-candidatos.php` — cadastra pelo fluxo real, gastando quatro chamadas da API por profissional (D76)
   por candidato
3. `php scripts/abrir-visibilidade-demo.php` — visibilidade variada e determinística
4. `php scripts/preencher-declarados-demo.php` — as quatro dimensões autodeclaradas

E uma limpeza, porque os verificadores deixam rastro que a banca veria primeiro:

- `php scripts/revogar-sessoes-de-teste.php` — cada execução da suíte faz vários logins e nem todo
  caminho termina com logout, então `/privacidade` do profissional de demonstração lista dezenas
  de sessões abertas. Revoga, não apaga, e só toca conta de verificação e de demonstração.

Outros dois rastros **não têm script de limpeza**, e a única forma de tirá-los é recarregar o
banco antes da demonstração:

- `scripts/verificar-e4.php` grava **duas sessões do motor por execução**, e elas ocupam a
  primeira página de `/admin/sessoes`.
- `scripts/verificar-e6.php` abre denúncias de verificação, que entram na fila do moderador.

**Rode a suíte de ponta a ponta antes de recarregar, nunca depois**: ela também cria dado real.

## As contas

| Papel | Conta | Por que esta |
|---|---|---|
| Profissional | `pedro.henrique.alves.0451@prolink.local` | tem acervo em `TOS_10.4.2.3`, o código com mais candidatos na massa |
| Empresa | `alfa.engenharia.e.consultoria.ltda.0145@prolink.local` | uma das 15 empresas cujo CNPJ passa no dígito verificador |
| Administração | a que você criou com `criar-admin.php` | |

A senha das contas semeadas é a que `semear-candidatos.php` imprime ao final (`ProLinkDemo2026!`,
salvo se você passar `--senha`).

**As 85 empresas restantes da massa têm dígito verificador quebrado** e não se cadastram. Isso não
é defeito: a validação está correta e recusa todas. A lista das 15 que passam está em
`docs/massa-de-dados.md`.

## A demanda, e por que esta

`TOS_10.4.2.3` é "Planejamento Urbano, Metropolitano e Regional · Planejamento Urbano", e **três
profissionais** da massa têm acervo exatamente nele. `TOS_10.1.1` é do mesmo grupo, o que produz
afinidade parcial em vez de zero, e serve para a tela mostrar que compatibilidade tem graus.

**A demanda foi escrita depois de olhar o índice, nunca antes.** Os vínculos de ART para código
TOS da massa fictícia são aleatórios, e nenhum cenário pode assumir coerência temática: escolher
um assunto bonito e procurar quem o atende produz uma tela vazia. Se você precisar de outra
demanda, consulte primeiro:

```sql
SELECT evi_tos_codigo, COUNT(DISTINCT CONCAT(evi_candidato_tipo, evi_candidato_id)) AS candidatos
  FROM crea_evidencias GROUP BY evi_tos_codigo ORDER BY candidatos DESC LIMIT 10;
```

---

## Cenário 1 · O profissional cria o perfil e liga uma competência a uma experiência

**Caminho:** entrar como o profissional → `/perfil`

**A página é repartida em quatro abas**, cada uma com endereço próprio: `/perfil#acervo`,
`/perfil#experiencia`, `/perfil#preferencias` e `/perfil#privacidade`. A divisão é a tese da
página: a primeira é o que o conselho confirma, as duas do meio são o que a pessoa declara, e a
última é o controle do titular. A troca é `:target` puro, sem JavaScript, e o conteúdo das quatro
está no HTML o tempo todo, para quem lê o documento sem executar script.

**O que mostrar, nesta ordem:**

1. **O acervo verificado** (aba *Acervo técnico*). Duas ARTs importadas da API oficial, cada uma
   com o selo verde água ao lado do número, e os códigos TOS de cada atividade. O selo não é uma
   marca guardada no banco: a cada exibição a linha é reconferida contra o resumo criptográfico
   gravado na importação. Se divergir, o selo é suspenso e a divergência entra na trilha.
2. **A distinção que sustenta o projeto** (aba *Preferências*). Marca visual diferente, e a
   legenda de procedência fica fixa no alto da página, valendo para todas as abas. Dado verificado
   pelo conselho e dado escrito pela pessoa nunca se confundem na tela.
3. **A experiência com competência** (aba *Experiência*). Preencher "O que você fez", e em
   "Vincular a uma ART do seu acervo (opcional)" escolher uma das ARTs. A experiência é
   autodeclarada, a ART é verificada, e é a ART que carrega os códigos TOS que o motor vai ler.
4. **A aba *Privacidade***, que é o antigo "Quem vê o quê". Tudo em "Só eu" por padrão. Nada é
   público sem escolha. Ela governa **cada item deste perfil**; quem governa a conta inteira é a
   tela `/privacidade`, e cada uma aponta para a outra para as duas nunca se confundirem.

**Frase que vale dizer:** o portfólio não é o que a pessoa diz que fez; é o que o conselho
registrou que ela fez.

---

## Cenário 2 · A empresa publica uma demanda

**Caminho:** entrar como a empresa → `/demandas/nova`

1. Título, escopo, município `Manaus`, UF `AM`. Salvar rascunho.
2. **A demanda nasce rascunho, e isso é desenho.** Sem código da Tabela de Obras e Serviços não há
   o que compatibilizar, e publicar uma demanda que não pode ser compatibilizada só produziria uma
   tela vazia.
3. **Escolher os códigos.** Buscar por **"Planejamento Urbano"**, e acrescentar `TOS_10.4.2.3` e
   `TOS_10.1.1`. A busca é **textual**: procurar pelo código não acha nada, porque ninguém decora
   código TOS.
4. Publicar.

---

## Cenário 3 · O sistema apresenta correspondências e explica os critérios

**Caminho:** `/demandas/{id}/compativeis`

Este é o cenário que mais vale mostrar devagar.

1. **Cada compatível tem aderência por dimensão, não uma nota.** Seis barras, com o nome que
   aparece na tela: competência técnica, área de atuação, localização, experiência, tipo de
   contrato e disponibilidade. As três primeiras vêm da API e somam 0,70; as três autodeclaradas
   somam 0,30. Essa diferença **é** a tese do projeto.
2. **Dimensão sem dado não penaliza.** Ela sai da média em vez de contar zero, porque perfil
   incompleto não pode ser punido: o edital pede inclusão de quem está começando.
3. **A ordem da lista não é classificação.** O score decide quem entra no conjunto; a ordem vem de
   uma semente sorteada e gravada. O item 10.1 veda ranking de profissionais, e a plataforma não
   contorna isso com eufemismo: ela sorteia de verdade.
4. **O aviso de que a correspondência é indicativa fica visível na própria tela**, não atrás de um
   "saiba mais". É o item 10.2.

**Se perguntarem "e quem é o melhor?":** a plataforma não responde essa pergunta, por decisão e por
exigência. Ela responde "quem tem acervo aderente a esta demanda", que é outra coisa.

---

## Cenário 4 · O profissional manifesta interesse e a empresa vê o perfil

**Caminho:** profissional → `/demandas/{id}/manifestar`; depois empresa → `/demandas/{id}/interessados`

1. **A tela mostra o retrato antes do clique.** Exatamente o que vai sair do perfil, com os
   números do que está aberto. Manifestar interesse não abre nada que estava fechado.
2. **A confirmação é parte do requisito.** O envio não pode ser desfeito e é um por demanda, e a
   plataforma avisa **antes** de agir.
3. **O e-mail chega no Mailpit** (`http://localhost:8025`). A fila despacha depois da resposta, sem
   cron: uma peça móvel a menos no ambiente que a banca sobe.
4. **Do outro lado**, a empresa vê quem manifestou e abre o perfil **congelado no instante do
   envio**, não o perfil de agora. Alteração posterior não muda o que já foi recebido.

---

## Cenário 5 · O titular corrige, restringe e denuncia

**Caminho:** `/perfil` → `/privacidade` → `/denuncias/nova?entidade=DEMANDA&alvo={id}`

1. **Correção.** Na aba *Preferências* do `/perfil`, editar o resumo profissional. Cada edição
   guarda o valor anterior e o novo na trilha.
2. **Restrição.** Na aba *Privacidade* do `/perfil`, mudar um campo para "Só eu". Três níveis por
   campo e por documento.
3. **Privacidade.** Em `/privacidade`: os consentimentos com data e hora, a exportação dos dados em
   JSON, e a exclusão de conta com o que ela faz escrito na frente de quem clica.
4. **Denúncia.** Sempre contra um alvo. Abrir `/denuncias/nova` sem alvo devolve 404, e isso é
   regra: denúncia genérica não tem o que moderar.

---

## Cenário 6 · A administração audita e modera

**Caminho:** entrar como administração → `/admin`

1. **Visão geral.** Contagem agregada da operação. Nenhuma lista de pessoas ordenada por nada: é
   num painel de indicadores que o ranking vedado apareceria disfarçado de métrica.
2. **Trilha de auditoria** (`/admin/auditoria`). Filtro por conta, ação e período. A tabela é
   insert-only por trigger no banco: nem o administrador altera o que já foi registrado.
3. **Sessões do motor** (`/admin/sessoes`). Abrir uma: a plataforma **refaz o sorteio** a partir da
   semente gravada e compara com o que ficou no banco, na frente de quem audita. Não é a aplicação
   afirmando que é reproduzível, é a reprodução acontecendo.
4. **Moderação** (`/admin/denuncias`). Tratar a denúncia do cenário 5. Bloquear uma conta é
   operação atômica: status, sessões encerradas e registro, tudo ou nada.
5. **Contas** (`/admin/contas`). É o "gerir perfis" do Anexo I, item 3: a lista com busca, e o
   bloqueio e o desbloqueio com motivo, que vão para a trilha. Mostrar rápido, e só se sobrar
   tempo: o que ela prova já apareceu na moderação.
6. **A ação do próprio administrador aparece na trilha.** Voltar em `/admin/auditoria` e mostrar.
   Quem modera é auditado também.
7. **Parâmetros** (`/admin/parametros`). Os pesos do motor, editáveis, com a faixa aceitável
   declarada por campo e o antes e o depois indo para a trilha. É o item 12.3 deixando de ser uma
   frase na documentação.
8. **Integrações** (`/admin/integracoes`). A última capacidade do Anexo I, item 3, e a que mais
   vale explicar: a tela diz para onde a plataforma aponta, se a credencial está configurada (sem
   mostrar o token), o que já veio da API e está em cache com a data de cada coleção, e as últimas
   importações lidas da trilha. Os dois ajustes da sincronização são editáveis aqui. **Ela não
   chama a API ao carregar**, de propósito: o item 10.4 veda coleta automatizada e a organização
   registra cada chamada.
9. **Lixeira** (`/admin/lixeira`). O item 8.6j: nada é apagado, e o excluído continua acessível
   por aqui. Mostrar que conta excluída **pelo próprio titular** aparece e **não** pode ser
   restaurada por ato administrativo.

---

## Perguntas prováveis, e onde está a resposta

| Pergunta | Onde |
|---|---|
| "Vocês criaram uma base própria simulando a API?" | Não. `docs/matching.md` e o cabeçalho de `crea_*`: cache datado da resposta real, reconstruível |
| "Isso não é um ranking disfarçado?" | `docs/matching.md` e a D44: score filtra o pool, semente ordena, e a semente é auditável |
| "Onde está a IA?" | Não há. `_arq/arquitetura.md`, seção de declaração de uso de IA (item 12.3) |
| "Por que vocês guardam o CPF?" | Política de Privacidade, item 2.2, e a D03. A alternativa recusada está escrita |
| "Excluir conta apaga mesmo?" | Política de Privacidade, item 6, e as D05 e D62 |
| "Como sabemos que o resultado é reproduzível?" | `/admin/sessoes/{id}`, ao vivo |
| "E quem está começando a carreira?" | Perfil em construção: sinalizado, nunca excluído do conjunto. `docs/fluxos.md` |
