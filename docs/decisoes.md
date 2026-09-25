# Registro de decisões

Por que o Pro-Link é como é. Uma entrada por decisão que teve **alternativa real** — se não havia
escolha, é exigência do edital e mora em `edital-requisitos.md`, não aqui.

**Este arquivo só cresce.** Nunca reescreva uma entrada: decisão revista ganha entrada nova que
cita a antiga (ver D07). O histórico de mudança de ideia é informação, não sujeira — é o que
permite responder "por que vocês não fizeram do jeito óbvio?" sem depender de memória.

Cada entrada tem quatro campos. **Contexto** é o problema, sem a solução. **Decisão** é o que
fizemos. **Alternativa recusada** é o caminho que um avaliador provavelmente imaginaria — é o
campo mais importante numa arguição. **Consequência** é o que isso custou ou travou.

Para que serve, em ordem de urgência: responder à banca no Demo Day; escrever a seção de
*declaração de uso de IA, vieses e limitações* que o item 12.3 e o Anexo VI exigem na entrega
(E7); e impedir que a gente desfaça sem perceber uma decisão que custou análise.

## Índice por etapa

| Etapa | Decisões |
|---|---|
| Fundação (antes da E0) | D01, D02, D03, D04, D05 |
| E0 — fundação que faltou | D06 |
| E1 — identidade e consentimento | D07, D08, D09, D10, D11, D12 |
| E2 — integração com a API | D13 a D27, D28, D29 |
| E1 — auditoria da etapa (resgatada) | D30, D31 |
| Front — design system | D32, D33, D34, D35, D36, D37, D38 |
| E6 — denúncias e painel | D39, D40, D41, D43 |
| E4 — motor de compatibilização | D44 |
| Front — padrão visual no pipeline | D42 |
| Revisão de código (15/09) | D45, D46, D47, D48, D49, D50 |
| E4 — feed do demandante | D51, D52 |
| Front — largura e divulgação progressiva | D53 |
| E4 — busca ativa e auditoria de sessões | D54, D55, D56 |
| E5 — manifestação, mensagens e notificações | D57, D58, D59 |
| E7 — entrega | D60 a D68 |
| E7 — auditoria de RF por entidade e refino visual | D69, D70, D71, D72, D73 |
| E7 — fechamento da entrega | D74, D75 |
| E8 — CAT, atualização do acervo e preferências da demanda (pós-entrega) | D76, D77, D78, D79, D80 |

---

## D01 · O índice de evidência é uma view, não uma tabela

`06/09/2026` · fundação · commit `d032b51` · mecânica em [`matching.md`](matching.md) e `_arq/estrutura.sql`

**Contexto.** O motor precisa responder "quem tem acervo no código `TOS_1.1.2.1`" em tempo de
requisição. A API não tem busca reversa, então o índice é nosso. Ele cruza cinco tabelas
(`crea_arts`, `crea_art_atividades`, `crea_tos`, `crea_cat_arts`, `crea_quadro_tecnico`) e precisa
dos quatro níveis do código TOS em colunas separadas para a busca por prefixo usar índice.

**Decisão.** `crea_evidencias` é uma view. A regra de herança do acervo da empresa — só vínculo
vigente, `qut_dt_fim IS NULL` — mora dentro dela e em nenhum outro lugar.

**Alternativa recusada.** Tabela materializada, populada no cadastro e atualizada por rotina.
Seria mais rápida de consultar, e custaria código de sincronização, um estado que pode divergir
da origem e uma pergunta desconfortável na banca: uma tabela própria com dados derivados da API
se parece com a "base própria para simular os dados da API" que o item 8.4 veda. Uma view não se
parece com isso porque não guarda nada.

**Consequência.** Nenhuma manutenção e nenhuma chance de divergir. Em troca, cada consulta do
motor paga os joins. Com 290 ARTs na massa é irrelevante; se um dia doer, a saída é materializar
a view, não mudar o desenho em volta dela.

---

## D02 · O score filtra quem entra; a semente decide a ordem

`06/09/2026` · fundação · commit `6c067e3` · mecânica em [`matching.md`](matching.md)

**Contexto.** O item 10.1 do edital veda "ranking de profissionais" e o 10.2 diz que a
correspondência é "apenas indicativa". Mas o item 3.2 exige compatibilizar por competências,
área, localização e experiência — o que produz, inevitavelmente, um número por candidato.

**Decisão.** O número existe e nunca ordena. O score decide **se** o candidato entra no pool
(limiar de `sis_parametros`); uma semente gerada por sessão decide **em que ordem** o pool
aparece. A semente vai gravada em `mat_sessoes` com o pool e o limiar, então qualquer sessão
passada é reproduzível pelo administrador.

**Alternativa recusada.** Ordenar por score e chamar de "relevância". É o que um marketplace faz
e é ranking com outro nome — o candidato de score 0.9 sempre acima do de 0.6 é exatamente a
ordenação que o item 10.1 proíbe.

**Consequência.** Atende de uma vez o 10.1 (sem ranking), o 10.2 (indicativo) e o 12.3
(explicável, auditável, com viés declarado — a semente é nossa defesa contra *position bias*).
O custo é de produto: o demandante não recebe "o melhor primeiro" e precisa abrir os cards. É o
comportamento que o edital pede, não uma limitação técnica, e a interface tem que dizer isso.

---

## D03 · Guardamos o CPF, cifrado, em vez de descartá-lo

`06/09/2026` · fundação · commit `6c067e3` · `src/Support/Crypto.php`, `sis_usuarios.usu_documento_cif`

**Contexto.** A RF02 exige refletir o status do registro do profissional: registro suspenso não
pode aparecer no pool. O `pro_status` só existe na resposta de `?p=profissionais&cpf=`, e nenhum
outro endpoint o devolve — nem o de ARTs, nem o CAO. Sem o CPF guardado não há como reconsultar.

**Decisão.** O CPF fica em `usu_documento_cif`, AES-256-GCM, com hash cego separado
(`usu_documento_hash`, HMAC-SHA-256) para busca exata sem decifrar a coluna. `scripts/sincronizar-status.php`
decifra sob demanda para reconsultar.

**Alternativa recusada.** Descartar o documento depois do cadastro, que é a postura mais limpa em
LGPD: o dado que não existe não vaza. Custaria a sincronização de status, e um perfil de registro
suspenso continuaria visível — o que é pior para o titular e para quem contrata do que guardar o
CPF cifrado.

**Consequência.** Assumimos guarda de dado sensível e a obrigação que vem com ela: cifragem em
repouso, `APP_KEY` fora do repositório, exibição sempre mascarada (`Documento::mascarar`), e a
justificativa de finalidade escrita — é isso que o item 11.3 cobra. A decisão precisa aparecer na
Política de Privacidade, não só no código.

---

## D04 · A trilha de auditoria é imutável no banco, não na aplicação

`06/09/2026` · fundação · commit `6c067e3` · `_arq/estrutura.sql`, `src/Support/Auditoria.php`

**Contexto.** O item 8.5g exige registro de auditoria. Uma trilha que o próprio sistema pode
reescrever não prova nada sobre o que aconteceu.

**Decisão.** `sis_auditoria` é insert-only por *trigger* no MariaDB: `UPDATE` e `DELETE` lançam
`SQLSTATE 45000`. Nem a aplicação nem o administrador alteram. `Auditoria::registrar()` é o único
caminho de escrita, e não engole exceção — se a auditoria falha, a operação não se considera
concluída.

**Alternativa recusada.** Garantir a imutabilidade só na camada de serviço, sem trigger. Mais
simples, e depende de ninguém nunca escrever um `DELETE` à mão no banco durante a correria da
entrega. A garantia tem que estar onde o dado está.

**Consequência.** Teste que escreve auditoria não tem como limpar o que escreveu — `DELETE` é
recusado de propósito. Isso moldou a estratégia de teste: verificação de ponta a ponta por script
sobre banco descartável, não por teste que promete faxina no `tearDown`.

---

## D05 · A exclusão é lógica, e a LGPD é atendida por revogação de acesso

`06/09/2026` · fundação · commit `6c067e3` · `_arq/estrutura.sql`

**Contexto.** Dois requisitos que se contradizem na letra. O item 8.6j manda nada ser apagado
fisicamente, com `_status = 'X'` e lixeira administrativa. O item 11.3 exige "descarte" como
direito do titular.

**Decisão.** `_status = 'X'` em toda tabela. Para o titular, "excluir minha conta" significa:
sai de toda consulta operacional, sessões encerradas na hora, perfil fora do pool e fora da busca
— acesso revogado de forma efetiva e imediata. A remoção física é ato administrativo, registrado
em auditoria, não um efeito colateral do clique do usuário.

**Alternativa recusada.** `DELETE` de verdade no pedido do titular. Viola o 8.6j, destrói a trilha
de auditoria que o 8.5g exige e apaga o registro de que o consentimento existiu — o que
atrapalharia justamente provar que a plataforma agiu certo.

**Consequência.** A leitura é defensável mas não é óbvia, então tem que estar escrita na Política
de Privacidade e na arguição: a plataforma entrega revogação e portabilidade imediatas, e o
descarte físico é procedimento com trilha. Toda query operacional precisa filtrar `_status`, o
que é fácil de esquecer — é item de revisão da E7.

---

## D06 · Autorização por requisição, sem superusuário implícito

`06/09/2026` · E0 · commit `502f5f4` · `public/index.php`, `src/Support/Router.php`

**Contexto.** Quatro perfis mais o público, com telas que se sobrepõem. O jeito comum de errar
isso é verificar o perfil no login, guardar na sessão e confiar nela.

**Decisão.** Cada rota declara seus perfis e o front controller verifica em **toda** requisição:
401 para anônimo, 403 com registro em `sis_auditoria` para perfil errado. `ADMIN` não herda nada
— rota de administrador aceita só administrador, e rota de profissional recusa administrador.

**Alternativa recusada.** Hierarquia de perfis com administrador no topo, herdando todas as
permissões. Conveniente, e é como se constrói um A01 do OWASP: qualquer rota nova fica aberta ao
administrador sem ninguém decidir isso.

**Consequência.** Administrador que precisar ver tela de profissional precisa de rota própria em
`/admin`, explícita. Mais trabalho, e a matriz de acesso fica legível na lista de rotas.

---

## D07 · CNPJ valida o dígito verificador, e a massa é que se adapta

`08/09/2026` · E1 · commit `27b05d8`, **revendo** `e440965` · `src/Support/Documento.php`

**Contexto.** Os dois últimos dígitos de CPF e CNPJ são calculados dos anteriores por módulo 11,
e é essa conta que faz um formulário recusar erro de digitação sem consultar nada. Medido sobre a
massa fictícia do desafio: os **100 CPFs** fecham a conta; só **15 dos 100 CNPJs** fecham
(`00123007000190` traz `90` onde a conta dá `09`).

**Decisão.** Validação completa nas duas, formato e dígito verificador. As 85 empresas com DV
quebrado são recusadas no cadastro. `scripts/validar-massa.php` lista as 15 cadastráveis e
`tests/Dados/MassaDeDadosTest.php` trava a contagem.

**Alternativa recusada — e que nós chegamos a implementar.** O commit `e440965` validava CNPJ só
por formato, deixando o DV de fora para não bloquear 85% das empresas da massa. O raciocínio era
"a API é a autoridade real, o DV local não decide nada". Está errado na prioridade: transforma
defeito do dado que nos deram em defeito da nossa validação, e um avaliador que digitar um CNPJ
qualquer no formulário e vê-lo aceito não vai perguntar sobre a massa. Além disso o custo era
imaginário — a demonstração pede quatro empresas e existem quinze.

**Consequência.** Duas fixtures ficaram só para teste de parser: `cao_66897.json` (ELETRONORTE) e
`cao_82940.json` (GÊNESIS) são de empresas com DV quebrado, que não passam pelo cadastro e não
podem ser sujeito de cenário. Fixture nova sai das 15 listadas em
[`massa-de-dados.md`](massa-de-dados.md). Ainda falta confirmar, quando a API estiver alcançável,
quais das 15 têm acervo no quadro técnico — CNPJ válido não garante ART.

---

## D08 · A E2 é escrita contra fixtures, com o transporte do cliente injetável

`08/09/2026` · E1 · sem commit ainda · `src/Service/CreaApiClient.php`

**Contexto.** A sessão de desenvolvimento na nuvem não alcança `desafio-prolink.crea-am.org.br`:
a política de egress recusa o túnel com 403 antes do TLS, então nem o cabeçalho de autorização
sai. Não é problema de token. O Docker Hub está bloqueado do mesmo jeito, o que impede subir o
`docker compose` lá.

**Decisão.** O `CreaApiClient` ganha uma interface de transporte: implementação cURL em produção,
implementação que lê de `fixtures/` nos testes. A E2 inteira — `PortfolioService`, gravação do
cache, selo HMAC, herança do CAO — é desenvolvida e testada offline contra as 14 fixtures, e
validada contra a API na máquina do Gabriel.

**Alternativa recusada.** Esperar acesso à API para começar a E2, ou pedir a liberação do host na
política do ambiente antes de escrever código. Custaria dias de calendário que não temos, e
deixaria a E2 sem teste automatizado mesmo depois de liberada — o transporte injetável é bom
desenho independentemente do bloqueio.

**Consequência.** Falta uma fixture que pagine de verdade: `profissional_arts.json` é página 1 de
1, então o laço de paginação não tem contra o que ser verificado. Resolve-se com uma captura de
`?p=profissionais/{rnp}/arts&limit=2` em duas páginas, feita na máquina do Gabriel. Enquanto isso
o laço fica sem cobertura — está anotado como pendência da E2, não como feito.

---

## D09 · A sessão tem respaldo no servidor, conferido a cada requisição

`08/09/2026` · E1 · commit a seguir · `src/Repository/SessaoRepository.php`, `public/index.php`

**Contexto.** A sessão do PHP vive num arquivo no disco do servidor. Derrubar quem já está logado
— porque o administrador bloqueou a conta (E6), porque o titular excluiu a conta, ou porque a
senha foi trocada — exigiria achar e apagar esse arquivo. Na prática, ninguém faz isso, e o
usuário bloqueado continua navegando até o cookie vencer.

**Decisão.** Cada login grava uma linha em `sis_sessoes` com o **hash** do identificador de
sessão, e o front controller confere em toda requisição autenticada se aquela linha existe, não
está revogada e não expirou. Revogar é um `UPDATE` numa coluna; o efeito é na requisição seguinte.
Troca de senha e exclusão de conta já derrubam todas as sessões do usuário.

**Alternativa recusada.** Confiar na expiração do cookie e na limpeza de sessão do PHP. Significa
que "bloquear usuário", a operação atômica 5 da proposta, não bloquearia nada por até duas horas —
o pior momento possível para uma plataforma que existe para mediar contratação técnica.

**Consequência.** Uma consulta por requisição autenticada, em rota não pública. É o custo de poder
revogar, e torna a E6 uma tela em cima de mecanismo que já funciona em vez de um problema novo.
Guardar só o hash significa que dump do banco não entrega sessão de ninguém.

---

## D10 · "Nunca concedido" é estado distinto de "concedido e revogado"

`08/09/2026` · E1 · commit a seguir · `_arq/estrutura.sql`, `src/Repository/ConsentimentoRepository.php`

**Contexto.** `sis_consentimentos.con_dt_concessao` nascera `NOT NULL DEFAULT CURRENT_TIMESTAMP`.
Ao gravar uma finalidade que o titular **recusou** no cadastro, o banco rejeitou o `NULL` — e a
saída fácil seria gravar a data de agora, fazendo a linha dizer "concedida e revogada no mesmo
instante".

**Decisão.** A coluna passou a aceitar `NULL`, com `NULL` significando "nunca concedida". Recusa
no cadastro grava `con_concedido = 0`, `con_dt_concessao = NULL` e a data de revogação; revogação
posterior preserva a data original de concessão.

**Alternativa recusada.** Gravar a data de agora na recusa, para não mexer no schema. Produziria
trilha falsa de consentimento — exatamente o registro que o item 11.3 existe para tornar
confiável, e o tipo de coisa que não se quer explicar numa fiscalização.

**Consequência.** `estrutura.sql` mudou, então quem tiver banco antigo precisa recriar (na
prática, `docker compose down -v`). Em troca, a pergunta "este titular já consentiu com isso
alguma vez?" tem resposta no dado, sem inferência.

---

## D11 · Logout é POST, e o menu tem formulário em vez de link

`08/09/2026` · E1 · commit a seguir · `templates/layout/base.html.twig`, `public/index.php`

**Contexto.** O layout já trazia `<a href="/sair">Sair</a>`, de antes de existir rota. Logout muda
estado do servidor, e o item 8.5e exige proteção de CSRF em escrita — que um link GET não tem.

**Decisão.** `/sair` é rota POST com token CSRF, e o item do menu é um formulário com botão
estilizado como link.

**Alternativa recusada.** Manter GET, argumentando que forçar logout de alguém é dano pequeno. É
pequeno e é real: um `<img src>` numa página qualquer derrubaria a sessão de quem visitasse, e é
um achado gratuito para quem for rodar o checklist OWASP da E7 contra a aplicação.

**Consequência.** Um formulário no menu em vez de uma tag `<a>`, com o CSS cuidando da aparência.
Nada mais muda — e fica o padrão: escrita nenhuma por GET em nenhuma tela nova.

---

## D12 · O critério de pronto da etapa é um script executável, não uma lista lida

`08/09/2026` · E1 · commit a seguir · `scripts/verificar-e1.php`

**Contexto.** O "Pronto quando" da E1 no backlog exige cadastro nos quatro perfis, bloqueio por
tentativas, exportação em JSON e auditoria mostrando tudo. Nada disso é testável por teste
unitário: depende de CSRF, cookie, sessão, redirecionamento e autorização por requisição — coisas
que só existem numa requisição HTTP de verdade.

**Decisão.** `scripts/verificar-e1.php` executa o critério por HTTP contra a aplicação rodando:
71 verificações nomeadas, saída colorida, código de saída 1 se alguma falhar. Usa o banco para
preparar cenário (liberar bloqueio) e conferir resultado (auditoria, cifragem em repouso). Cada
execução usa e-mails com sufixo de hora, e as contas terminam marcadas como excluídas.

**Alternativa recusada.** Testes de integração em PHPUnit com banco. `sis_auditoria` é insert-only
por trigger (D04), então o `tearDown` não tem como limpar o que o teste escreveu — e um teste que
não consegue desfazer o próprio efeito polui o banco de quem roda a suíte. Um script de
verificação, explicitamente executado, é honesto sobre o que faz.

**Consequência.** `composer test` segue rápido e sem dependência de banco (62 testes de lógica
pura), e a prova de ponta a ponta é um comando separado que a banca pode rodar na frente da gente.
Cada etapa seguinte ganha o seu `verificar-eN.php`. O primeiro bug achado pelo script, aliás, foi
nele mesmo: PHP avalia argumentos antes da chamada, então buscar o token CSRF no mesmo argumento
que zerava a sessão fazia as verificações negativas passarem pelo motivo errado.

---

## D13 · O transporte devolve resposta crua, e nenhum `.env` troca um pelo outro

`08/09/2026` · E2 · commit a seguir · `src/Support/Transporte.php`, `src/Service/CreaApiClient.php`

**Contexto.** A D08 decidiu que o `CreaApiClient` teria transporte injetável. Faltava decidir duas
coisas que ela não resolveu: qual é o contrato do transporte, e como se escolhe entre rede e
fixtures em tempo de execução.

**Decisão.** O transporte devolve `RespostaHttp`: status e corpo **string**, sem interpretar nada.
Todo o significado — `200 []` é "não pertence", `404` é "não existe", `401`/`429` são
indisponibilidade, corpo não-JSON é indisponibilidade — continua dentro do cliente, num lugar só.
E a escolha do transporte é por injeção explícita: a produção usa cURL por ser o padrão do
construtor, e o de fixtures só entra onde alguém o constrói de propósito.

**Alternativa recusada.** Duas. A primeira, o transporte devolver array já decodificado: mais
curto, mas o transporte de fixtures desviaria justamente do código que interpreta status, e os
testes da E2 estariam verificando um caminho que a produção não percorre. A segunda,
`PROLINK_API_TRANSPORTE=fixtures` no `.env`, que deixaria navegar as telas da E2 offline — é uma
forma barata de a aplicação servir dado fictício achando que é da API, e o item 8.4 do edital é
exatamente sobre isso. Uma variável de ambiente errada no dia da demonstração seria irrecuperável.

**Consequência.** Clicar as telas da E2 sem rede exige um script que monte o cliente com o
transporte de fixtures na mão. Custo aceito: é chato uma vez, e o risco que evita é o de mostrar
dado inventado para a banca. O token também mudou de casa — vive no `TransporteCurl`, não no
cliente, então a suíte roda em máquina sem credencial nenhuma.

---

## D14 · O fake deriva respostas, e a derivação é conferida contra a captura real

`08/09/2026` · E2 · commit a seguir · `src/Support/TransporteFixture.php`, `tests/Service/CreaApiClientTest.php`

**Contexto.** As catorze fixtures cobrem um caso cada: uma ART validada, uma página de ARTs, um
CAO. A E2 precisa de mais — validar as quatro ARTs da profissional, percorrer páginas, distinguir
ART que não é dela. Capturar uma fixture por caso significaria dezenas de chamadas a uma API que
registra tudo e cujo edital proíbe coleta automatizada (10.4).

**Decisão.** O `TransporteFixture` **deriva** o que falta a partir do que foi capturado: projeta a
resposta de validação de ART a partir da lista de ARTs, tira as atividades de cada ART da mesma
lista, e refatia envelopes no tamanho de página pedido. Para a derivação não virar ficção, a suíte
compara o que o fake produz com as capturas reais nos casos em que as duas existem. E requisição
sem captura levanta `LogicException` em vez de inventar um `404` — um 404 fabricado faria um teste
passar pelo motivo errado.

**Alternativa recusada.** Capturar tudo, o que custaria chamadas demais numa API observada; ou o
fake devolver sempre o mesmo arquivo, ignorando os parâmetros, que faria a suíte inteira passar
sem provar nada sobre a distinção `200 []` × `404` — que é a distinção da qual a RF03 depende.

**Consequência.** A lacuna que a D08 deixou anotada está fechada: em 08/09 capturamos
`?p=profissionais/0412340011/arts&limit=2` nas duas páginas, e o envelope refatiado pelo fake é
igual, campo a campo, ao que o servidor devolveu — está no teste. Na mesma verificação, a captura
de 06/09 do endpoint de CPF continua idêntica à resposta de hoje. Sobra uma ressalva viva: o
comportamento de `?p=arts/{numero}/atividades` para ART inexistente segue não observado, e o fake
falha alto em vez de escolher entre `404` e `200 []`.

---

## D15 · O script de verificação gera os próprios documentos (revisa a D12)

`08/09/2026` · E2 · commit a seguir · `scripts/verificar-e1.php`

**Contexto.** A D12 afirmou que sufixo de hora no e-mail bastava para o script rodar de novo sem
colidir. Não bastava, e só apareceu ao rodar duas vezes na mesma máquina: os quatro cadastros
usavam documentos fixos da massa, e `documentoEmUso()` não filtra status de propósito — conta
excluída continua segurando o CPF dela, que é o que impede recadastrar um documento para zerar
histórico. A segunda execução falhava nos quatro cadastros, e o critério de pronto da E1 era, na
prática, de uso único por banco.

**Decisão.** O script gera CPF e CNPJ válidos a partir do relógio. A conta do dígito verificador
está escrita de novo dentro do script, e não importada de `Support\Documento`: são duas
implementações independentes do mesmo módulo 11, e o cadastro só aceita se concordarem —
divergência vira falha visível. A massa oficial continua conferida, agora pelo validador, sem
consumir documento em cadastro.

**Alternativa recusada.** Escolher um documento livre da massa a cada execução: os CNPJs válidos
são quinze (D07), então o script se esgotaria em quinze execuções. Ou apagar fisicamente as contas
criadas, que violaria a exclusão lógica do item 8.6j e esbarraria no insert-only da auditoria (D04).

**Consequência.** 74 verificações, executáveis quantas vezes se queira — rodadas três vezes
seguidas para confirmar. A verificação de documento repetido ficou melhor do que era: usa o
documento cadastrado na própria execução, então prova a unicidade contra o que acabou de entrar,
e não contra resíduo de uma execução anterior.

---

## D16 · A E2 tem dois critérios de pronto: um sem rede e um contra a API real

`08/09/2026` · E2 · commit a seguir · `scripts/verificar-api.php`

**Contexto.** A D14 deixou o `TransporteFixture` provando que o cliente lê certo as respostas
gravadas. Isso é metade do problema. A outra metade é se a API de hoje ainda responde como as
capturas de 06/09 dizem — e nenhuma quantidade de teste offline responde a isso. Havia também uma
pergunta em aberto que a D14 registrou como não observada: `?p=arts/{numero}/atividades` para uma
ART inexistente devolve `404` ou `200 []`?

**Decisão.** Dois critérios, com propósitos separados. O `composer test` roda offline, em qualquer
máquina, sem token, e é o que trava regressão — 87 testes. O `scripts/verificar-api.php` exercita
todo método do `CreaApiClient` contra a API oficial e compara cada resposta com a fixture
correspondente: 38 verificações, quinze chamadas, sujeitos fixos da massa. É o que detecta
mudança do lado deles.

**Alternativa recusada.** Rodar a verificação contra a API dentro do `composer test`. Amarraria a
suíte a rede e a token, e cada execução de teste viraria chamada registrada pela organização numa
API cujo edital proíbe coleta automatizada (10.4) e cujo limite de taxa ninguém conhece. Um
desenvolvedor rodando testes em laço viraria, sem querer, exatamente o padrão que não podemos ter
nos logs deles.

**Consequência.** A pergunta em aberto foi respondida na primeira execução: ART inexistente em
`/atividades` devolve **404**, com `{"error": "ART não encontrada para o número informado."}`. Faz
sentido com a regra geral — número no caminho do recurso se comporta como o RNP em
`profissionais/{rnp}/arts`, e o `200 []` fica para busca por filtro. A resposta virou
`fixtures/erro_art_inexistente.json`, e o `TransporteFixture` deixou de recusar o caso: agora
devolve o 404 observado, o que a D14 já previa como caminho ("quando o comportamento passa a ser
observado, a captura entra em fixtures/").

Na mesma execução, **todas as sete fixtures comparáveis voltaram idênticas** às capturas de
06/09 — profissional, empresa, quadro técnico, CAO, validação de ART e atividades. A massa não se
mexeu em dois dias, e o CAO continua sem `cao_arts`, sem local de ART e sem `qut_dt_fim`.

---

## D17 · O Selo ART prova integridade em repouso, não procedência

`08/09/2026` · E2 · commit a seguir · `src/Support/Acervo.php`

**Contexto.** A proposta promete "dado verificado" e o schema descreve `art_hash` como HMAC da
resposta canonicalizada. Faltava dizer o que exatamente é assinado e, mais importante, o que a
assinatura permite afirmar na frente da banca.

**Decisão.** O selo é HMAC-SHA256 sobre os campos da ART que vieram da API **mais a lista de
atividades TOS**, ordenada pelo código, com a chave no servidor. É recalculado na exibição a
partir da linha do banco e comparado com o gravado; divergência vira aviso na tela e
`SELO_DIVERGENTE` em `sis_auditoria`, nunca exceção que derruba a página.

As atividades entram no selo, e essa é a parte que importa. Sem elas, acrescentar um `tos_codigo`
a uma ART direto no banco não quebraria nada — e `tos_codigo` é a única informação desta massa
que discrimina um candidato de outro (`aat_descricao` e `art_objeto` não discriminam). Seria
exatamente o lugar onde uma adulteração compensaria.

**Alternativa recusada.** Assinar a resposta bruta da API e guardá-la. Pareceria mais forte, mas
a mesma ART chega por três endpoints com campos diferentes, então a conferência dependeria de
lembrar qual resposta gerou o selo — e o que precisamos verificar é a linha exibida, não o
histórico de como ela foi montada. Também foi recusado assinar só os campos da ART, pelo motivo
acima.

**Consequência.** É preciso dizer com precisão o que o selo significa, porque a formulação
natural é generosa demais. **Ele não prova que o dado veio do CREA.** Prova que ninguém mexeu
nele depois que gravamos. Prova de procedência exigiria assinatura do próprio CREA, que a API não
emite — e afirmar mais do que isso numa arguição seria indefensável. O selo também ignora
`art_id`, `art_status` e datas nossas, senão uma exclusão lógica legítima quebraria a conferência.

---

## D18 · A rede fica fora da transação, e a importação drena a API antes de gravar

`08/09/2026` · E2 · commit a seguir · `src/Service/PortfolioService.php`

**Contexto.** `associarArt` é a operação atômica 1 da proposta: duas chamadas à API e uma
gravação que precisa ser tudo-ou-nada. A importação do acervo no cadastro é a mesma coisa
multiplicada por até 290 ARTs. O jeito curto de escrever as duas é abrir a transação, chamar a
API dentro e gravar conforme as respostas chegam.

**Decisão.** Toda chamada acontece **antes** do `beginTransaction`, e a transação envolve só a
gravação. `importarArts` drena o generator do `CreaApiClient` inteiro antes de abrir a transação,
mesmo custando memória, em vez de gravar página a página.

**Alternativa recusada.** Gravar enquanto pagina, que era inclusive o motivo de o cliente expor
um generator. Manteria a memória baixa e seguraria locks do InnoDB pelo tempo de uma requisição
HTTP externa — que o `.env` permite chegar a 30 segundos por chamada. Numa importação de quinze
páginas isso seria uma transação de minutos, prendendo conexão do pool por causa de latência de
terceiro, e um timeout no meio deixaria o acervo pela metade dentro de uma transação viva.

**Consequência.** O generator do cliente continua existindo e continua certo — quem quiser
interromper cedo pode —, mas o `PortfolioService` escolhe não usar essa propriedade. A tensão
está anotada no código para ninguém "otimizar" de volta sem ler o motivo. Para 290 ARTs o custo
de memória é irrelevante; se um dia for relevante, a saída é lote por transação, nunca rede
dentro de transação.

Na mesma decisão entrou a ordem das duas chamadas de `associarArt`: `validarArt` antes de
`atividadesDaArt`, porque o endpoint de atividades não pede RNP e responde para qualquer número
válido. Invertida, alguém traria para o próprio portfólio o escopo de uma ART alheia.

---

## D19 · A herança de acervo pela empresa é binária, porque a API não datar ART não deixa alternativa

`08/09/2026` · E2 · commit a seguir · `_arq/estrutura.sql` (view `crea_evidencias`), `docs/matching.md`

**Contexto.** Três documentos nossos afirmavam que a empresa só herda a ART registrada **enquanto
o vínculo do profissional estava vigente**. É a regra correta no mundo real, e soa bem numa
apresentação. Ao escrever o `PortfolioService` fomos conferir os campos para implementá-la:
nenhum endpoint da API devolve data de ART. Nem `?p=profissionais/{rnp}/arts`, nem o CAO, nem
`?p=arts&rnp=&art_numero=`. Os campos são número, tipo, forma de registro, contratante, objeto,
local e situação — e só. Sem data de ART, `qut_dt_fim` não tem com o que ser comparado.

**Decisão.** A regra é binária e vale para o presente: vínculo com `qut_dt_fim IS NULL` herda o
acervo daquele profissional; vínculo encerrado não herda nada dele. Está implementada na view
`crea_evidencias` e em nenhum outro lugar. As três afirmações de recorte temporal foram
corrigidas em `endpoints.md`, `modelo-de-dados.md` e `matching.md`, e o comentário da view
passou a avisar por que não se deve tentar refinar.

**Alternativa recusada.** Inferir a data da ART. Havia dois caminhos e os dois foram descartados.
O primeiro, ler o ano embutido no número (`AM` **2026** `9999001`): daria só o ano, e nesta massa
as 290 ARTs são todas de 2026, então não separaria nada. O segundo, usar `cat_dt_emissao` da CAT
que agrupa a ART como limite superior — as CATs são a única parte da API que traz data útil — mas
seria um palpite apresentado como fato num índice de evidência, cobriria apenas ARTs certificadas
por CAT, e nesta massa todas as 100 CATs têm emissão em `2026-01-15`, o que empata todo mundo.
Escrever um filtro temporal sobre data inferida seria pior do que não ter filtro: daria a impressão
de rigor sem o rigor.

**Consequência.** Nenhuma linha de código muda — a view já era binária; o que estava errado era a
prosa, e ela estava prestes a virar código. A limitação passa a ser declarável em vez de
escondida, o que interessa para a declaração de limitações do item 12.3. E fica registrado o
padrão: quando um documento nosso e a API discordarem, a API ganha e o documento é corrigido com
a data — foi o mesmo caminho da D07 e da estrutura do CAO.

---

## D20 · API fora do ar não custa o cadastro: pendente é um estado, não um erro

`08/09/2026` · E2 · commit a seguir · `src/Service/PerfilCreaService.php`, `src/Controller/AuthController.php`

**Contexto.** O cadastro de Profissional passa a consultar o CREA. A API é de terceiro, o edital
não promete disponibilidade, e o Demo Day é presencial numa rede que não controlamos. Se a
consulta faz parte da transação de cadastro, uma oscilação de rede vira "não foi possível criar
sua conta" — e, na demonstração, vira o cenário 1 morrendo na frente da banca.

**Decisão.** Cadastro em dois passos, com a conta primeiro. `AutenticacaoService::cadastrar` cria
identidade, termos e consentimentos numa transação; só então `PerfilCreaService` consulta o CREA.
A consulta tem três desfechos, e nenhum deles desfaz a conta:

- **VINCULADO** — perfil montado, modalidades gravadas, acervo importado.
- **SEM_REGISTRO** (`200 []`) — CPF válido sem registro no CREA: a conta vira Terceiro PF, porque
  manter o perfil Profissional prometeria um registro que não existe.
- **API_INDISPONIVEL** — a conta fica **pendente de validação** e a pessoa é avisada.

A pendência não precisou de coluna: usuário com perfil PROFISSIONAL e **sem linha em
`pro_profissionais`** já é o estado pendente, porque `prf_rnp` é obrigatório e só a API o fornece.
E como nada é público por padrão e o motor só enxerga quem aparece em `crea_evidencias`, um perfil
pendente é invisível até ser validado — o desenho falha fechado sozinho.

**Alternativa recusada.** Duas. Falhar o cadastro e pedir para tentar de novo: mais simples, sem
estado parcial, mas perde a inscrição por causa de terceiro, e é o único desfecho irreversível da
tela. E tratar indisponibilidade como CPF não encontrado, rebaixando para Terceiro PF: reusaria
um caminho que já existe, mas confunde "esta pessoa não tem registro" com "não deu para verificar
agora" — o primeiro é fato, o segundo é temporário, e a pessoa teria de se recadastrar.

**Consequência.** O controller passa a orquestrar dois passos, e a segunda etapa **nunca lança**:
qualquer falha vira mensagem, porque devolver o formulário faria a pessoa tentar de novo com um
e-mail já cadastrado e concluir que o cadastro falhou quando ele deu certo. Fica devendo a tela de
"validar meu registro" para resolver a pendência — `vincularProfissional` já aceita ser chamada
sem CPF em claro, decifrando de `usu_documento_cif`, que é o mesmo caminho do
`sincronizar-status.php`.

Entrou junto uma guarda que a modelagem não tinha: `uq_prf_rnp` é global, então o `ON DUPLICATE
KEY UPDATE` do repositório reescreveria a linha de outra conta se dois usuários chegassem ao mesmo
RNP. Não deveria acontecer — CPF já é único —, mas o efeito seria o acervo de uma pessoa
respondendo por outra. Agora é recusa explícita com registro em auditoria. E `pro_profissionais`
ganhou `uq_prf_usu`: um usuário tem um CPF, logo um RNP, logo um perfil CREA.

---

## D21 · `prf_dt_sincronizacao` marca sucesso completo, não a gravação do perfil

`08/09/2026` · E2 · commit a seguir · `src/Repository/ProfissionalRepository.php`

**Contexto.** A validação do registro tem duas metades: consultar o CPF e importar o acervo. A
segunda pode falhar sozinha, e aí sobra um perfil com zero ARTs — que no banco é **idêntico** ao
de um profissional que genuinamente não tem ART nenhuma. Sabíamos da diferença no instante da
falha, e jogávamos a informação fora: virava mensagem na tela e sumia. Nem o botão de revalidar
nem o `sincronizar-status.php` teriam como saber que precisam tentar de novo. A pergunta que
expôs isso foi do Gabriel: "o problema é que não conseguimos saber quando ela cai durante?".

**Decisão.** O carimbo sai de `salvar()` e vira `marcarSincronizado()`, chamado só quando as duas
metades entram. `prf_dt_sincronizacao` passa a significar **última sincronização completa**: nula
é "nunca deu certo até o fim", e uma data velha é "deu certo até ali". Falha posterior preserva a
data anterior em vez de zerá-la — o que interessa é quando foi o último sucesso, que é
exatamente o que `api.sincronizacao.horas` compara.

**Alternativa recusada.** Uma coluna nova de estado, tipo `prf_validacao_pendente`. Seria mais
explícita e é o reflexo natural, mas duplica em booleano o que a data já diz, e coluna de estado
derivada de outra é o começo de duas fontes discordarem — o mesmo erro que a D01 evitou. A outra
alternativa, deixar como estava e recarimbar na próxima sincronização periódica, adiaria a
correção para um script que ainda não existe e deixaria o buraco aberto até a E7.

**Consequência.** Acervo pela metade continua não existindo, por causa da D18: `importarArts` lê
todas as páginas antes de abrir a transação, então ou entram todas as ARTs ou nenhuma. Somando as
duas decisões, os estados possíveis viraram quatro e todos são legíveis no banco: sem linha em
`pro_profissionais` (nem o CPF foi consultado), linha com carimbo nulo (perfil validado, acervo
não), linha com carimbo velho (sincronizou um dia, falhou depois) e linha com carimbo recente
(completo). É o que a tela de "validar meu registro" vai consultar.

---

## D22 · "Nada público por padrão" é a ausência de registro, e o filtro acontece antes do template

`08/09/2026` · E2 · commit a seguir · `src/Support/Visibilidade.php`, `src/Support/Visao.php`

**Contexto.** A RF03 e o item 11.3 exigem visibilidade granular, por campo do perfil e por ART, e
o backlog é explícito: **nada público por padrão**. Faltava decidir como essa afirmação vira
código — e onde o filtro é aplicado.

**Decisão.** Três coisas.

Primeira: **alvo sem linha em `pro_visibilidade` é privado.** O padrão é a ausência, não linhas
escritas no cadastro. A consequência que mais vale é que um campo novo, criado daqui a duas
semanas, nasce invisível sem ninguém lembrar de escrever migração — o desenho falha fechado por
construção, e não por disciplina de quem escreve a próxima tela.

Segunda: **o filtro acontece na montagem, não na renderização.** O controlador entrega ao template
só o que já passou pela `Visao`; o template não recebe o dado escondido e o esconde com `{% if %}`.
Um `if` esquecido não vaza o que nunca chegou até lá.

Terceira: **dois portões globais acima do controle por campo** — consentimento `EXIBICAO_PERFIL`
vigente e, para profissional, `prf_status_api = 'A'`. Qualquer um deles fechado esconde tudo,
inclusive o que estiver marcado como público. Mas nenhum dos dois apaga a escolha do titular: o
perfil reabre como estava.

**Alternativa recusada.** Escrever uma linha por campo no cadastro, todas em `PRIVADO`, que é o
que a D10 nos fez fazer com o consentimento. Lá a distinção entre "nunca concedido" e "concedido e
revogado" tem efeito jurídico — o titular precisa poder provar o que autorizou e quando. Aqui não
existe diferença nenhuma entre "nunca marquei" e "marquei privado": o resultado é idêntico,
ninguém vê. Guardar seis linhas por usuário para representar o padrão seria criar estado para
manter, e cada campo novo viraria uma migração que alguém esquece — trocando um desenho que falha
fechado por um que depende de memória.

Também foi recusado dar passe livre ao administrador. Quem administra modera denúncia e conteúdo
publicado; abrir o perfil fechado de alguém não é moderação, e a D06 já dizia que não há
superusuário implícito.

**Consequência.** O titular sempre enxerga o próprio dado, mesmo com o perfil fechado — senão
revogar a exibição o trancaria para fora do próprio cadastro. E a revogação de `EXIBICAO_PERFIL`
passou a fechar as linhas abertas, redundante com o portão de propósito: o portão decide o que a
tela mostra agora, o fechamento deixa o banco coerente com a decisão para quem consultar a tabela
sem passar pelo serviço.

Fica devendo o outro lado do portão do CREA: `prf_status_api` deixando de ser `'A'` numa
sincronização ainda não chama `fecharTudo`. A leitura já está correta, porque o portão é avaliado
a cada visão; o que falta é o banco acompanhar, e o lugar disso é o `sincronizar-status.php`.

---

## D23 · Identidade não é ocultável; quem não quer ser encontrado fecha o perfil inteiro

`08/09/2026` · E2 · commit a seguir · `src/Support/Visibilidade.php`, `src/Service/PerfilService.php`

**Contexto.** A visibilidade é campo a campo (D22), e a pergunta natural ao montar a tela foi:
quais campos entram na lista? A resposta preguiçosa é "todos", e ela parece a mais respeitosa com
o titular.

**Decisão.** `CAMPOS_DO_PERFIL` é lista fechada e **não inclui nome, RNP nem registro CREA**. Esses
três sempre acompanham o perfil quando ele está visível. O que o titular controla é contato,
resumo, modalidades, tipo de contrato e disponibilidade — mais cada ART, uma a uma.

**Alternativa recusada.** Deixar o nome e o registro ocultáveis como qualquer outro campo. Um
perfil sem nome e sem registro não identifica ninguém: o demandante veria um acervo de ARTs
pertencente a uma pessoa que ele não pode nomear nem conferir no CREA, o que é o oposto da tese
da plataforma — capacidade **comprovada**, e comprovação exige saber de quem. Pior, seria uma
falsa escolha: o titular acharia que está protegido enquanto suas ARTs, com número e local,
continuariam públicas e o identificariam de qualquer jeito.

**Consequência.** Quem não quer ser encontrado tem um caminho melhor e mais honesto: revogar
`EXIBICAO_PERFIL` no painel de privacidade, que fecha o perfil inteiro para todo mundo (D22). É
uma escolha só, com efeito total, em vez de seis escolhas com efeito parcial e enganoso. A lista
ser fechada também é defesa técnica: `deChave()` recusa campo fora dela, então um `name`
adulterado no formulário não cria linha de visibilidade para coluna nenhuma do banco.

---

## D24 · A empresa não tem acervo próprio: a ART do CAO é gravada sob o RNP de quem a registrou

`14/09/2026` · E2 · commit a seguir · `src/Service/PortfolioService.php` (`importarCao`),
`src/Support/Cao.php`, `src/Repository/AcervoRepository.php`

**Contexto.** A Certidão de Acervo Operacional é o documento **da empresa**, e a tela dela precisa
mostrar "este é o nosso acervo". A leitura mais direta do CAO é, portanto: as ARTs que vieram
naquela certidão pertencem àquela empresa, e é assim que se grava. Era preciso decidir isso antes
de escrever a primeira linha de `importarCao`, porque `crea_arts` tem uma coluna só para dono —
`art_pro_rnp` — e ela ia receber o registro da empresa ou o RNP do profissional.

**Decisão.** Recebe o RNP. Toda ART entra em `crea_arts` sob o RNP de quem a registrou, e a
empresa não aparece em lugar nenhum daquela tabela. A ligação entre as duas identidades é feita
exclusivamente pela view `crea_evidencias`, pelo vínculo vigente do quadro técnico (D19) — e o
`AcervoRepository::porEmpresa` lê a lista de ARTs **através da view**, em vez de repetir o JOIN,
para a regra continuar morando num lugar só.

**Alternativa recusada.** Gravar a ART sob o registro da empresa, que é como a certidão se
apresenta. Três problemas, e o primeiro é fatal: `uq_art_numero` é global, então a ART
AM20269999001 é uma linha só no banco — no dia em que a profissional que a registrou criasse
conta, a importação dela bateria de frente com a linha da empresa, e `Acervo::mesclar` levantaria
o conflito de RNP que existe justamente para impedir que evidência mude de dono. O segundo: a
mesma evidência contaria duas vezes no motor, uma para a empresa e uma para a pessoa, sem que
nada no banco dissesse que são a mesma obra. O terceiro é de honestidade — a empresa não registra
ART, ela responde por quem registrou, e um acervo que não diz de quem é apresenta como capacidade
própria o que é capacidade emprestada. É a confusão que a plataforma existe para desfazer.

**Consequência.** Sai de graça o efeito que o cenário exige: encerrar um vínculo tira o acervo
daquele profissional da empresa, inteiro e na hora, sem apagar nada e sem tocar no perfil dele —
verificado em `verificar-e2.php`. Sai de graça também a mescla nos dois sentidos: o CAO não traz
local, contratante nem forma de registro, e importar o CAO depois da importação do profissional
(ou antes) não apaga o que o outro caminho trouxe, porque a regra "valor novo vence, exceto
quando é nulo" já estava lá. Em troca, a tela da empresa tem uma obrigação a mais: cada ART
aparece com o nome e o RNP de quem a registrou. Foi preciso uma coluna nova, `qut_pro_nome`, para
isso não virar uma lista de RNPs sem nome.

---

## D25 · O quadro técnico vem do endpoint próprio, e não do CAO, por causa de um campo só

`14/09/2026` · E2 · commit a seguir · `src/Repository/QuadroTecnicoRepository.php`,
`src/Service/EmpresaCreaService.php`

**Contexto.** Validar uma empresa custa chamadas à API, e o item 10.4 do edital registra cada uma.
O CAO devolve o quadro técnico junto com o acervo, numa resposta só: `quadro_tecnico` traz
`pro_nome`, `pro_rnp`, `pro_registro_crea` e `qut_funcao` de cada profissional. Usá-lo para as
duas coisas economizaria uma requisição por empresa, e a lista parece a mesma.

**Decisão.** Não é a mesma, e são duas chamadas: `?p=empresas/{registro}/quadro-tecnico` monta
`crea_quadro_tecnico`, `?p=empresas/{registro}/cao` traz o acervo. O motivo é um campo:
**o CAO não devolve `qut_dt_fim`** (nem `qut_dt_inicio`, nem `qut_tipo`). Uma empresa custa três
chamadas no total, contando a busca pelo CNPJ, e `verificar-e2.php` afirma esse número.

**Alternativa recusada.** Montar o quadro técnico pelo CAO e economizar a chamada. Sem
`qut_dt_fim`, todo vínculo seria gravado como vigente — inclusive os encerrados. E `qut_dt_fim IS
NULL` **é** a regra de herança inteira (D19): a economia de uma requisição compraria um banco em
que toda empresa herda o acervo de todo mundo que um dia respondeu por ela. O erro seria
silencioso, porque a tela ficaria plausível: mais acervo, nenhum aviso. `CaoTest` trava os dois
fatos contra as fixtures — o CAO não tem a chave, o outro endpoint tem — para que uma
recaptura que mude o formato apareça em `composer test` e não na demonstração.

**Consequência.** A sincronização da empresa tem três passos e não dois, e o carimbo
`emp_dt_sincronizacao` só sai quando os três entram (mesma regra da D21). Ficou também uma
armadilha de banco documentada: `uq_qut_emp_pro` inclui `qut_dt_inicio`, que aceita nulo, e NULL
não colide em índice UNIQUE no MariaDB — um `ON DUPLICATE KEY UPDATE` ingênuo duplicaria o
vínculo a cada sincronização de uma empresa sem data de início. A gravação procura antes, com
`<=>`, e decide entre UPDATE e INSERT sem depender do índice.

---

## D26 · A API não devolve situação de empresa, e a plataforma declara isso em vez de inventar

`14/09/2026` · E2 · commit a seguir · `src/Service/VisibilidadeService.php`,
`src/Service/EmpresaCreaService.php`

**Contexto.** Do lado do profissional, `prf_status_api != 'A'` zera a visibilidade: registro
suspenso no CREA fecha o perfil sem ninguém precisar agir, e a proposta promete isso com essas
palavras. Ao escrever o portão equivalente para a empresa fomos buscar o campo e ele não existe:
`?p=empresas&cnpj=` devolve `emp_cnpj`, `emp_razao_social`, `emp_nome_fantasia`,
`emp_registro_crea` e `emp_dt_registro`, e mais nada. Não há `emp_status` em endpoint nenhum.

**Decisão.** O portão global da empresa é o consentimento `EXIBICAO_PERFIL` mais a validação
pendente da D20 — sem linha em `pro_empresas`, o perfil fica fechado. Não existe um terceiro
fator, e a limitação é declarada no código, aqui e na seção de limitações do item 12.3, em vez de
ser dissimulada.

**Alternativa recusada.** Derivar uma situação. Havia dois caminhos e os dois fabricariam
informação: tratar a ausência do registro numa reconsulta como suspensão — mas a API responde
`200 []` tanto para "não tem registro" quanto para "a chave não casou", e uma indisponibilidade
mal classificada fecharia o perfil de uma empresa regular sem aviso e sem recurso; ou herdar a
situação do responsável técnico, o que é pior, porque puniria a empresa por um fato da pessoa e
inventaria uma regra que o conselho não tem. Mesmo padrão da D19: quando a API não dá o campo, a
resposta é declarar a limitação, não estimá-la.

**Consequência.** Uma empresa cujo registro fique irregular no CREA continua visível até alguém
agir — o `sincronizar-status.php` não tem o que reconsultar para ela. Dá para mitigar quando
houver denúncia (E6) ou pelo painel administrativo, e é isso que a declaração de limitações vai
dizer. Em compensação, o cadastro da empresa ganhou de graça o resto da simetria com o
profissional: os mesmos três desfechos, agora num vocabulário compartilhado
(`Support\DesfechoCrea`), a mesma guarda de registro já vinculado a outra conta, e a mesma
regra de que a API fora do ar nunca custa o cadastro.

---

## D27 · O formulário de visibilidade só aceita de volta os alvos que ele mesmo desenhou (revisa a D22)

`14/09/2026` · E2 · commit a seguir · `src/Support/Visibilidade.php` (`lote`),
`src/Service/VisibilidadeService.php` (`definirLote`), `src/Controller/PerfilController.php`

**Contexto.** A D22 estabeleceu que o formulário manda `nivel[<chave do alvo>]` e que chave
adulterada é ignorada em silêncio, porque `deChave()` recusa entidade e campo fora das listas
fechadas. Ao estender a mesma tela para a empresa fomos conferir o comportamento com um POST
forjado, e a defesa não cobria o que parecia cobrir: `deChave()` valida a **forma** da chave, não
a **posse** do alvo. `ART:999999:-` tem forma válida. Uma função sem banco não tem como saber de
quem é a ART 999999 — e não deveria ter.

Na sonda, um POST com `nivel[ART:999999:-]`, `nivel[PERFIL:-:MODALIDADES]` (campo que a tela da
empresa não desenha) e um nível inventado no fim gravou **duas linhas** em `pro_visibilidade`,
com duas linhas em `sis_auditoria`, e só então abortou com mensagem de erro na tela.

**Decisão.** Duas correções, e a segunda foi achada junto com a primeira.

A tela passa a dizer quais alvos são legítimos. O controlador remonta o perfil no POST só para
extrair as chaves que a montagem desenhou (`perfil.niveis`) e as entrega como lista permitida;
`Visibilidade::lote()` descarta tudo o que não estiver nela. É a mesma lista que gerou os
controles, então não há regra nova a manter nem consulta nova por alvo.

E o lote passa a ser tudo ou nada: os níveis são validados **antes** de qualquer escrita, e um
nível inválido recusa o formulário inteiro sem gravar nada.

**Alternativa recusada.** Para a posse do alvo, consultar o banco por ART dentro do laço —
"esta ART é do titular?". Funciona, mas coloca a regra de quais alvos existem num segundo lugar,
que precisaria ser mantido em sincronia com o que cada tela desenha: hoje profissional e empresa
já montam listas diferentes, e o dia em que a terceira tela (CATs, experiências) aparecesse,
alguém esqueceria de ensinar a consulta sobre ela — falhando **aberto**. Derivar a lista da
própria montagem falha fechado por construção: alvo que a tela não desenha não é aceito, sem
ninguém precisar lembrar.

Para o lote parcial, a alternativa era abrir uma transação em volta do laço. Daria atomicidade,
mas pelo preço errado: manteria uma requisição adulterada capaz de derrubar a operação inteira e
gastaria transação para resolver o que é validação de entrada. Validar antes de escrever é mais
barato e diz a verdade na mensagem.

**Consequência.** Nenhuma das duas falhas vazava dado — a `Visao` consultada numa tela é sempre a
do dono **daquele** perfil, e nenhuma listagem de ARTs sai de `pro_visibilidade` —, então o
impacto era escrita inútil, ruído na trilha de auditoria e uma escolha "PÚBLICO" esperando por uma
ART que ainda ia chegar, o que contraria o "nada público por padrão" da D22 em espírito. Depois da
correção a mesma sonda grava zero linhas, e o POST legítimo continua funcionando nas duas telas.
O custo é uma montagem de perfil a mais por POST de visibilidade, que é a mesma consulta que o
GET seguinte faria de qualquer jeito.

Fica registrado o método, que vale para o endurecimento da E7: a defesa foi conferida com uma
requisição forjada de verdade, não por leitura do código. As duas falhas estavam na tela do
profissional desde a D22 e passaram por uma revisão sem serem vistas.

---

## D28 · A unicidade do alvo de visibilidade é do repositório, porque o índice não a garante (revisa a D22)

`14/09/2026` · E2 · commit a seguir · `src/Repository/VisibilidadeRepository.php`,
`_arq/estrutura.sql` (`uq_vis_alvo`)

**Contexto.** Ao escrever a verificação da experiência declarada, uma conferência falhou de um
jeito impossível: abrir uma ART que estava fechada não surtia efeito. A investigação encontrou
`pro_visibilidade` com **seis linhas para o mesmo alvo** — o mesmo titular, a mesma ART, níveis
diferentes.

A causa é a mesma armadilha que a D25 já tinha documentado do outro lado do sistema.
`uq_vis_alvo` cobre (usuário, entidade, entidade_id, campo), e as duas últimas colunas aceitam
nulo por desenho: o alvo `ART:5` não tem campo, o alvo `PERFIL:EMAIL` não tem id. **Em MariaDB
duas linhas com NULL na mesma coluna não violam UNIQUE**, então o `ON DUPLICATE KEY UPDATE` do
repositório nunca casava para alvo nenhum desta tabela — todos têm pelo menos uma coluna nula.
Cada clique inseria linha nova.

O efeito não era acumular linhas, que seria só feio. `nivel()` devolvia uma das duplicatas, o
serviço comparava a escolha nova contra um valor antigo, concluía "não mudou" e **descartava a
alteração** — devolvendo "Visibilidade atualizada" ao titular. Numa tela de privacidade, a
plataforma dizia ter obedecido e não tinha.

**Decisão.** A unicidade do alvo passa a ser garantida pelo repositório: `definir()` procura a
linha com `<=>` (igualdade null-safe, que funciona onde o índice não funciona) e decide entre
UPDATE e INSERT. `nivel()` e `mapaDoUsuario()` ganharam ordem explícita, para que uma base que
já tenha duplicatas leia a escolha mais recente em vez de sortear. O índice permanece — cobre de
graça os alvos sem nulo —, e o comentário dele em `estrutura.sql` passa a dizer a verdade sobre o
que ele não garante.

**Alternativa recusada.** Fazer o banco garantir de verdade, com uma coluna gerada persistente
(`CONCAT(entidade, ':', IFNULL(id,'-'), ':', IFNULL(campo,'-'))`) dentro do índice único. É mais
sólido — o banco passaria a recusar a duplicata em vez de depender de todo mundo usar o
repositório — e foi recusada por duas razões. A primeira é que colocaria o formato de
`Visibilidade::chave()` também em SQL, criando dois lugares para um fato só, exatamente o que o
princípio de manutenção do `CLAUDE.md` proíbe; no dia em que a chave mudasse de formato, o índice
discordaria em silêncio. A segunda é a data: faltam três dias para a entrega, e trocar o esquema
de uma tabela de privacidade exige migração em toda máquina da equipe. Fica anotado como melhoria
pós-entrega.

**Consequência.** As duplicatas já gravadas precisam sair das bases existentes — o `DELETE` está
em `docs/estado.md`, junto com os `ALTER` pendentes. E fica a lição, que já custou duas vezes:
**neste projeto, `ON DUPLICATE KEY UPDATE` só é confiável quando nenhuma coluna do índice aceita
nulo.** Vale para `uq_vis_alvo` e para `uq_qut_emp_pro`, e é a primeira coisa a conferir no
próximo índice com coluna opcional.

Também fica registrado como o defeito apareceu: não numa revisão de código — ele sobreviveu à
revisão que escreveu a D27, na mesma tabela —, mas numa conferência de comportamento que falhou
por um motivo que não fazia sentido. Falha inexplicável é sintoma, não ruído.

---

## D29 · Experiência declarada não empresta evidência alheia, nem reabre o que o titular fechou

`14/09/2026` · E2 · commit a seguir · `src/Service/ExperienciaService.php`,
`src/Service/PerfilService.php`

**Contexto.** A experiência autodeclarada é, por definição, o que a plataforma **não** verifica —
o edital pede que ela exista "com ou sem ART/CAT" (Anexo I, item 3), e quem trabalhou sem registro
precisa caber. A tentação é tratar o bloco inteiro como texto livre e não conferir nada. Mas o
campo `exp_art_id` muda a natureza do que está sendo dito: deixa de ser "eu afirmo que fiz" e
passa a ser "e o documento X do CREA sustenta isso".

**Decisão.** Duas conferências, e só duas.

A ART informada é conferida contra o acervo do próprio profissional. A chave estrangeira do banco
não serve: ela garante que a ART **existe**, e existir não é pertencer. Sem a conferência, o
bloco de dado declarado viraria o caminho fácil para pendurar no próprio perfil a evidência de
outra pessoa — justamente o que a plataforma existe para tornar impossível.

E o número da ART só viaja dentro da experiência se **aquela ART** também estiver visível para
aquele espectador. Uma ART fechada não reaparece escrita dentro de uma experiência aberta.

**Alternativa recusada.** Para a segunda, deixar o número aparecer sempre, com o argumento de que
o titular escolheu abrir a experiência e o número é parte do texto dela. É plausível e está
errado: as duas escolhas são sobre coisas diferentes, e quem fecha uma ART está dizendo "não
quero que saibam deste trabalho", não "não quero que este trabalho apareça nesta lista
específica". Respeitar a escolha só na lista e furá-la no parágrafo ao lado seria uma falsa
escolha — o mesmo erro que a D23 recusou ao não deixar o nome ser ocultável.

**Consequência.** Experiência e acervo saem da montagem como duas listas separadas, e nenhuma
experiência carrega `selo_confere` — não há o que selar, e uma chave com valor `null` ali
convidaria algum template futuro a desenhar um selo cinza, que é pior do que nenhum. A separação
continua no CSS, com `.dado-declarado` e `.selo-art` em cores diferentes desde antes desta etapa.

Fica também um efeito colateral útil: a conferência de posse da ART foi o que expôs um defeito no
próprio formulário. O `<select>` de ARTs estava sendo montado com `merge` no Twig, que é
`array_merge` em PHP e **renumera chaves inteiras** — os `value` saíam 0, 1, 2, 3 no lugar dos
`art_id` reais 1, 2, 3, 4, e cada opção mandaria de volta o id da ART anterior, com o rótulo certo
na tela. Sem a conferência, o vínculo errado teria sido gravado em silêncio. O `<select>` passou a
ser escrito à mão, com o porquê no template.

---

## D30 · Dependência com `new` no valor padrão, e a cobertura que isso custa

`08/09/2026` · E1 · resgatada em 14/09 · commit a seguir · todos os construtores de `src/Service/` e `src/Controller/`

**Contexto.** Treze construtores declaram as dependências assim:

```php
public function __construct(
    private readonly UsuarioRepository $usuarios = new UsuarioRepository(),
) {}
```

O parâmetro existe, então em teoria dá para injetar outra coisa. Na prática o valor padrão
constrói o objeto real, e todo repositório chama `Database::conexao()` no construtor. **Logo
nenhum serviço pode ser instanciado sem banco de pé** — e é por isso que `AutenticacaoService` e
`PrivacidadeService` não têm teste unitário nenhum. A cobertura deles é zero; quem os exercita é
`scripts/verificar-e1.php`, por HTTP.

**Decisão.** Fica assim. A injeção real vem de dois lugares quando importa: `Repositorio` aceita
um `PDO`, que é o que permite uma operação atômica inteira rodar na mesma transação, e as
dependências são parâmetros, então um teste que queira um duplo pode passá-lo.

**Alternativa recusada.** Um contêiner de injeção, ou fábricas explícitas, para que serviço se
construa com duplos e ganhe teste unitário de verdade. É o desenho correto e custa tempo que não
existe entre 08 e 17/09 — e, mais importante, compraria um tipo de teste que não cobre o que mais
quebra aqui. Os três bugs reais desta etapa foram parâmetro nomeado repetido no SQL, variável sem
`default` no Twig com `strict_variables`, e ordem de avaliação de argumento em PHP. **Teste com
duplo de repositório não pega nenhum dos três**, porque os três estão exatamente na fronteira que
o duplo substitui. O script por HTTP pegou todos.

**Consequência.** Se a banca perguntar por que não há teste de `AutenticacaoService`, a resposta é
esta entrada, e o contraponto é o `verificar-e1.php`. Também é o que torna o contêiner a primeira
coisa a fazer se este projeto virar produto depois do desafio — aí o prazo deixa de ser argumento.

---

## D31 · Auditoria da própria E1: as regras do CLAUDE.md passaram a valer no código

`08/09/2026` · E1 · resgatada em 14/09 · commit a seguir · `src/Repository/AuditoriaRepository.php`, `src/Support/Sessao.php`

**Contexto.** Revisão da E1 inteira encontrou duas regras escritas pelo projeto e desobedecidas
pelo projeto. O `CLAUDE.md` diz "só repositórios escrevem SQL, nenhum controller toca o banco", e
`SaudeController` fazia `SELECT COUNT(*) FROM crea_tos` direto, enquanto `Support\Auditoria` fazia
o `INSERT` da trilha. A `Sessao` diz no próprio docblock que existe para nenhum outro lugar tocar
`$_SESSION`, e três arquivos chamavam `session_start()` por fora.

Nenhuma das duas era bug: tudo funcionava. O problema é de outra natureza — um avaliador lê a
regra na documentação, dá um `grep`, e encontra a contradição em dez segundos.

**Decisão.** Nascem `AuditoriaRepository` e `SaudeRepository`, e `Support\Auditoria` passa a
delegar o SQL continuando a ser o caminho único de escrita para quem chama. Nasce
`Sessao::reiniciar()`, usada nos três pontos que abriam sessão à mão. O front controller passa a
consultar `SessaoRepository` direto em vez de construir `AutenticacaoService` — que montava sete
objetos, o serviço de e-mail incluído, para responder se a sessão vale.

**Alternativa recusada.** Afrouxar as regras no `CLAUDE.md` para descrever o que o código fazia. É
tentador porque é mais rápido, e é o caminho para um documento que ninguém respeita: regra que
cede ao primeiro atrito deixa de ser regra. As duas exceções existiam por ordem histórica —
`Auditoria` e `SaudeController` foram escritos antes de `src/Repository/` existir — e ordem
histórica não é justificativa depois que a camada existe.

**Consequência.** Toda query da aplicação está em `src/Repository/`, e `grep` por `session_start`
só acha `Sessao.php`. No caminho saíram também: `FINALIDADE_ACEITE_TERMOS`, que nunca era o valor
gravado e só servia de prefixo concatenado; quatro métodos declarando `: string` sem nunca
retornar; duas variáveis de ambiente lidas pelo `_config.php` e ausentes do `.env.example`, o que
o item 8.3.1 do edital não perdoaria; e um dígito a mais do que o necessário na máscara de CPF,
agora travado por teste. Administrador deixou de poder excluir a própria conta pelo painel do
titular — se fosse o único, a plataforma perderia moderação até alguém rodar `criar-admin.php` no
servidor.
## D32 · O visual é um design system próprio sobre o Bootstrap, não o tema padrão

`14/09/2026` · Front · commit a seguir · regra em [`design.md`](design.md); telas em `mockups/`

**Contexto.** O edital exige Bootstrap 5 no front. O Bootstrap puro entrega um visual genérico, e a
experiência de uso é critério de nota (Anexo I: funcionalidade e experiência, 20 pontos, o maior
peso). Do lado do design a equipe já tinha um sistema próprio pronto — sidebar, tokens, selo,
tipografia — desenhado nas sete telas de `mockups/`.

**Decisão.** O Bootstrap fica como base (grid, utilitários, JS de componentes, macros de
formulário) e o nosso design entra como tema por cima: sobrescreve as variáveis `--bs-*` e adiciona
os componentes que faltam. CSS3 também é exigência do edital, então tema próprio está em
conformidade.

**Alternativa recusada.** Duas. Bootstrap puro — atende a letra do edital mas joga fora o
diferencial de UX. E CSS 100% custom sem Bootstrap — o nosso mockup original é assim, mas remover o
Bootstrap fere a exigência de tecnologia. O meio-termo usa as duas coisas de verdade.

**Consequência.** Os componentes nossos levam prefixo `pl-` porque `.btn`, `.card`, `.nav`, `.pill`
e `table` são do Bootstrap: sem o prefixo, o nosso CSS quebraria toda tela já feita (auth, perfil,
admin). As três classes que já existiam (`.selo-art`, `.dado-declarado`, `.perfil-em-construcao`)
mantêm o nome e trocam só a aparência.

---

## D33 · Verificado e não-verificado viram símbolo — verde água e círculo cinza com X, não verde/amarelo

`14/09/2026` · Front · commit a seguir · regra em [`design.md`](design.md); revisita o CSS da fundação

**Contexto.** Que dado verificado pela API nunca se confunda com dado autodeclarado é o compromisso
central da proposta, e o `prolink.css` da fundação já o traduzia: selo verde, tarja amarela. Ao
trazer o design system a equipe reviu a execução dessa distinção.

**Decisão.** O princípio fica; a execução muda. Verificação passa a ser um símbolo inline, à direita
do texto: selo chanfrado verde água (`--pl-seal`) para o verificado, círculo liso cinza com um X
para o não verificado. Sem tarja de texto, sem coluna separada, sem amarelo.

**Alternativa recusada.** Manter verde/amarelo. O amarelo entrava em conflito com o laranja do CTA
(dois avisos quentes competindo) e a tarja de texto poluía a leitura — testado nas telas e rejeitado.

**Consequência.** O verde água vira cor de papel único: só verificação, nada mais usa. O edital não
obriga cor nenhuma para isso (conferido em `edital-requisitos.md`), então é escolha de design
legítima. Afeta código do parceiro (`perfil/index`, `perfil/empresa`, `privacidade`) — combinar
antes de aplicar.

---

## D34 · Uma única cor de ação preenchida; secundário e dado em azul escuro

`14/09/2026` · Front · commit a seguir · regra em [`design.md`](design.md)

**Contexto.** Com laranja (CTA), azul e um teal para dados havia três tratamentos "clicáveis"
competindo pela atenção, e não ficava claro qual era a ação principal.

**Decisão.** Laranja é a única cor de ação preenchida (a que compromete: publicar, manifestar).
Botão secundário é azul escuro contornado; barra de dado é azul escuro preenchido; link é azul
brilhante; verde água é só verificação. Cada cor, um papel.

**Alternativa recusada.** Usar azul petróleo/teal para dados e botões — confundia com o verde água
do selo, que é vizinho na roda de cor.

**Consequência.** Hierarquia de ação sem ambiguidade; a regra 60/30/10 fica explícita no
`design.md`.

---

## D35 · Fundo neutro off-white quente, não cinza-azulado frio

`14/09/2026` · Front · commit a seguir · regra em [`design.md`](design.md)

**Contexto.** O cinza-azulado frio dá um ar corporativo e transacional; a plataforma lida com
pessoas e reputação técnica.

**Decisão.** Neutro off-white quente e claro (`--pl-page`/`--pl-app`), com cards brancos.

**Alternativa recusada.** O cinza-azulado frio original — mais duro, menos "de pessoas".

**Consequência.** Card precisa de fundo branco explícito: como `background` não é herdado em CSS,
um quadro sem fundo deixa o off-white vazar e parece sujo. Regra registrada no `design.md`.

---

## D36 · Selo é marca inline e a linha de tabela tem uma ação só

`14/09/2026` · Front · commit a seguir · regra em [`design.md`](design.md)

**Contexto.** As primeiras telas tinham tarjas com texto ("ART verificada") e várias ações por
linha de tabela, o que poluía e competia visualmente.

**Decisão.** Verificação é só o símbolo colado à direita do texto (na tabela, ao lado do
identificador). A linha de tabela tem uma ação — "Ver" — e as demais moram na página de detalhe,
atrás de um menu de três pontos.

**Alternativa recusada.** Tarjas rotuladas e duas ou mais ações por linha — é o padrão fácil, mas
enche a tela de ruído e repete o mesmo rótulo em cada linha.

**Consequência.** Telas mais limpas e uma leitura mais rápida; o detalhe passa a ser o lugar das
ações menos frequentes.

---

## D37 · Perfil sem nenhuma ART fica fora do feed, e isso é declarado em vez de contornado

`14/09/2026` · Front · commit a seguir · limite descrito em [`fluxos.md`](fluxos.md); marca em [`design.md`](design.md)

**Contexto.** A proposta promete que o perfil em construção **nunca sai do pool**, como mitigação
de viés contra quem está começando. Ao desenhar a marca de perfil em construção apareceu o caso
que a promessa não cobre: o profissional com **zero** ARTs. O motor cruza a demanda com
`crea_evidencias`, e evidência vem de ART. Com zero ARTs não há o que cruzar: a dimensão de
competência é nula, o score fica abaixo do limiar e o perfil não entra em pool nenhum. A promessa
vale para quem tem uma ou duas ARTs; para quem tem zero, é falsa.

**Decisão.** Declarar o limite em vez de contorná-lo. O perfil sem ART não entra no feed de
recomendação e continua encontrável na **busca ativa** por nome e modalidade, que vêm do cadastro
validado na API e não dependem de acervo. O texto entra na declaração de uso de IA, vieses e
limitações da entrega (12.3 e Anexo VI), junto das outras limitações já previstas para a E7.

**Alternativa recusada.** Deixar o perfil sem ART entrar no pool com aderência baixa, para honrar
a promessa ao pé da letra. Seria recomendar à empresa alguém sobre quem a plataforma não tem
nenhuma evidência, contra a tese do projeto: capacidade **comprovada**, não autodeclarada. O
remédio seria pior que a limitação, e a primeira pergunta da banca seria por que um perfil vazio
aparece num feed que se diz baseado em evidência documental.

**Consequência.** A frase "nunca sai do pool" passa a valer com a qualificação "desde que exista
pelo menos uma ART", e é assim que deve ser dita na demonstração. A tela de estado vazio do acervo
("nenhuma ART registrada ainda") fica para depois da entrega: não está em nenhum dos seis cenários
e não paga o custo agora. Quem tem zero ART já enxerga o caminho pelo botão de associar ART que a
tela de portfólio oferece.

---

## D38 · Erro de integridade não conta como o sistema descobriu

`14/09/2026` · Front · commit a seguir · tela em `mockups/`; regra em [`design.md`](design.md)

**Contexto.** A tela de selo divergente trazia, abaixo da mensagem, uma linha técnica com o
identificador do evento de auditoria e a descrição do mecanismo ("hash gravado x HMAC
recalculado"). A intenção era provar que a trilha existe.

**Decisão.** A mensagem ao usuário diz o que aconteceu, o que o sistema fez e o que ele pode
fazer, e para por aí. O identificador do evento e o mecanismo de verificação ficam na trilha de
auditoria, visível para a administração. A tela do usuário apenas menciona que o detalhe está lá.

**Alternativa recusada.** Manter o identificador visível para dar rastreabilidade a quem abre
suporte. Não compensa: a linha revelava que a verificação é por HMAC, que a comparação é entre
hash gravado e recalculado, e um identificador sequencial que permite inferir volume e enumerar
eventos. É divulgação de informação em mensagem de erro (CWE-209), e num recurso cuja função é
justamente resistir a adulteração.

**Consequência.** Vale como regra para as telas de erro que vierem: mensagem de falha de
integridade ou de autenticação descreve o efeito, nunca o mecanismo nem o identificador interno.
Entra na autoavaliação do Anexo VI na E7.

---

## D39 · O bloqueio administrativo tem efeito imediato sem tocar em `Sessao`

`14/09/2026` · E6 · commit a seguir · `public/index.php` (88-95), `AutenticacaoService::sessaoTemRespaldo`,
`SessaoRepository::revogarTodasDoUsuario`

**Contexto.** A operação atômica 5 exige que bloquear uma conta tire o acesso de quem já está
logado. A E7 do `backlog.md` afirmava que isso não era possível hoje: `Sessao::autenticar()` copia
id, nome e perfil para `$_SESSION` e nunca reconfere contra o banco, então o bloqueio "só valeria no
próximo login". Se fosse verdade, ou a operação atômica 5 não fecharia, ou seria preciso mexer em
autenticação a três dias da entrega, com RF01 e RF02 completos e verificados em cima.

**Decisão.** Bloquear é `usu_status = 'I'` mais revogar todas as sessões do usuário, e nada além
disso. Nenhuma linha de `Sessao` muda.

A afirmação do backlog estava errada, e a medição mostrou por quê: o front controller chama
`sessaoTemRespaldo()` em **toda** requisição autenticada, e esse método procura a linha em
`sis_sessoes` e devolve falso quando ela está revogada. O caminho já existia; faltava alguém ligar
os dois fatos. Conferido com requisição real, não por leitura: sessão viva em `/privacidade`
respondendo 200; depois de `usu_status = 'I'` e `ses_dt_revogacao = NOW()`, a mesma sessão recebeu
303 para `/login`. Novo login com a conta bloqueada é recusado pela mensagem única de credencial
inválida, porque `UsuarioRepository::porEmail()` filtra `usu_status = :ativo`.

**Alternativa recusada.** Fazer `Sessao` reconferir o usuário no banco a cada requisição. Custaria
uma consulta em toda requisição autenticada e código novo no caminho crítico de autenticação, para
comprar um efeito que já existe. A terceira alternativa, adiar o efeito imediato para a E7 e exibir
"vale a partir do próximo acesso", ficou dispensada pelo mesmo motivo, e teria enfraquecido o
cenário 6 na demonstração, onde a parte convincente é o usuário caindo na hora.

**Consequência.** A operação atômica 5 fecha hoje sem risco para RF01 e RF02, que seguem em 140
testes e 108 verificações do portfólio. Fica uma limitação declarada, e ela é estreita: **troca de
papel** em sessão aberta continua valendo só no próximo login, porque o perfil é lido de
`$_SESSION`. Isso afeta o rebaixamento para Terceiro da D20 e da D26, não o bloqueio. O edital pede
controle de perfis de acesso (8.5) e moderação (RF06), não reflexo imediato de mudança de papel.
Fica registrado também o método: a afirmação do backlog tinha três meses de vida e nunca havia sido
medida.

---

## D40 · Um relógio só: o fuso do MariaDB segue o do PHP na conexão

`14/09/2026` · E6 · commit a seguir · `Support\Database::conexao()`

**Contexto.** O contêiner do MariaDB roda em UTC e o do PHP em `America/Manaus`, quatro horas de
diferença. Isso não era um detalhe de exibição: as duas horas iam para a **mesma coluna** conforme
o caminho do código. A sessão nasce com `DATE_ADD(NOW(), ...)` em `SessaoRepository` e o bloqueio
por tentativas com `date()` em `AutenticacaoService`, e os dois convivem em `sis_usuarios`. A
trilha de auditoria exibia o dia seguinte às 20h, e o corte do filtro de período comparava um
`date()` local com uma coluna gravada em UTC.

**Decisão.** `Database::conexao()` executa `SET time_zone` com o deslocamento do próprio fuso do
PHP, lido de `(new DateTimeImmutable())->format('P')`. Um ponto, e os dois relógios passam a ser o
mesmo. O deslocamento é derivado em vez de escrito à mão justamente para que mudar o fuso do PHP
não recrie a divergência. Nome de fuso (`'America/Manaus'`) exigiria as tabelas de fuso carregadas
no MariaDB, que a imagem não traz.

**Alternativa recusada.** Manter o banco em UTC e converter na apresentação, com um filtro Twig.
É a prática recomendada em sistema multi-fuso, e foi recusada por duas razões: não conserta a
comparação entre colunas gravadas por caminhos diferentes, que é o defeito de verdade, e obrigaria
cada tela nova a lembrar do filtro. A desvantagem clássica de gravar em hora local não se aplica
aqui: o sistema é do CREA-AM, e o Amazonas não tem horário de verão desde 2008. Também foi
recusado converter só na tela de auditoria: `denuncias.html.twig` tem o mesmo defeito, e o painel
passaria a se contradizer, com a denúncia recebida às 20:19 e moderada às 16:19.

**Consequência.** Registro novo grava a hora real, medido com PHP e `NOW()` no mesmo segundo. O
histórico anterior continua em UTC e **não tem conserto**: `sis_auditoria` bloqueia UPDATE e DELETE
por trigger (D04). Some quando o banco for recarregado para a demonstração, o que também limpa a
massa de verificação. Some junto o rótulo "horários em UTC" que a tela declarava enquanto a hora
exibida não era a local.

---

## D41 · Identificador de sistema não chega à tela

`14/09/2026` · E6 · commit a seguir · `Support\Rotulos`, filtros em `Support\View`

**Contexto.** O painel de auditoria lê constantes e nomes de esquema direto do banco, e eles
chegavam crus ao usuário: o filtro de ação listava `ACESSO_NEGADO`, `BLOQUEIO_LOGIN`,
`SELO_DIVERGENTE`; a coluna Entidade mostrava `pro_denuncias`; a coluna Campo mostrava
`usu_status`. Além de feio, é vazamento gratuito da forma interna para quem não precisa dela.

**Decisão.** Três mapas em `Support\Rotulos`, expostos como filtros Twig `rotulo_acao`,
`rotulo_entidade` e `rotulo_campo`. Chave desconhecida cai num fallback por convenção (prefixo de
tabela fora, underline vira espaço, primeira maiúscula) em vez de sumir ou estourar: ação nova
entra em `Support\Auditoria` e a tela continua legível antes de alguém lembrar de vir aqui. O
valor cru fica em `title=""`, porque quem audita de verdade quer o identificador exato.

**Alternativa recusada.** Traduzir no controller. A regra é de apresentação e vale em qualquer
template que toque auditoria, não só no painel; no controller, a segunda tela repetiria o mapa. E
mapa no próprio template, que é o padrão que `denuncias.html.twig` já usava, foi mantido só para o
vocabulário de **valor gravado** (`'A'`, `'PENDENTE'`), que é específico da tela; o que é
vocabulário do sistema subiu para `Rotulos`.

**Consequência.** As quatro finalidades de consentimento entraram no mapa de campos, porque em
`sis_consentimentos` o `aud_campo` guarda a finalidade e não o nome de uma coluna, e o fallback
devolvia "Consulta api", sem acento e sem a sigla. Fica um débito conhecido: as ~32 chaves do JSON
de contexto ainda moram em `auditoria.html.twig`; se virarem vocabulário de mais de uma tela, o
lugar delas é aqui.

---

## D42 · O padrão visual vira trava no repositório, não disciplina de quem revisa

`14/09/2026` · Front · commit a seguir · `.claude/agents/designer-ui.md`,
`scripts/verificar-padrao.php`, `scripts/hook-padrao.sh`, `CLAUDE.md`

**Contexto.** Toda vez que uma tela foi escrita junto com o backend, saiu no padrão de dump de
dados e teve de ser refeita depois da reclamação. A régua existia e estava combinada; o que
faltava era ela valer sem alguém lembrar. A tela de auditoria fechou com 16 verificações no verde
e subiu em 500 no navegador, porque nenhuma delas tocava a camada de apresentação.

**Decisão.** Quatro peças, da mais forte para a mais fraca. Hook `PostToolUse`
(`scripts/hook-padrao.sh`) confere cada arquivo escrito em milissegundos, sem Docker, e devolve o
erro na hora. `scripts/verificar-padrao.php` confere o projeto inteiro, e o faz **sobre o HTML
renderizado** além do texto do template, porque template limpo pode renderizar sujo. O agente
`designer-ui`, versionado no repositório, é obrigatório antes de tela nova ou redesenho. E a regra
escrita em `CLAUDE.md`, para quem não passar por nenhum dos três.

**Alternativa recusada.** Confiar na revisão. Já era a política, e falhou três vezes no mesmo dia.
Também recusado bloquear a escrita de template por hook `PreToolUse`: travaria o próprio agente
designer, e o problema não é quem edita, é o que sai.

**Consequência.** A primeira execução acusou 28 violações em telas dadas por prontas, e o merge
com a main acusou mais 10 nas telas que vieram de lá. O verificador roda no `/encerrar` e sessão
que mexeu em tela não fecha com violação aberta. Verde nele é o piso, não a aprovação: a régua
continua sendo olho humano, e por isso o agente não foi substituído pelo script.

---

## D43 · A auditoria tem dois caminhos de leitura, e isso não é duplicação

`14/09/2026` · E6 · commit a seguir · `Repository\AuditoriaRepository`

**Contexto.** O merge de 14/09 juntou duas branches que criaram `AuditoriaRepository` no mesmo dia,
sem saber uma da outra e sem nenhum método em comum: `registrar()` e `doUsuario()` de um lado,
`listar()`, `contar()` e `acoesDistintas()` do outro. Os dois `SELECT` de leitura parecem a mesma
consulta com nomes diferentes.

**Decisão.** Ficam os dois no mesmo arquivo, com a razão escrita no docblock da classe.
`doUsuario()` serve à exportação do titular (item 11.3) e devolve seis colunas; `listar()` serve ao
painel do administrador e devolve tudo, com `LEFT JOIN` no nome de quem agiu e com paginação.

**Alternativa recusada.** Unificar em `listar()` com parâmetros, e a exportação chamaria com o id
do titular. Obrigaria a exportação a carregar `aud_user_agent` e os valores gravados, que é dado
que o titular não deve levar num JSON que ele baixa. A economia seria de umas quinze linhas, ao
custo de um caminho de privacidade decidido por parâmetro.

**Consequência.** `UsuarioRepository::auditoriaDoUsuario()` foi removido no mesmo merge: a query
mudou de dono e o método tinha ficado órfão, com `PrivacidadeService` já chamando o repositório de
auditoria. Ficou um comentário de duas linhas no lugar, apontando para onde foi, para ninguém
recriar.

---

## D44 · O score filtra o pool, e quem ordena é a semente

`14/09/2026` · E4 · commit a seguir · `Support\Compatibilidade::embaralhar`, `CompatibilizacaoRepository::pool`

**Contexto.** O item 10.1 do edital veda ranking de profissionais e o 10.2 diz que a
correspondência é apenas indicativa. Um motor de compatibilização produz naturalmente um número
por candidato, e a coisa mais natural do mundo é ordenar a lista por ele. Isso é ranking, mesmo
chamando de "relevância".

**Decisão.** O score decide **quem entra** no pool, comparado com o limiar, e nada mais. A ordem
sai de `embaralhar()`, que ordena por hash de (semente da sessão + chave do candidato). A semente
é gravada em `mat_sessoes`, então qualquer sessão passada é reproduzível pelo administrador
(item 12.3), e a mesma semente devolve a mesma ordem em qualquer máquina.

Hash em vez de `shuffle()` com seed: `shuffle` depende do estado e da versão do gerador do PHP, e
a ordem poderia divergir entre a máquina de desenvolvimento e a da apresentação, o que destruiria
justamente a propriedade que a semente existe para garantir.

**Alternativa recusada.** Ordenar por score e não exibir o número. A ordem *é* o ranking: a
primeira posição é lida como recomendação institucional, que é exatamente o que o item 10.1
proíbe. Também recusado gravar a posição em `mat_sessao_pool`, e a verificação confere que a
tabela não tem coluna de posição, ordem ou rank.

**Consequência.** A leitura do pool devolve por `msp_id`, nunca por score, porque ordenar na
leitura reintroduziria o ranking pela porta dos fundos. Dois testes travam a propriedade: um monta
o pool em ordem decrescente de score e prova que a saída difere; outro prova que a mesma semente
devolve sempre a mesma ordem.

## D45 · As duas dimensões autodeclaradas falam um vocabulário fechado

`15/09/2026` · revisão · commit a seguir · `Support\Preferencias`, `Compatibilidade::abrangencia`, `PreferenciaService`

**Contexto.** Uma revisão do código inteiro encontrou duas das seis dimensões do item 3.2 mortas,
por dois defeitos que se escondiam um ao outro.

O primeiro: `prf_tipo_contrato` e `prf_disponibilidade` não eram escritas por **nenhum** caminho do
código. `PerfilService` as exibia, `CandidatoRepository` as lia, `ProfissionalRepository::salvar()`
até documentava por que não as toca — e não existia formulário nem serviço que as gravasse. O motor
rodava com quatro dimensões em vez de seis, calado, porque a regra que protege o perfil incompleto
(dimensão nula sai da média) também esconde a dimensão que ninguém pode preencher.

O segundo: a dimensão de abrangência comparava `dem_local_uf` (`"AM"`) com um campo cujo comentário
de esquema dizia "raio ou municípios", por `strcasecmp` exato. Nenhum texto real casaria. Se o
primeiro defeito fosse corrigido sozinho, a dimensão passaria a devolver **0.0** — a afirmação
"este candidato não atende" — para todo mundo que preenchesse o campo.

**Decisão.** Os dois lados passam a falar uma lista fechada, em `Support\Preferencias`: tipo de
contrato é uma chave de `CONTRATOS`, abrangência é `QUALQUER` ou uma lista de UFs (`AM,RR`),
normalizada em ordem canônica para que a mesma escolha grave sempre a mesma string. A comparação
de abrangência ganha função própria, `Compatibilidade::abrangencia()`, e **texto que o vocabulário
não reconhece devolve null**, não zero: coluna com resquício do tempo em que o campo era livre sai
da média em vez de punir quem a preencheu. `PreferenciaService` dá o caminho de escrita, e a tela
de demanda troca os dois campos de texto por seleção.

**Alternativa recusada.** Raio em quilômetros, que é o que o comentário original prometia. A API
não devolve coordenada de município e nenhuma das duas pontas sabe informar distância com
honestidade; geocodificar seria inventar precisão que o dado não tem, e ainda transformaria uma
dimensão autodeclarada de peso 0.10 na mais cara de calcular. Também recusado deixar o campo livre
e comparar por texto normalizado: "Manaus e região" e "Amazonas" continuariam sendo a mesma
intenção escrita de dois jeitos, e a dimensão voltaria a depender de sorte.

**Consequência.** A abrangência deixa de exprimir raio ou município: quem atende só a região
metropolitana de Manaus declara `AM` e o motor não distingue isso de quem atende o estado inteiro.
É perda de granularidade aceita em troca de a dimensão passar a funcionar. O `COMMENT` das duas
colunas mudou em `estrutura.sql`, então banco criado antes desta sessão descreve o campo errado —
sem efeito em dado, porque as duas colunas estavam nulas em toda linha. E `dem_tipo_contrato`
gravado como texto livre antes desta sessão não casa com nenhuma opção do novo `<select>`: editar
uma demanda antiga apaga o valor em silêncio. A recarga do banco antes da demonstração, que o
`estado.md` já exige por outro motivo, resolve os dois.

## D46 · `sis_auditoria` não tem `_log` nem `_status`, e é a única

`15/09/2026` · revisão · commit a seguir · `_arq/estrutura.sql`

**Contexto.** O Anexo I do edital pede `_dt_registro`, `_log` e `_status` em toda tabela, e o
`CLAUDE.md` repete a regra. Das 28 tabelas do esquema, 27 cumprem; `sis_auditoria` tem só
`aud_dt_registro`. A ausência era deliberada desde a D04 e não estava escrita em lugar nenhum —
numa conferência coluna a coluna, o que aparece é uma tabela fora do padrão sem justificativa.

**Decisão.** Fica como está, e a exceção passa a ser declarada aqui. `_status` é a marca de
exclusão lógica (item 8.6j): uma trilha de auditoria com coluna de exclusão é uma trilha que pode
ser apagada por `UPDATE`, que é exatamente o que a trigger da D04 existe para impedir. `_log` é
campo de anotação editável, e a tabela recusa `UPDATE` por construção — a coluna nasceria morta.
Acrescentar as duas para satisfazer a contagem produziria um esquema que *parece* cumprir a norma
enquanto contradiz o item 8.5g, que é a razão de a tabela existir.

**Alternativa recusada.** Criar as duas colunas com `DEFAULT` fixo e nunca usá-las. Passaria em
qualquer conferência automática e seria pior: um avaliador que lesse `aud_status` concluiria que
registro de auditoria pode ser excluído nesta plataforma.

**Consequência.** Uma conferência coluna a coluna da nomenclatura do Anexo I vai acusar uma tabela
fora do padrão, e a resposta é esta entrada — que precisa estar à mão no Demo Day, não descoberta
na hora. Em troca, `sis_auditoria` não tem nenhum caminho, nem de esquema, que sugira exclusão.

## D47 · A coluna do perfil lê em três registros, e a ordem é o argumento

`15/09/2026` · revisão · commit a seguir · `templates/perfil/index.html.twig`

**Contexto.** Com o formulário de preferências declaradas, o perfil do profissional passou a ter
três blocos de natureza diferente na mesma coluna: o acervo, que o CREA confirma; as preferências,
que a pessoa declara em vocabulário fechado; e a experiência, que ela declara em texto livre. A
plataforma inteira se sustenta em não confundir o primeiro com os outros dois.

**Decisão.** A ordem da coluna é verificado → declarado estruturado → declarado narrativo, e ela é
o argumento: o que a página mostra primeiro é o que sustenta o resto. O bloco de preferências diz
com todas as letras quanto pesa no motor — 0,10 por dimensão contra 0,40 da competência comprovada
em ART. Marca de autodeclarado é a mesma nos dois blocos declarados.

**Alternativa recusada.** Agrupar as preferências junto do cabeçalho de identidade, que é onde um
formulário de "dados da conta" normalmente vive. Colocaria regime de contratação ao lado do RNP,
sugerindo que os dois têm a mesma origem — exatamente a confusão que o projeto existe para evitar.
Também recusado omitir os pesos: número de motor na tela do titular parece detalhe interno, mas é
o que permite a ele entender por que aparece ou não, e o item 12.3 pede critérios explicáveis.

**Consequência.** Toda tela de perfil futura — `/perfil/{id}` público, o card do feed — herda esta
ordem, ou a plataforma passa a dizer coisas diferentes em telas diferentes sobre o que é evidência.
E os pesos na tela viram promessa: recalibrar `sis_parametros` sem atualizar o texto deixa a tela
mentindo.

## D48 · Autodeclarado é pílula neutra, e isso encerra a execução da D33

`15/09/2026` · revisão · commit a seguir · `templates/perfil/index.html.twig`, [`design.md`](design.md)

**Contexto.** A D33 tirou o amarelo como sinal de autodeclarado e o `design.md` registrou a regra,
mas o bloco de experiência continuava com `badge text-bg-warning` no rótulo "Declarado" — a decisão
tinha sido tomada e não tinha chegado ao template. Com um segundo bloco autodeclarado na mesma
coluna, o desvio deixaria de ser detalhe: seriam dois vocabulários visuais para o mesmo conceito,
lado a lado.

**Decisão.** Autodeclarado é `.pl-pill.neutral` nos dois blocos. Amarelo não marca nada nesta
interface, e verde água continua exclusivo de verificação.

**Alternativa recusada.** Deixar como estava e alinhar depois, junto com uma passada geral de
design. É como o desvio chegou até aqui: a D33 é de 14/09 e o template nunca foi ajustado.

**Consequência.** A ausência de cor quente para "não verificado" é deliberada e vai parecer
omissão para quem olhar a tela sem contexto — um avaliador pode perguntar por que a plataforma não
"alerta" sobre dado não verificado. A resposta é a D33: alerta pressupõe risco, e declarar
experiência não é risco; o que a interface faz é separar, não advertir.

## D49 · Campos que se anulam resolvem no servidor; o script só sincroniza

`15/09/2026` · revisão · commit a seguir · `templates/perfil/index.html.twig`, `public/assets/js/perfil-abrangencia.js`

**Contexto.** "Qualquer lugar do país" e a lista de 27 UFs se anulam: marcar o primeiro torna a
lista irrelevante, e `Preferencias::normalizarAbrangencia()` já dá precedência a `QUALQUER` ao
gravar. A tela precisa mostrar essa anulação, e o caminho curto é um `disabled` posto por
JavaScript no carregamento.

**Decisão.** O estado correto é renderizado pelo servidor — o `fieldset` das UFs nasce com
`disabled` quando `QUALQUER` está gravado — e o script só mantém isso em dia no clique. A tela
está correta com JavaScript desligado, bloqueado por CSP ou quebrado por erro anterior na página.

**Alternativa recusada.** Resolver tudo no cliente. Funciona em 99% dos carregamentos e falha no
que importa: sem o script, a tela mostraria 27 caixas habilitadas que a gravação vai ignorar, e a
pessoa acreditaria ter declarado uma coisa enquanto o banco guarda outra.

**Consequência.** Todo par de campos que se anulam nesta plataforma passa a dever o estado inicial
ao servidor, o que significa que o controller precisa conhecer a regra — não dá para tratar
exclusão mútua como assunto só de interface.

## D50 · A CSP espelha o layout, e a máquina confere isso

`15/09/2026` · revisão · commit `0c417a6` · `docker/nginx/default.conf`, `scripts/verificar-padrao.php`

**Contexto.** A primeira versão da CSP acrescentada nesta sessão foi escrita por suposição:
`script-src 'self'`, `style-src 'self' 'unsafe-inline'`, e um comentário afirmando que o Bootstrap
vinha de arquivo local. Não vinha — `layout/base.html.twig` carrega Bootstrap (CSS e JS) de
`cdn.jsdelivr.net` e as fontes do Google desde a fundação. A política teria servido **todas** as
telas sem estilo nenhum, e sem erro visível: bloqueio de CSP só aparece no console do navegador.

**Decisão.** A política lista exatamente as origens que o layout carrega, por diretiva, e
`verificar-padrao.php` compara as duas listas a cada execução: falha se um template usa origem que
a diretiva certa não permite, se uma origem some da política inteira, e se a política permite
origem que nenhum template usa. Script embutido também falha — o da abrangência foi para
`public/assets/js`, e assim `script-src` não precisa de `'unsafe-inline'`, que é onde a CSP carrega
o peso real contra XSS.

**Alternativa recusada.** Servir Bootstrap e as fontes de `public/assets` e fechar a CSP em
`'self'`, que é mais seguro e ainda elimina a dependência de rede no Demo Day. Recusado **nesta
sessão** por escopo — é mudança de infraestrutura de front no meio de uma revisão de motor —, e
não por mérito: continua sendo a escolha certa e está anotada como pendência.

**Consequência.** A plataforma depende de `cdn.jsdelivr.net` e de `fonts.googleapis.com` estarem
acessíveis para aparecer com estilo. No Demo Day presencial, sem internet ou com rede filtrada, a
apresentação é feita sem CSS. A verificação automática protege contra a política divergir do
layout, mas não contra a rede: essa é a pendência acima.

---

## D51 · O feed é passivo: quem manifesta interesse é o candidato, não o demandante

`15/09/2026` · E4 · commit a seguir · `templates/demanda/compativeis.html.twig`,
`docs/mockups/prolink-feed-imersivo-v3.html`

**Contexto.** O mockup aprovado do feed traz, no card de cada compatível, um botão "Manifestar
interesse" com a legenda "enviado por e-mail à outra parte" — ou seja, o **demandante convidando o
candidato**. Ao construir a tela, a leitura das fontes mostrou que esse sentido não é o
especificado em lugar nenhum, e que a própria proposta se contradiz:

- Edital, Anexo I item 7, cenário 4: *"Profissional manifesta interesse e a empresa visualiza o
  perfil."*
- Proposta, RF05: *"Profissional manifesta interesse em demanda; o sistema captura snapshot do
  perfil no momento da manifestação; empresa recebe notificação."*
- Proposta, jornada do usuário (p. 4), fase 04, coluna Empresa: *"Navega o feed, confere o Selo ART
  verificado e **manifesta interesse**."* — o sentido inverso, e marcado como fluxo crítico.
- Edital e proposta, tabela de perfis: Empresa e Terceiros podem "registrar interesse". Sem dizer
  se é convite ativo ou leitura da manifestação recebida.

O banco segue o RF05: `pro_manifestacoes` tem `man_usu_id` ("quem manifestou") e
`uq_man_dem_usu UNIQUE (man_dem_id, man_usu_id)`, e **não tem `man_candidato_id`**. Não há onde
guardar "para quem o convite foi enviado".

**Decisão.** O feed é passivo. O card tem uma ação só, "Ver perfil completo", que leva a
`/perfil/{id}`. O botão de manifestar sai do mockup na portagem para código. Quem manifesta é o
profissional, a partir de `/demandas/abertas`, e isso é a E5.

O argumento que decidiu não foi o de esforço, foi o do item 10.1. A plataforma não pode fazer
ranking nem recomendação institucional, e a correspondência é apenas indicativa. Uma empresa
abordando ativamente candidatos extraídos de um pool pontuado se parece muito mais com
recrutamento ordenado do que um índice onde quem procura trabalho se candidata — mesmo com a
ordem sorteada e o score escondido. O feed passivo é a postura que a proposta já vendia:
*"Empresas **recebem** compatíveis em feed passivo."*

**Alternativa recusada.** Implementar o convite, acrescentando `man_candidato_id` e trocando o
índice único por `(man_dem_id, man_usu_id, man_candidato_id)`. Recusado por três razões, em ordem:
nenhum dos seis cenários de demonstração o exercita; é mudança de modelo de dados a dois dias da
entrega; e enfraquece a defesa do 10.1 acima, que vale 15 pontos no critério de ética.

Também foi recusado deixar o botão na tela desabilitado com "em breve": elemento morto numa tela
que a banca vai olhar de perto custa mais do que a intenção que ele comunicaria.

**Consequência.** O mockup `prolink-feed-imersivo-v3.html` diverge da tela implementada neste
ponto, e continua no repositório como estava — é artefato de design, com data. A capacidade
"registrar interesse" da tabela de perfis fica atendida pelo lado da leitura: o demandante vê e
responde as manifestações recebidas (E5). Se a banca pedir o convite ativo, o caminho está
descrito na alternativa recusada e é uma migração mais um formulário.

---

## D52 · ART fechada conta para o match e não é citada pelo número

`15/09/2026` · E4 · commit a seguir · `src/Service/CompatibilizacaoService.php`,
`src/Repository/EvidenciaRepository.php`

**Contexto.** O card do feed explica a compatibilidade citando a evidência que a sustenta:
"compatível porque a ART AM…001 cobre TOS_x e está na CAT 999001/2026". Essa é a promessa central
da proposta — evidência documental no lugar de autodeclaração — e o "critério explicável" que o
Anexo VI cobra.

Só que o índice de evidência (a view `crea_evidencias`, D01) **não consulta `pro_visibilidade`**.
Ele deriva de `crea_arts`, `crea_art_atividades` e `crea_quadro_tecnico`, que são cache da
resposta da API. Então o card citaria, pelo número, uma ART que o profissional fechou.

Pela régua do próprio projeto isso é vazamento: a E2 tem conferência específica de que "o número
da ART fechada não vaza dentro da experiência aberta". A visibilidade granular por ART é exigência
da RF03 e do item 11.3, e o feed é exatamente o lugar onde ela tem consequência.

A pergunta tem duas metades, e elas se decidem em sentidos opostos.

**Decisão.** **A ART fechada continua contando para o score, e não pode ser citada pelo número.**

Conta para o score porque o mecanismo de "não me procure" é revogar `EXIBICAO_PERFIL`, que é um
dos dois portões globais da D22, e não fechar ARTs uma a uma. A D23 já havia fixado esse desenho
do outro lado: *"quem não quer ser encontrado fecha o perfil inteiro."* Se fechar uma ART tirasse
pontos, a privacidade viraria custo de posicionamento, e a plataforma estaria empurrando o
profissional a abrir tudo para não ser penalizado — o oposto do que a RF01 promete. A proposta já
tem o mesmo ethos no perfil em construção: sinaliza "sem excluí-los do pool".

Não é citada pelo número porque a D22 manda o desenho falhar fechado e aplicar o filtro na
montagem, não na renderização. Quando a evidência que sustenta a correspondência está fechada, o
card diz que existe evidência não aberta, em vez de exibir o documento.

**Alternativa recusada.** Tirar a ART fechada do cálculo, que foi a primeira leitura de "privado é
só meu" e é intuitiva. Recusada porque confunde duas finalidades distintas: o consentimento
`EXIBICAO_PERFIL` — cujo texto na tela é "autorizo exibir meu perfil para demandantes e na busca
da plataforma" — é o que autoriza ser encontrado; a visibilidade por ART governa o que a **vitrine
do perfil** mostra. Fundir as duas faria o controle de exibição operar como controle de
elegibilidade, sem que nada na interface avise a pessoa disso.

Também foi recusado exibir tudo, sob o argumento de que entrar no pool já é consentimento
suficiente. É defensável, mas exigiria defender por escrito na entrega por que um controle
granular anunciado não vale no único lugar em que o dado é efetivamente consumido.

**Consequência.** O efeito colateral é bom para a demonstração: o feed passa a **exibir** o
controle granular funcionando, em vez de só o descrever. O custo é que um candidato com todo o
acervo fechado aparece no pool com a correspondência explicada só por dimensão, sem documento —
estado legítimo, que a tela precisa dizer com clareza em vez de parecer defeito.

Fica uma assimetria a vigiar: o score reflete acervo que o demandante não pode conferir. É
mencionável na declaração de limitações do item 12.3, e é o preço de não penalizar a privacidade.

## D53 · A largura é decidida por tipo de conteúdo, e a explicação vira revelação sob demanda

`16/09/2026` · front · passagem de sistema sobre as telas existentes · desenho em [`design.md`](design.md)

**Contexto.** Duas queixas do dono do produto sobre a mesma tela de sempre. A primeira: *"tá tudo
muito engessado, até tentando ser um pouco mobile"*. A causa era literal: o `base.html.twig` punha
**toda** tela dentro de `<main class="container py-4">`, e o `.container` do Bootstrap trava em
1140px (1320px no XXL). Num monitor de 1920 a aplicação inteira era uma faixa central com vazio
dos dois lados, e a trilha de auditoria de seis colunas disputava 1140px enquanto sobrava tela. O
uso de grid era raso: três telas repetiam `col-lg-7` + `col-lg-5` e o resto era coluna única.

A segunda: *"o nosso front tenta muito explicar a aplicação"*. Eram 18 parágrafos explicativos na
interface, o maior com 361 caracteres, todos permanentes. Eles ajudam na primeira visita e
estorvam em todas as outras.

A tensão real estava na segunda queixa: parte dessa prosa existe por exigência do edital (12.3 e
Anexo VI pedem critérios explicáveis; o item 10.2 pede a declaração de não-ranking), e esconder o
que a banca pontua seria autossabotagem.

**Decisão.** Três regras.

**1. Largura por tipo de conteúdo.** `.pl-wrap` com três medidas: `densa` (1640px, tabela e
grade), `registro` (1360px, conteúdo mais painel de ação) e `leitura` (980px, formulário e texto
corrido). O padrão de desenho passa a ser o desktop; o teto existe para a linha de texto não ficar
ilegível em monitor ultralargo, não para centralizar coluna estreita. O par `col-lg-7/5` vira
`.pl-cols`, que dá ao painel largura de painel (máximo 372px) e ao conteúdo o resto.

**2. O "i" é apresentação legítima do critério, não esconderijo.** Divulgação progressiva é boa
prática de interface: o texto continua na tela, continua sendo lido por leitor de tela, e aparece
quando alguém pede. Explicação de funcionamento (como o pool é montado, o que é a Tabela de Obras
e Serviços, quanto cada dimensão pesa, por que a trilha é somente leitura) vai para o "i".

**3. O que decide continua visível.** Só duas classes de texto não podem depender de clique: a
declaração de não-ranking **na tela onde o motor apresenta resultado**, porque atrás de um clique
alguém leria a lista inteira sem saber o que ela é; e a consequência de ação irreversível **no
momento da ação**, que é consentimento informado e não dica de uso. As duas foram reescritas mais
curtas, e a segunda aparece de novo na confirmação do clique.

**Alternativa recusada.** Usar o Popover do Bootstrap, que já vem no bundle carregado. Recusado
por três motivos somados: ele exige inicialização por JavaScript (sem script, o "i" vira um botão
morto e a explicação some), o texto mora em `data-bs-content`, que é conteúdo em atributo — a
mesma objeção que o projeto já faz a `title=""` —, e ele não resolve nada que o `<details>` nativo
não resolva. O `<details>` já é botão para o teclado, já é anunciado, não depende de hover (logo
funciona igual em tela de toque) e mantém o texto no DOM. O JavaScript que acompanha é opcional e
só fecha painel aberto.

Também foi recusado esticar tudo para 100% da janela, que é a leitura preguiçosa de "ocupar a
tela": um formulário de cadastro com 1900px de linha é pior do que o container de antes.

**Consequência.** O `.pl-tos` saiu do feed para `layout/_ui.html.twig` e passou a valer nas quatro
telas que ainda mostravam `TOS_1.1.2.3` cru dentro de `<code>`; o sprite do selo de verificação
subiu para o `base.html.twig`, e com ele o acervo do perfil trocou a tarja verde de texto pelo
selo colado no número da ART, como o design system já mandava. A conferência renderizada ganhou
quatro telas em `scripts/amostras.php`, e a primeira delas já devolveu um defeito que a parte
estática não pegava: a tela de privacidade imprimia o perfil de acesso cru (`ADMIN`), porque o
verificador só procura constante com underline.

O custo é que existe agora um segundo vocabulário de largura ao lado do grid do Bootstrap, e tela
nova precisa escolher `classe_main` conscientemente. O padrão sem escolha (`pl-wrap`, 1240px) é
intencionalmente o meio-termo: erra por pouco em qualquer direção.

---

## D54 · A busca e o perfil abrem sem conta, e o que muda é o alcance, não o acesso

`16/09/2026` · E4 · commit a seguir · `public/index.php`, `src/Service/BuscaService.php`

**Contexto.** O Anexo I, item 3, dá ao perfil **Público** duas capacidades: "pesquisar
profissionais (especialidade, experiência, nome)" e "ver perfil". Até esta sessão nenhuma rota da
aplicação atendia quem não tinha sessão, e a busca ativa nem existia. `Visibilidade::alcanceDe()`
já estava escrita para isso desde a E2 — devolve `PUBLICO` para anônimo e `AUTENTICADO` para quem
entrou —, mas nada exercia esse caminho, então o nível `PUBLICO` da visibilidade granular era um
estado sem efeito prático: ninguém sem conta chegava a lugar nenhum para vê-lo.

**Decisão.** `/profissionais` e `/perfil/{id}` são `PERFIL_PUBLICO`. O anônimo não vê menos tela,
vê menos dado: o serviço filtra campo a campo pela mesma `Visao` que monta o perfil do titular.
Medido num perfil de postura reservada: anônimo enxerga 0 campos e 0 ARTs, autenticado enxerga 4
campos e 2 ARTs, sem nenhuma diferença de rota ou de layout.

**Alternativa recusada.** Exigir login nas duas. O argumento a favor é bom e não é formalidade: o
item 10.4 proíbe coleta automatizada, e busca aberta a anônimo é um endpoint de extração. Foi
recusada porque contraria uma linha literal do edital e porque esvaziaria o nível `PUBLICO` da
visibilidade — a plataforma ofereceria ao titular uma escolha ("qualquer pessoa") que nenhuma tela
honraria. O risco do 10.4 foi endereçado onde ele mora, no volume: `BuscaRepository::LIMITE` corta
em 60 e o serviço **avisa** que cortou, porque lista truncada em silêncio faz alguém concluir que
não há ninguém.

Também foi recusado o meio-termo "busca anônima, perfil só logado": separaria duas capacidades que
o edital lista na mesma linha, e deixaria o anônimo com uma lista de nomes que ele não pode abrir.

**Consequência.** O portão global continua sendo o mesmo `perfisAbertos()` do motor, de propósito:
candidato que não entra no feed não pode ser encontrado por outro caminho. E a identidade continua
não-ocultável (D23) — quem está aberto aparece com nome e registro mesmo tendo fechado o resto;
quem não quer ser encontrado revoga a exibição, que fecha o perfil inteiro.

Um defeito nasceu e morreu dentro desta decisão: `PerfilController::publico()` fazia
`(int) Sessao::usuarioId()`, e para anônimo isso vira `0`, que não é `null`. Como a `Visao` decide
`autenticado` por `!== null`, o anônimo teria recebido o alcance de quem tem conta. Vale como
aviso: ao abrir rota ao público, o espectador nulo precisa sobreviver até a camada que o
interpreta.

---

## D55 · A auditoria de sessão mostra o pool como foi gravado, e não como ele ficaria hoje

`16/09/2026` · E4 · commit a seguir · `src/Service/CompatibilizacaoService.php` (`reproduzir()`)

**Contexto.** O feed relê a sessão gravada e reaplica o portão de privacidade a cada leitura
(D52): quem revogou `EXIBICAO_PERFIL` depois do cálculo some da lista, porque o feed é superfície
viva. A tela `/admin/sessoes/{id}` lê exatamente a mesma sessão, e a pergunta era se deveria fazer
o mesmo.

**Decisão.** Não. A auditoria mostra o pool como ficou registrado, incluindo quem fechou o perfil
depois e quem excluiu a conta (esse aparece como linha sem nome, dizendo que a conta não existe
mais). A tela existe para provar **o que o motor fez naquele instante**, e ajustar o registro à
preferência de hoje faria a trilha mentir sobre o passado — um pool com buraco silencioso é
auditoria incompleta, e uma auditoria que não pode ser confrontada não serve ao item 12.3.

O que o administrador vê é nome e chave do candidato, que é a mesma identidade que `sis_auditoria`
já mostra de quem agiu. Nada além disso: para abrir o perfil de alguém ele passa pela `Visao` como
qualquer espectador, porque a D06 e a D22 recusaram passe livre de administrador, e esta decisão
não o reintroduz pela porta lateral.

**Alternativa recusada.** Filtrar igual ao feed, por coerência entre as duas telas que leem a mesma
tabela. Recusada porque a coerência aqui seria só aparente: as duas telas respondem a perguntas
diferentes. O feed responde "quem posso ver agora"; a auditoria responde "o que aconteceu". Aplicar
a resposta da primeira à segunda destruiria a única prova de que o sorteio foi o que dizemos que
foi.

**Consequência.** Existe um dado no painel administrativo que o demandante já não enxerga. É
defensável porque é registro de execução, não vitrine, e porque o administrador tem o próprio
acesso registrado em `sis_auditoria`. Vale declarar isso na seção de limitações do 12.3, em vez de
esperar a pergunta.

A tela também se recusa a anunciar "reprodução confere" quando o pool está vazio: comparar duas
listas vazias não prova nada, e chamar isso de prova seria o oposto de auditar.

---

## D56 · O nome da empresa na tela é a razão social, não o nome fantasia

`16/09/2026` · E4 · commit a seguir · `src/Repository/CandidatoRepository.php`

**Contexto.** `CandidatoRepository` compunha o nome do candidato empresa como
`emp_nome_fantasia ?: emp_razao_social`, que é a ordem natural em qualquer cadastro comercial. Na
massa do desafio a API devolve o nome fantasia truncado na primeira palavra: "RIO NEGRO ENGENHARIA
CIVIL S.A." vira "RIO", "BASE SÓLIDA CONSTRUÇÕES LTDA" vira "BASE". O defeito só ficou visível
quando o feed passou a mostrar o nome em 30px com avatar de iniciais, e a empresa apareceu como uma
palavra solta com uma letra no círculo.

**Decisão.** A tela lê `emp_razao_social` primeiro. O dado da API continua guardado e intocado —
muda apenas qual campo a interface prefere. Razão social é, além disso, o nome sob o qual o
registro no CREA existe, e registro é o que esta plataforma afirma sobre a empresa.

**Alternativa recusada.** Corrigir o nome fantasia no cache, deduzindo-o da razão social. Recusada
sem hesitação: o item 8.4 veda base própria que simule os dados da API, e as tabelas `crea_*`
guardam resposta real, datada e com hash — nunca dado escrito à mão. Escolher qual campo exibir é
decisão de interface; reescrever o campo seria adulteração.

**Consequência.** Empresa cujo nome fantasia seja legítimo e mais reconhecível que a razão social
passa a aparecer pela razão social. Na massa do desafio isso nunca acontece, e se a plataforma
sair do protótipo o certo é preferir a fantasia quando ela não for um fragmento — o que exige uma
heurística que não vale inventar agora.

---

## D57 · O snapshot congela o que o demandante podia ver, e não o perfil inteiro

`16/09/2026` · E5 · commit `ec83fbe` · `src/Service/ManifestacaoService.php`

**Contexto.** A manifestação grava um retrato do perfil (`man_snapshot`) para que "alterações
posteriores não afetem o que a empresa já viu", que é diferencial declarado da proposta. Faltava
decidir o ponto de vista do retrato, e as duas leituras têm apoio no texto: o RF05 diz que a
empresa "acessa o perfil **completo**", e a mesma linha diz "dados **autorizados** pelo
profissional".

**Decisão.** O snapshot é montado com o **demandante como espectador**:
`PerfilService::montar($candidato, $demandante)`. O JSON gravado já passou pela `Visao`, então
manifestar não abre campo nenhum que o titular tinha fechado. O consentimento de manifestar vale
para *aquela demanda*, e tratá-lo como autorização genérica sobreporia, sem aviso, a visibilidade
campo a campo que a D22 estabeleceu.

A decisão tem contrapartida, e ela não podia ficar escondida: quem tem o perfil quase todo fechado
manifesta e envia pouco. Medido na base — um candidato envia 0 campos, 0 ARTs e 0 experiências, só
nome e registro. Por isso `previa()` existe e a tela de confirmação mostra o envio real antes de
enviar, com o caminho para abrir mais. **A tela é parte da decisão, não um adorno dela.**

**Alternativa recusada.** Abrir o perfil inteiro para aquele demandante, ao pé da letra do "perfil
completo". Recusada porque faria o ato de manifestar operar como revogação silenciosa das escolhas
de visibilidade: a pessoa clicaria em "tenho interesse" e publicaria, para aquele destinatário,
campos que havia fechado deliberadamente. Se a plataforma quiser oferecer isso um dia, o desenho
honesto é visibilidade por destinatário, com caixas na própria tela de manifestação — o que o
modelo de dados hoje não tem.

**Consequência.** Perfil discreto manifesta e mostra pouco, e isso é o sistema funcionando. A tela
diz isso antes, não depois. E a leitura do snapshot reconfere o hash: retrato que não bate com o
próprio `man_snapshot_hash` é alteração no banco, e nesse caso a tela mostra alarme em vez do
conteúdo.

---

## D58 · A fila de e-mail dispara depois da resposta, não por cron

`16/09/2026` · E5 · commit `26aad26` · `public/index.php`, `scripts/despachar-fila.php`

**Contexto.** `NotificacaoService::despachar()` existia desde a E1 e **nada o chamava**. Setenta e
sete notificações estavam paradas, 65 de cadastro e 12 de recuperação de senha, algumas havia uma
semana. O e-mail só saía quando alguém rodava um script à mão, o que não é sistema de notificação,
é lembrete.

**Decisão.** Um `register_shutdown_function` no front controller despacha um lote pequeno depois de
`fastcgi_finish_request()`.

O lugar não é estético. O front controller sai por `exit` em seis caminhos — 404, 401, 403, CSRF
inválido e todo `View::redirecionar()`, que é `never`. Código no fim do arquivo não roda em nenhum
deles, e a manifestação, que redireciona logo depois de gravar, é justamente o caso que mais
precisa do e-mail sair. O shutdown dispara em todos.

Rodar **depois** de `fastcgi_finish_request()` tira o SMTP do tempo de resposta: medido, uma
requisição que despachou cinco e-mails respondeu em 7,6 ms.

**Alternativa recusada.** Cron dentro do contêiner, que é o que produção faria e não acopla e-mail
a requisição. Recusada por causa de quem vai avaliar: o edital exige ambiente que suba de forma
padronizada e reproduzível, e um processo agendado é uma peça móvel a mais que pode silenciosamente
não rodar na máquina da banca. O cenário 4 ao vivo precisa que o e-mail apareça no Mailpit em
segundos, sem ninguém rodar comando. `scripts/despachar-fila.php` continua existindo para drenar
fila represada e para servir de alvo a quem preferir agendar.

**Consequência.** O despacho ocupa o processo do php-fpm depois de a resposta ter ido, então o lote
é pequeno e fila represada drena em várias requisições. Falha de envio nunca chega ao usuário: a
resposta já saiu, e o que resta é registro. `pendentes()` ganhou teto de tentativas junto — sem
ele, a mensagem que falha para sempre consome o lote inteiro reencenando a mesma falha e empurra
para o fim o que sairia.

---

## D59 · Mensagem não dispara e-mail, e o administrador não lê a conversa

`16/09/2026` · E5 · commit `1d5a3f4` · `src/Service/InteressadoService.php`,
`src/Repository/MensagemRepository.php`

**Contexto.** A manifestação abre um canal entre duas partes. Duas perguntas apareceram juntas:
cada mensagem gera e-mail, e quem mais pode ler.

**Decisão.** Mensagem **não** gera e-mail. O RF07 lista os eventos que notificam — cadastro,
recuperação de senha, nova manifestação, atualização de demanda, nova denúncia — e mensagem não
está entre eles. Quem avisa é a interface, com contagem de não lidas em lote.

O administrador **não** entra na conversa. A D06 recusou superusuário implícito e a D22 recusou
passe livre de administrador sobre perfil fechado; conversa entre duas partes não é conteúdo
publicado, e moderá-la por iniciativa própria seria vigilância, não moderação. O caminho para
conteúdo abusivo é a denúncia, que é a E6 e já existe.

**Alternativa recusada.** Notificar cada mensagem, sob o argumento de que canal que ninguém checa
é canal morto. Recusada porque acrescentaria um evento que a proposta aprovada não lista — e
"aderência ao desafio e à proposta" vale 15 pontos —, além de transformar uma conversa de cinco
trocas em cinco e-mails. Se a ausência de aviso se mostrar um problema real de uso, o desenho certo
é digest, não mensagem a mensagem.

**Consequência.** Quem não voltar à plataforma não sabe que recebeu resposta. É limitação
consciente e vale declarar no item 12.3. Um detalhe que a implementação obrigou a acertar:
`marcarLidas()` filtra por remetente — sem isso, abrir a própria conversa carimbaria como lida a
mensagem que a pessoa acabou de enviar, e o "não lida" do outro lado sumiria sem ninguém ter lido
nada.

O corpo da mensagem não entra em `sis_auditoria`: a trilha precisa saber que houve mensagem, não o
que foi dito.

---

## D60 · O front é servido pela própria aplicação, e a CSP fecha em `'self'` (encerra a pendência da D50)

`16/09/2026` · E7 · commit `dfe3762` · `templates/layout/base.html.twig`,
`docker/nginx/default.conf`, `public/assets/vendor/`

**Contexto.** A **D50** fixou que a Content-Security-Policy espelha exatamente as origens que o
layout carrega, e deixou registrada uma pendência na Consequência: *"servir Bootstrap e as fontes
de `public/assets` e fechar a CSP em `'self'` continua sendo a escolha certa"*. Ela foi recusada
**naquela sessão** por escopo, não por mérito — era mudança de infraestrutura de front no meio de
uma revisão de motor.

Três sessões depois a pendência continuava, e o `estado.md` a carregava como o maior risco
operacional: o Demo Day é presencial, e a plataforma dependia de `cdn.jsdelivr.net` e de
`fonts.googleapis.com` estarem acessíveis para ter qualquer aparência. Rede filtrada no auditório
significava apresentar a solução sem estilo nenhum — e, pior, sem aviso: bloqueio de CSP não
produz erro de tela, só linha no console.

**Decisão.** Bootstrap 5.3.3 (CSS e bundle JS) e as 14 faces de Inter e Space Grotesk passam a ser
servidas de `public/assets/vendor/`, versionadas no repositório. A CSP fecha em `'self'`:
`default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'`.

Só os subconjuntos `latin` e `latin-ext` das fontes — cirílico, grego e vietnamita somariam mais
de vinte arquivos que nenhuma tela desta plataforma usa. Total versionado: 988 KB.

**O fechamento da CSP é a própria garantia.** Não é preciso confiar em disciplina de quem escreve
a próxima tela: se um template voltar a apontar para um CDN, o navegador bloqueia e a tela quebra
imediatamente, em desenvolvimento. `scripts/verificar-padrao.php` continua comparando a diretiva
com o que os templates carregam.

**Alternativa recusada.** Manter o CDN e aceitar o risco, com a justificativa de que auditório de
evento costuma ter rede. Recusada porque o custo do erro é assimétrico: acertar economiza 988 KB
no repositório, errar custa a demonstração ao vivo, que é onde estão os 20 pontos de
"Funcionalidade e experiência" — o critério de maior peso da ficha.

Também foi recusado baixar os arquivos num passo de instalação (`composer` ou script de setup).
Acrescentaria uma dependência de rede ao **momento de subir o ambiente**, que é exatamente o que o
item 8.8 pede que seja reproduzível, e moveria o problema em vez de resolvê-lo.

**Consequência.** O repositório carrega binário de terceiros, o que normalmente se evita. É
deliberado e está declarado em `_arq/dependencias.md` com versão e licença — MIT para o Bootstrap
e SIL OFL 1.1 para as duas famílias, todas permitindo redistribuição embutida. Atualizar o
Bootstrap passa a ser um passo manual: baixar, substituir, conferir a aparência. Para um protótipo
com data de entrega, é o lado certo da troca.

---

## D61 · A faixa aceitável de cada parâmetro mora no código, não numa coluna nova

`17/09/2026` · E7 · `src/Support/Parametros.php`, `src/Service/ParametroService.php`

**Contexto.** O item 12.3 do edital exige supervisão humana sobre os critérios de recomendação, e
enquanto os pesos do motor só existiam em `sis_parametros` sem tela, a supervisão era uma frase na
documentação e um `UPDATE` no banco. A tela de edição precisava saber o que é valor aceitável para
cada parâmetro: peso vai de 0 a 1, limiar também, "mínimo de ARTs" é inteiro, intervalo de
sincronização tem piso. A tabela guarda chave, valor, tipo, grupo, descrição e a marca de
sensível, e não guarda faixa.

**Decisão.** A faixa fica em `Support\Parametros::LIMITES`, uma lista fechada com `min`, `max`,
`passo` e `inteiro` por chave. Ela também decide **o que é editável**: chave que não está ali não
é aceita pelo formulário, mesmo existindo no banco.

**Alternativa recusada.** Acrescentar `par_min` e `par_max` a `sis_parametros`. É o desenho que um
avaliador imaginaria primeiro, e ele tem mérito: configuração junto do que configura. Foi recusado
por duas razões. A primeira é de oportunidade: seria `ALTER TABLE` em banco carregado na véspera da
entrega, e a estrutura é entregue como `estrutura.sql`, que a banca roda do zero. A segunda é de
natureza: faixa válida de peso é **regra de negócio**, não configuração. Quem muda o intervalo
aceitável do motor está mudando o motor, e isso deve passar por revisão de código, não por um
formulário.

**Consequência.** Parâmetro novo precisa de duas edições, a linha na carga inicial e a entrada em
`LIMITES`, e esquecer a segunda faz o parâmetro aparecer na tela como não editável. Foi
exatamente o que aconteceu com `manifestacao.limite_hora`, descoberto ao rodar a carga num banco
limpo: ele existia em `carga-inicial.sql` e não no banco de desenvolvimento, que é anterior a ele.

---

## D62 · Conta excluída pelo titular aparece na lixeira e não é restaurada por ato administrativo

`17/09/2026` · E7 · `src/Service/LixeiraService.php`

**Contexto.** O item 8.6j manda que nada seja apagado fisicamente e que o registro excluído
"continue acessível somente por mecanismo administrativo de lixeira". A primeira metade valia no
código desde a fundação; a segunda não existia, e registro que some de todo lugar é, na prática,
registro apagado. Ao construir a lixeira, uma pergunta apareceu: e a conta que o **próprio
titular** mandou excluir?

Essa exclusão é exercício do art. 18 da LGPD, e a D05 já registrou que a plataforma a atende por
revogação efetiva de acesso. Um administrador que a reativasse não estaria restaurando um
registro: estaria trazendo de volta dado pessoal que o dono mandou apagar, sem que ele peça e sem
que ele saiba.

**Decisão.** A conta **aparece** na lixeira, com a data e o autor do pedido, e a restauração é
**recusada** pelo serviço, com o motivo escrito na tela. A distinção é feita comparando o autor da
exclusão com o dono do registro, pela trilha de auditoria. Exclusão feita pela administração
continua restaurável. Toda restauração, em qualquer caso, exige motivo de 10 a 500 caracteres, que
vai para a trilha.

A regra vale só para `sis_usuarios`: restaurar uma demanda devolve um registro à operação,
restaurar uma conta devolve uma identidade inteira, com documento cifrado, consentimentos e
histórico. São coisas de tamanho diferente.

**Alternativa recusada.** Esconder essas contas da lixeira. Resolveria o risco de privacidade e
quebraria o 8.6j, que manda o excluído continuar acessível ao mecanismo administrativo. A segunda
alternativa recusada foi permitir a restauração com confirmação reforçada: confirmação protege
contra engano, não contra decisão errada, e aqui a decisão de reativar não é de quem administra.

**Consequência.** Existe um caminho que a plataforma declaradamente não oferece, e ele precisa
estar escrito na Política de Privacidade, não só no código. Está, no item 6. Se um titular pedir a
volta da própria conta, o procedimento é externo à tela e com registro próprio.

---

## D63 · A marca de início de carreira é recalculada na escrita, e não derivada na leitura

`17/09/2026` · E7 · `src/Service/PortfolioService.php`

**Contexto.** `prf_em_construcao` é derivado da contagem de ARTs do titular, e era escrito só pelo
cadastro. `associarArt` mudava a contagem e não recalculava: quem passasse do limiar associando
ARTs à mão continuaria sinalizado como iniciante para sempre. O backlog registrava isto como
defeito latente desde a E2, e latente só porque nenhuma rota chamava o método.

**Decisão.** O recálculo passa a acontecer dentro da própria transação que grava o acervo, no
único serviço que escreve ARTs. Toda entrada de ART passa por `persistir()`, e toda chamada de
`persistir()` está num dos dois caminhos que agora reavaliam. `PerfilCreaService::atualizarEmConstrucao`
continua existindo para os dois desfechos em que o acervo **não** entrou, onde não há transação de
acervo para carregar o recálculo.

**Alternativa recusada.** Derivar a marca na leitura, como a **D01** fez com o índice de evidência.
É o desenho melhor, e continua recusado por agora: `prf_em_construcao` é lido pelo motor em lote,
dentro da montagem do pool, e trocá-lo por contagem na leitura mexeria no `CandidatoRepository` na
véspera da entrega. Fica como melhoria pós-entrega.

Também foi recusado chamar o recálculo de fora, a partir de quem chama `associarArt`. É o mesmo
padrão frágil que produziu o defeito: a regra dependeria de cada chamador lembrar dela.

**Consequência.** A regra do que é "perfil em construção" mora em dois lugares, o que é dívida
declarada. O segundo lugar existe por um motivo estreito e está comentado como tal.

---

## D64 · Registro suspenso no CREA fecha a visibilidade, e não rebaixa a conta

`17/09/2026` · E7 · `src/Service/SincronizacaoService.php`, `scripts/sincronizar-status.php`

**Contexto.** `pro_status` é o único dado do conselho que muda sozinho depois do cadastro, e a
plataforma inteira se apoia nele: registro suspenso não pode continuar aparecendo com selo de
verificação. A reconsulta era promessa do backlog da E2 desde 10/09. Faltava decidir o que fazer
quando a API responde que o registro deixou de estar ativo.

**Decisão.** Fecha a visibilidade inteira do perfil e registra na trilha, com o motivo. A conta
continua, o acervo continua, o vínculo continua.

**Alternativa recusada.** Rebaixar a conta para Terceiro, que é o que o cadastro faz quando a API
não conhece o CPF (D20). Recusada porque suspensão de registro pode ser temporária, e destruir o
vínculo a partir de uma leitura que muda seria irreversível a partir de informação reversível.
Fechar visibilidade é reversível e já resolve o problema real, que é o perfil circular enquanto a
situação não se resolve.

**A visibilidade não se reabre sozinha quando a situação volta.** Quem fechou foi uma regra, e
reabrir escolha de privacidade sem o titular pedir seria decidir por ele. A assimetria é
deliberada e está verificada em `scripts/verificar-e2.php`.

**Consequência.** O titular que tiver o registro reativado precisa reabrir a visibilidade à mão, e
a tela precisa explicar isso. Hoje a explicação está na trilha de auditoria dele, o que é o
mínimo, não o ideal.

---

## D65 · `sis_auditoria` fica sem `_log`, sem `_status` e sem chave estrangeira, e isso é declarado

`17/09/2026` · E7 · `_arq/estrutura.sql`, `_arq/mer/`

**Contexto.** A geração do MER a partir do banco real levantou dois desvios da nomenclatura do item
8.6, os dois na mesma tabela. `sis_auditoria` é a única sem `_log` e sem `_status`, e `aud_usu_id` é
a única coluna `*_usu_id` do esquema sem chave estrangeira declarada para `sis_usuarios` — outras
doze a têm.

**Decisão.** Os dois ficam como estão, declarados por escrito aqui e sinalizados no próprio
diagrama. A ausência de `_status` é o ponto: a tabela é insert-only, protegida por trigger, e uma
coluna de status criaria a possibilidade de **exclusão lógica de linha de auditoria**, que é
exatamente o que a imutabilidade da D04 existe para impedir. Cumprir a letra do 8.6 aqui
enfraqueceria o 8.5g.

**Alternativa recusada.** Acrescentar as colunas por conformidade literal e nunca usá-las. Cria a
porta e confia em disciplina para não abri-la, que é o oposto da garantia que a trigger dá.

Para a chave estrangeira, foi recusado acrescentá-la na véspera: a conferência feita agora mostra
1494 linhas, **zero órfãs** e 6 sem autor, que são ações de sistema e onde `NULL` é legítimo. O
ganho seria formal, e a mudança de esquema em banco carregado, no dia da entrega, não se paga.
Entra como melhoria pós-entrega, com a ressalva de que a integridade precisa sobreviver à remoção
física de usuário, que é ato administrativo previsto.

**Consequência.** Se a banca perguntar, a resposta existe e está escrita. Sem esta entrada, o
desvio pareceria descuido.

---

## D66 · A suíte de ponta a ponta usa o navegador e cria dado real, sem atalho por SQL

`17/09/2026` · E7 · `e2e/`

**Contexto.** Os seis cenários do Anexo I são a definição de pronto do MVP, e eram provados por
`scripts/verificar-*.php`, que conversam com serviço e repositório. Isso prova a regra de negócio e
não prova a tela: a de auditoria já subiu em 500 com dezesseis conferências no verde, porque
nenhuma delas tocava o template.

**Decisão.** Uma suíte em Playwright que percorre os seis cenários clicando onde uma pessoa
clicaria, com sessão, CSRF e JavaScript reais, e que **cria dado de verdade**: cadastra
experiência, publica demanda, manifesta interesse, denuncia e modera, tudo pela interface. Toda
tela é conferida contra erro de servidor, rolagem horizontal e identificador de sistema visível.

Um segundo arquivo grava a mesma jornada num vídeo só, com legenda sobreposta, como insumo do
vídeo demonstrativo e ensaio da demo.

**Alternativa recusada.** Preparar o estado por SQL antes de cada cenário, que é mais rápido e
mais estável. Recusada porque provaria que o sistema funciona por um caminho que ninguém percorre:
três defeitos do próprio teste só apareceram porque ele passou pela interface, e o primeiro deles
era a confirmação de ação irreversível que o Playwright recusa por padrão, fazendo o POST nunca
sair.

**Consequência.** O banco acumula registro de verificação a cada execução, e a suíte precisa rodar
**antes** do recarregamento para a demonstração, nunca depois. Está escrito no `README.md` do
`e2e/` e no `estado.md`.

---

## D67 · A CAT fica fora do MVP, e a limitação é declarada em vez de silenciada

`17/09/2026` · E7 · `src/Service/CreaApiClient.php`, `_arq/estrutura.sql`

**Contexto.** O projeto se apresenta, no `README.md` e na proposta da fase 1, como compatibilização
por evidência documental: "ARTs, **CATs** e acervo operacional". A Certidão de Acervo Técnico é o
documento que o mercado pede em licitação, e a estrutura para ela existe inteira: `crea_cats` e
`crea_cat_arts` estão em `estrutura.sql` desde a fundação, `CreaApiClient::catsDoProfissional` e
`::validarCat` estão escritos e testados contra fixture, e a view `crea_evidencias` **já faz
`JOIN`** com as duas tabelas, expondo `evi_cat_numero` e `evi_cat_dt_validade`.

Falta uma coisa só: nenhum serviço chama aqueles dois métodos. As duas tabelas têm zero linhas, e
as duas colunas da view são sempre nulas.

**Decisão.** Não implementar na entrega de 17/09, e declarar a ausência em vez de deixar o leitor
descobrir. O caminho está desenhado e é curto: importar as CATs no mesmo ponto em que as ARTs são
importadas, e gravar os vínculos que `validarCat` devolve.

**Alternativa recusada.** Implementar hoje, que é tentador justamente por ser curto. Recusada por
onde o efeito cai: a view `crea_evidencias` é a única coisa que o motor lê, e popular `crea_cats`
muda **a evidência de cada candidato**, e com ela o pool de toda demanda, na véspera da entrega e
depois de os seis cenários já estarem verificados de ponta a ponta. Mexer no núcleo do produto
para ganhar cobertura de um documento que a massa fictícia tem em quantidade mínima é trocar risco
alto por ganho pequeno.

Também foi recusado esconder a lacuna reescrevendo o `README.md` para não citar CAT. A proposta
aprovada citou, a estrutura está lá, e a banca compara proposta com entrega: declarar o que ficou
para depois é mais forte do que fingir que nunca foi prometido.

**Consequência.** O portfólio da entrega é ART e acervo operacional. A CAT aparece no modelo de
dados e no MER como estrutura pronta e não alimentada, o que é verdade e está escrito. Entra em
`backlog.md`, junto dos outros itens que a proposta prometeu e o MVP não entrega.

---

## D68 · A sessão reconfere o perfil no banco, e a mudança de papel vale na hora

`17/09/2026` · E7 · `public/index.php`, `src/Repository/SessaoRepository.php`, `src/Support/Sessao.php`

**Contexto.** O backlog da E7 carregava esta linha desde 14/09: *"`Sessao` guarda o perfil em
`$_SESSION` e nunca o reconfere contra o banco. O `sessaoTemRespaldo()` derruba sessão revogada,
mas mudança de papel só vale no próximo login. Decidir junto com a operação atômica 5"*.

A operação atômica 5 resolveu metade: bloquear uma conta revoga as sessões dela, e a D39 mediu que
isso vale imediatamente. A outra metade ficou, e não era teórica. O rebaixamento para Terceiro da
**D20** e da **D26** acontece quando a própria pessoa clica em "validar meu registro" e a API
responde `200 []`, ou seja, **com sessão aberta**.

Medido com requisição forjada em 17/09, e é OWASP A01: rebaixar a conta no banco e repetir o
`POST /perfil/preferencias`, que é rota exclusiva de Profissional, devolvia 303 como antes.

**Decisão.** A consulta que o front controller já fazia a cada requisição autenticada, para saber
se a sessão vive, passa a trazer também o perfil, por `JOIN`. Se ele divergir do que está em
`$_SESSION`, a sessão é atualizada e a troca entra na trilha de auditoria. Depois da correção, a
mesma sonda devolve **403**.

Custo: um `JOIN` numa consulta que já existia. Nenhuma consulta nova por requisição.

**Alternativa recusada.** Derrubar a sessão quando o papel muda, que é a postura mais defensiva.
Recusada pelo caso real: quem é rebaixado é justamente quem acabou de pedir para revalidar o
próprio registro, e encerrar a sessão dele ali seria punir o ato de conferir, com uma mensagem que
ele não teria como interpretar. A autorização passa a usar o perfil corrente, que é o que o
problema exigia; o resto seria atrito sem ganho.

Também foi recusado reconferir o perfil dentro de `Sessao::temPerfil()`, que é onde a autorização
acontece. Aquele método é chamado várias vezes por requisição e não tem banco: colocá-lo lá
transformaria uma consulta em várias, e acoplaria a camada de sessão ao repositório.

**Consequência.** `SessaoRepository::ativa()` deixa de devolver só a sessão e passa a devolver o
perfil e o nome. Quem "simplificar" aquela consulta no futuro reabre a falha, e é por isso que
`verificar-e1.php` ganhou cinco conferências que a exercitam de ponta a ponta, incluindo o par
positivo e negativo e a linha de auditoria.

---

## D69 · Gerir contas é ato próprio, e não providência de denúncia

`17/09/2026` · E7 · `src/Service/ContaService.php`, `src/Repository/UsuarioRepository.php`

**Contexto.** A auditoria de requisitos por entidade, feita a pedido da autora, cruzou o Anexo I
item 3 com as rotas existentes. A linha "Administrador: moderar, **gerir perfis**, auditar, tratar
denúncias, emitir relatórios" tinha um pedaço sem caminho: bloquear uma conta só era possível como
**providência de uma denúncia**. Conta que precisa ser suspensa sem que ninguém a tenha denunciado
não tinha por onde, e não havia listagem que respondesse "quem existe na plataforma".

**Decisão.** `ContaService` com listagem filtrável por termo, perfil e situação, e bloqueio que usa
a mesma operação atômica da E6: status, sessões revogadas e trilha, tudo ou nada. Motivo obrigatório
de 10 a 500 caracteres, que vai para a auditoria.

A listagem **não traz documento**. `usu_documento_cif` é cifrado e só o titular tem motivo para
vê-lo decifrado; uma tela administrativa que mostrasse CPF de todo mundo seria o oposto do que a
D03 se comprometeu a fazer ao guardá-lo.

**Alternativa recusada.** Reusar o fluxo de denúncia, abrindo uma denúncia "de ofício" para poder
bloquear. Seria menos código e produziria um registro falso: a trilha diria que houve denúncia
onde houve decisão administrativa, e a diferença entre as duas é justamente o que a auditoria
existe para preservar.

**Consequência.** Três recusas explícitas: administrador, a própria conta, e desbloquear conta
excluída pelo titular, que seria a D62 por outro nome. E um defeito que a implementação expôs:
`UsuarioRepository::porId()` filtra por conta ativa, o que é correto no caminho operacional e
tornava o **desbloqueio impossível**, porque a conta bloqueada não era encontrada para ser
desbloqueada.

---

## D70 · O demandante registra interesse, e é a mesma manifestação com a origem gravada

`17/09/2026` · E7 · `src/Service/ManifestacaoService.php`, `pro_manifestacoes.man_origem`

**Contexto.** O Anexo I item 3 dá ao Terceiro "registrar interesse em profissional **ou** empresa",
e a plataforma só tinha a direção contrária: o candidato manifestava interesse numa demanda, e o
feed do demandante era declaradamente passivo. Quem publicava a demanda via o compatível na tela e
não tinha o que fazer com ele além de esperar.

**Decisão.** Uma coluna, `man_origem`, com `'C'` para candidato e `'D'` para demandante. O par
(demanda, candidato) continua sendo o mesmo, `uq_man_dem_usu` continua garantindo um por par, e o
que muda é quem começou e para quem vai o aviso: aqui o e-mail é para o candidato, com texto
próprio, porque o fato é outro (lá alguém se candidatou, aqui alguém foi procurado).

**Alternativa recusada.** Uma tabela `pro_interesses` separada. Duplicaria o snapshot, o limite por
hora, a situação e o canal de mensagens, e criaria duas respostas possíveis para "existe interesse
entre esta demanda e este perfil?" — que é exatamente a pergunta que a tela de interessados faz.

**Consequência.** O teto de manifestações por hora passa a contar também o ato do demandante, o que
é desejado: sem isso, um script registraria interesse no pool inteiro e o aviso viraria ruído para
todo candidato. O snapshot congelado é o do candidato, na visão do demandante, que é o que ele viu
quando decidiu.

---

## D71 · A experiência da empresa não vincula ART

`17/09/2026` · E7 · `pro_experiencias.exp_emp_id`, `src/Service/ExperienciaService.php`

**Contexto.** O Anexo I item 3 dá "publicar experiência" ao profissional **e** à empresa. Só o
profissional tinha caminho: as três rotas eram `PERFIL_PROFISSIONAL`, e `exp_prf_id` era `NOT NULL`
com chave estrangeira para `pro_profissionais`.

**Decisão.** `exp_emp_id` entra ao lado, e uma `CHECK` garante exatamente um dono. A diferença que
sobra entre os dois perfis é deliberada: o profissional amarra a experiência a uma ART do próprio
acervo, e **a empresa não vincula ART nenhuma**.

O motivo é a D24. A ART é sempre gravada sob o RNP de quem a registrou, nunca sob a empresa, e o
acervo verificado da empresa é o operacional, herdado do quadro técnico pelo CAO e já exibido no
perfil. Deixar a empresa apontar para uma ART afirmaria que ela a registrou.

**Alternativa recusada.** Generalizar a coluna para `exp_usu_id` e deixar o dono ser o usuário.
Mais limpo no papel, e exigiria migrar as linhas existentes e reescrever todas as leituras na
véspera da entrega. Fica como melhoria pós-entrega.

**Consequência.** A tentativa de vincular ART do lado da empresa é recusada inclusive por
requisição forjada, e está coberta em `verificar-e2.php`.

---

## D72 · A métrica que o mockup pedia e que não foi inventada

`17/09/2026` · E7 · `src/Repository/DashboardRepository.php`

**Contexto.** Os mockups do profissional e da empresa abrem num painel de início que a
implementação não tinha. Entre os quatro números do desenho está **"Visualizações do perfil"**.

**Decisão.** Não existe registro de quem viu o perfil de quem, e o tile foi trocado por
**"interesses recebidos"**, que existe desde a D70 e diz mais: é ato de um demandante, não
passagem de olho. O mesmo raciocínio vale para o sino da topbar: `sis_notificacoes` é fila de
e-mail, sem estado de leitura, e o número passou a contar manifestação recebida ainda não aberta,
que é o que `man_dt_visualizacao` registra.

**Alternativa recusada.** Criar a contagem de visualizações. Seria rastrear visita por titular:
dado novo de comportamento, com implicação de privacidade que a Política não declara, acrescentado
a horas da entrega. Inventar número para preencher um tile é o oposto da regra que o projeto
seguiu em toda parte.

**Consequência.** O painel entrega quatro números verdadeiros em vez de três verdadeiros e um
inventado, e a diferença em relação ao desenho está escrita aqui e no código.

---

## D73 · Os mockups são o design final, e a implementação é que se ajusta

`17/09/2026` · E7 · `docs/mockups/`, `templates/`

**Contexto.** As telas foram construídas seguindo o `design.md`, que é o design system destilado
dos mockups, e foram se afastando deles: a landing virou um título com dois botões numa coluna de
980px, sem a barra de pesquisa que o fluxo público promete, e as telas logadas ficaram com a
top-nav horizontal em vez da barra lateral que os mockups desenham. A autora reprovou, e a
instrução foi literal: *"tudo que eu mandei refinar é pra ter fidelidade ao mockup, é basicamente
só pra colocar o backend nas telas porque tá estático"*.

**Decisão.** Os arquivos de `docs/mockups/` passam a ser tratados como **o design final**, e não
como referência a ser interpretada. Quem implementa porta o mockup sobre o Bootstrap e liga o dado
real; não redesenha, não reescreve texto, não acrescenta nem corta seção. Divergência só quando o
mockup depende de dado que não existe ou quebra uma regra do projeto, e nesse caso ela é listada
no relatório com o motivo.

**Alternativa recusada.** Manter o `design.md` como fonte e tratar o mockup como inspiração. É o
que vinha sendo feito, e produziu telas que respeitam a paleta e não se parecem com o que foi
desenhado. O design system continua valendo para o que o mockup não decide (componente novo,
estado de erro, responsividade), e não para sobrepor o que ele decide.

**Consequência.** Duas divergências já registradas por essa régua: a landing não usa a top-nav do
`base.html.twig`, porque o mockup tem a barra dentro do hero, e o `design.md` precisava dizer isso;
e as animações em `<canvas>` do mockup voltaram, com o script movido para `public/assets/js/`,
depois de terem sido convertidas em SVG estático por uma leitura errada da CSP.


## D74 · O par excluído não pode ser recriado, e a limitação fica declarada

`17/09/2026` · E4 · `_arq/estrutura.sql`, `src/Repository/ManifestacaoRepository.php`

**Contexto.** `ManifestacaoRepository::idDoPar()` filtra por `man_status = 'A'`, então a aplicação
entende que interesse excluído libera um novo registro do mesmo par demanda/pessoa. O índice
`uq_man_dem_usu`, porém, é sobre `(man_dem_id, man_usu_id)` **sem o status**: o banco recusa a
reinserção. A tentativa não produziria a mensagem tratada que o serviço escreveu; produziria erro
de integridade.

**Como apareceu.** Não por um caminho de usuário: apareceu ao consertar
`scripts/verificar-e4.php`, que criava um interesse por execução e nunca o desfazia. A correção
natural seria excluir logicamente o que o script criou, e ela não funciona por causa do índice.

**Decisão.** Não alterar o índice a horas da entrega. Mexer em restrição de unicidade de uma
tabela com dado de demonstração exige migração, recarga e uma nova rodada de verificação de tudo
que toca manifestação, e o defeito **não tem caminho de usuário que o alcance**: nenhuma tela
exclui manifestação, e a lixeira administrativa restaura em vez de recriar.

O script passou a remover fisicamente a linha que ele mesmo inseriu, com o motivo escrito no
código: é rastro de verificação criado segundos antes, pelo id que o próprio arquivo guardou, e
não dado da aplicação. A trilha de auditoria da operação **fica**, porque é insert-only por
gatilho, e é ela que prova que o registro aconteceu.

**O que a correção evitou.** A verificação se degradava sozinha: cada execução consumia um
candidato do pool, e depois de algumas rodadas o bloco inteiro passava a ser pulado, derrubando o
placar de 42 para 34 sem nada ter quebrado. Verificação que enfraquece a cada execução é pior que
verificação nenhuma, porque o número continua verde enquanto a cobertura desaparece. Três
execuções seguidas agora dão 42.

**Como corrigir depois.** Trocar o índice por um que inclua o status, ou por um índice parcial
sobre as linhas ativas, e tratar a violação restante como mensagem de domínio.

## D75 · A tela de integrações mostra o estado, e não chama a API

`17/09/2026` · E7 · `src/Repository/IntegracaoRepository.php`, `templates/admin/integracoes.html.twig`

**Contexto.** O Anexo I, item 3, lista cinco capacidades do administrador: moderar, gerir perfis,
auditar, tratar denúncias, emitir relatórios e **configurar integrações**. A última não tinha
tela, e o próprio `admin/_layout.html.twig` carregava um comentário dizendo que o item entraria
"quando a tela existir". Era a maior lacuna por perfil quando a autora pediu uma última passada.

**Decisão.** A tela existe e responde "a plataforma está falando com a API oficial, e desde
quando", com quatro coisas: a conexão (para onde aponta, com que tempo limite, e se a credencial
está configurada), o que já veio de lá e está em cache com a data de cada coleção, **os ajustes da
sincronização, editáveis**, e as últimas importações lidas da trilha de auditoria.

**A primeira versão só mostrava, e isso não bastava.** A autora perguntou se a tela estava certa
do jeito que estava, e não estava: o verbo do edital é *configurar*, e uma tela de leitura obriga
quem avalia a procurar o controle em outro lugar. Os dois parâmetros que a administração pode
mexer sem tocar no ambiente passaram para cá: o intervalo mínimo entre reconsultas, que já
existia e vivia escondido entre os pesos do motor, e o tamanho do lote, que era constante no
serviço. Os dois usam o mesmo lote atômico dos pesos, com a faixa aceitável declarada por campo e
o valor anterior indo para a trilha.

A tela de parâmetros deixou de mostrá-los e passou a apontar para cá: um mesmo controle em duas
telas seria duas verdades sobre a mesma coisa.

**Duas recusas, que são o conteúdo da decisão.**

**Ela não chama a API.** O item 10.4 veda coleta automatizada e a organização registra cada
chamada ao ambiente fictício. Uma tela que consultasse o serviço a cada carregamento gastaria cota
alheia para mostrar um número, e bastaria deixar a página aberta para virar exatamente o que o
edital proíbe. O estado vem do cache e da trilha; a conferência ao vivo continua explícita e fora
da interface, em `scripts/verificar-api.php`.

**Ela não mostra o token.** Diz que ele existe e quantos caracteres tem, que é o que responde "a
integração está configurada?" sem colocar uma credencial na tela de alguém. O Anexo VI reprova na
triagem quem entrega segredo à mostra, e uma tela de administração é um lugar tão bom quanto um
arquivo para vazar um. A amostra de `scripts/amostras.php` segue a mesma regra.

**Alternativa recusada: formulário que grava a configuração no banco.** Endpoint, credencial e
tempo limite vêm do ambiente, pelo `_config.php`, e é assim que o item 8.3.1 pede. Um formulário
criaria duas fontes de verdade para a mesma coisa, e a que vale na hora da requisição continuaria
sendo a do ambiente. O que é configurável em tempo de execução são os pesos do motor, e esses já
têm tela própria, com faixa declarada por campo e trilha.

---

## D76 · A CAT entra no acervo, e a D67 é revista

`24/09/2026` · E8 · `src/Support/Cat.php`, `src/Repository/CatRepository.php`,
`src/Service/PortfolioService.php`, `src/Service/PerfilCreaService.php`,
`src/Support/Compatibilidade.php`, `scripts/importar-cats.php`

**Contexto.** A D67 deixou a Certidão de Acervo Técnico fora da entrega de 17/09: a estrutura
existia inteira (`crea_cats`, `crea_cat_arts`, os dois métodos do cliente, a view
`crea_evidencias` já fazendo `JOIN`, o reforço `REFORCO_CAT` no motor e a tela de compatíveis
sabendo mostrar "CAT x"), e nenhum serviço gravava nas duas tabelas. O motivo da recusa era de
calendário: mexer na evidência de todo candidato na véspera, com os seis cenários já verificados.
Em 24/09 a organização liberou alterações até 26/09, e o motivo deixou de existir. A limitação
era a maior distância entre a proposta aprovada ("ARTs, CATs e acervo operacional") e a entrega.

**Decisão.** A CAT passa a ser importada junto com as ARTs, no cadastro do profissional, pelo
`PortfolioService::importarCats`, com as mesmas quatro regras do `importarArts`: consentimento
conferido antes de qualquer chamada, rede fora da transação, gravação tudo ou nada, auditoria
(`VALIDAR_CAT`). São duas rotas da API, porque nenhuma basta sozinha: a lista do profissional dá
os números das certidões; o detalhe de cada número dá as ARTs que ela agrupa. Custa uma chamada
por página da lista e mais uma por CAT; na massa, duas por profissional.

Cinco escolhas dentro da decisão:

1. **As ARTs da CAT passam pelo `persistir()` de sempre.** Na massa elas já chegaram pela lista do
   profissional, e a mescla da `Support\Acervo` só confirma: o detalhe da CAT não traz local nem
   forma de registro, e nulo novo nunca apaga valor existente. Conferido no clone: município,
   contratante e o próprio `art_hash` das quatro ARTs capturadas ficaram idênticos.
2. **A CAT tem selo próprio, que assina a certidão e a lista de ARTs que ela agrupa.** O dado que
   o motor usa é o vínculo, e é ele que uma fraude alteraria: uma linha a mais em `crea_cat_arts`
   daria a qualquer ART o reforço de uma certidão. O selo quebra, a tela avisa e a divergência vai
   para a auditoria. Reimportar desliga o vínculo estranho (`cta_status = 'X'`, nada é apagado).
3. **Certidão cujo detalhe não confere não é gravada** (outro RNP, outro número, resposta vazia),
   e a importação segue com as outras. É a regra do CAO (`importarCao`): gravar assim mesmo
   atribuiria acervo certificado de uma pessoa a outra.
4. **CAT vencida não reforça a competência.** `Compatibilidade::competencia` passou a conferir
   `evi_cat_dt_validade`; a ART segue contando pelo que é. Validade nula conta como vigente,
   porque ausência de data não é prova de vencimento. Na massa todas vencem em 31/12/2026, então
   a regra não muda nada na demonstração, e existe para não mentir no dia seguinte.
5. **Na tela, a CAT fica pendurada na ART que certifica**, e não numa lista própria. Assim ela
   herda a visibilidade da ART (quem esconde a ART esconde a certidão junto) sem uma regra nova de
   visibilidade, e quem lê o perfil vê a diferença onde ela importa. O desenho passou pelo
   `designer-ui`, como pede o `CLAUDE.md`.

**Quem já estava cadastrado.** `scripts/importar-cats.php` traz as certidões pelo mesmo serviço,
com limite obrigatório e modo de simulação. Rodado em 24/09 com autorização da equipe: 12
profissionais, 24 chamadas, 12 CATs, 36 ARTs certificadas, nenhuma recusada.

**O que continua fora, e por quê.** O acervo que a empresa herda do quadro técnico só traz CAT de
quem se cadastrou: importar as certidões de todo o quadro no momento do CAO seriam duas chamadas
por membro, para pessoas que não consentiram com nada, e isso se aproxima da varredura que o item
10.4 veda. A empresa herda a CAT de quem está no quadro e se cadastrou, pela view, sem chamada a
mais.

**Um fato da massa que a tela precisa saber.** Em todos os 12 profissionais importados, a CAT
cobre 100% das ARTs. "ART sem certidão" aparece, nesta massa, no acervo herdado pela empresa (4
das 98 linhas de evidência). Não é defeito do cálculo, e ninguém deve montar demonstração
supondo o contrário.

**Alternativa recusada: CAT como sétima dimensão do motor.** Daria à certidão peso próprio na
média, e um profissional com CAT de uma atividade irrelevante para a demanda ganharia pontos. A
CAT continua sendo o que a D10 desenhou: reforço dentro da competência, na atividade que ela
certifica, saturando em 1.

---

## D77 · O botão de atualizar o acervo tem espera, e a espera vale no servidor

`24/09/2026` · E8 · `src/Service/AtualizacaoAcervoService.php`, `src/Support/JanelaDeAtualizacao.php`,
`src/Controller/PerfilController.php`, `src/Support/Parametros.php`

**Contexto.** Desde a D76, o botão do perfil (que passou a se chamar "Atualizar meu acervo no
CREA") refaz o vínculo inteiro: perfil, ARTs, lista de CATs e cada CAT. No profissional típico da
massa são quatro chamadas à API oficial por clique, e nada limitava os cliques. O banco aguenta; o
problema é a API, que a organização registra chamada a chamada. Clicar sem parar produz o padrão
de coleta automatizada que o item 10.4 veda, e enche a trilha de auditoria de ruído.

**Decisão.** Uma janela de espera por titular, medida pela **última tentativa** registrada na
trilha (`ATUALIZAR_ACERVO`, com o resultado):

- tentativa concluída: 60 minutos (`api.atualizacao.minutos`, faixa de 15 a 1440);
- tentativa em que a API não respondeu: 5 minutos (`api.atualizacao.minutos_falha`, faixa de 1 a
  60). Deixa tentar de novo logo, sem que uma API caída vire rajada de chamadas.

Registro que o CREA não reconhece conta como consulta concluída: a API respondeu, e perguntar de
novo em um minuto não muda a resposta. Os dois parâmetros ficam na tela de Integrações, ao lado
dos da sincronização, com a faixa declarada (D61, D75).

**Três escolhas dentro da decisão:**

1. **A regra vale no servidor, e a tela só a mostra.** O botão desabilitado com o horário é
   conforto; quem manda um POST por fora da interface esbarra no mesmo limite no
   `PerfilController`.
2. **A medida vem da trilha de auditoria, e não de `prf_dt_sincronizacao`.** A trilha é
   insert-only, então ninguém zera a própria espera; e `prf_dt_sincronizacao` também é carimbado
   pela sincronização de status, que não atualiza o acervo, e bloquearia o titular por uma coisa
   que ele não pediu.
3. **Clique duplo é barrado por trava nomeada do MariaDB** (`GET_LOCK`, sem espera), por titular.
   O segundo pedido que chega enquanto o primeiro roda é recusado sem consultar nada, e o
   bloqueio não vira tentativa na trilha. A trava some sozinha se o processo morrer.

**Na tela.** Os três botões que chamam o mesmo endereço (o do acervo, o do estado vazio e os
dos avisos amarelos de validação e importação pendentes) passam pelo mesmo macro
(`ui.atualizar_acervo`) e ficam desabilitados com a mesma nota. O servidor manda os segundos que
faltam, e não um horário, e `atualizar-acervo.js` reativa o botão quando eles acabam: contar pelo
relógio de quem olha erraria em computador com hora errada. Sem o script, recarregar a página
depois do horário traz o botão de volta.

**Alternativa recusada: limite só no botão, por JavaScript.** Não protege a API, que é o motivo da
decisão.

**Alternativa recusada: contar tentativas por hora, como `manifestacao.limite_hora`.** A
manifestação é um ato que o usuário pode querer repetir para demandas diferentes; atualizar o
acervo é o mesmo ato repetido sobre o mesmo dado, e uma espera diz ao titular exatamente quando
vale a pena pedir de novo.

---

## D78 · As preferências da demanda viram perguntas a quem manifesta interesse, e o cartão deixa de dar nota ao que não é grau

`24/09/2026` · E8 · `src/Support/RespostaInteresse.php`, `src/Service/ManifestacaoService.php`,
`src/Service/InteressadoService.php`, `src/Service/PreferenciaService.php`,
`_arq/migracoes/2026-09-24-d78-preferencias-da-demanda.sql`

**Contexto.** O cartão de compatíveis mostrava as seis dimensões como porcentagem, e duas delas
quase sempre saíam como "Não medida nesta sessão". A causa estava no desenho, não no cálculo:
tipo de contrato e região de atendimento (que a tela chamava de "disponibilidade", nome que
enganava) só existiam se o profissional tivesse preenchido a aba Preferências **antes**, sem saber
que alguma demanda ia perguntar aquilo. E a demanda podia sair com contrato "A combinar", que
gravava vazio. Além disso, só a competência é grau de verdade: localização vale 100, 60 ou 0;
área, contrato e região valem 0 ou 100; experiência vale 50 ou 100. Mostrar "Localização 60%"
dava uma precisão que o cálculo não tem, e somava notas na tela que o item 10.1 manda evitar. A
mensagem de quem manifesta interesse chegava à empresa, mas a equipe não sabia onde.

**Decisão.** Quatro mudanças, aprovadas pela equipe:

1. **O cartão só dá grau à competência técnica.** As outras dimensões viram frases ("Já tem obra
   registrada em Manaus", "Aceita contrato PJ", "Atende no AM"), e o que não foi informado vira uma
   nota discreta, não uma linha "não medida". "Por que este perfil é compatível" fica só com a
   competência e o documento que a sustenta.
2. **A empresa declara o que prefere ao publicar**: regime de contrato (sem a opção "A combinar";
   o padrão é "qualquer regime") e o prazo de início (`dem_inicio_ate`, data ou em aberto).
3. **Quem manifesta interesse responde**: se aceita o regime (sim, não ou prefere conversar), se
   atende a região da obra, e quando pode começar. Pergunta só o que a demanda pede; início sempre.
   Pergunta feita é resposta obrigatória. As respostas ficam na manifestação (`man_aceita_contrato`,
   `man_atende_local`, `man_inicio_em`). Uma caixa **desmarcada** deixa o profissional usar as
   respostas para completar o perfil: acrescenta a região às que ele atende e grava o regime só se
   ele ainda não tinha um. Nunca substitui o que ele declarou.
4. **A empresa vê o quadro "o que pediu × o que respondeu"** em Interessados e na conversa, com a
   mensagem em destaque. Só quando foi o candidato que manifestou: interesse registrado pela
   própria empresa (D70) não passa pelo formulário, e "não respondeu" ali seria uma acusação falsa.

**As respostas não entram no motor.** O conjunto de compatíveis é montado antes de alguém
manifestar, e deixar a resposta mudar a nota permitiria entrar respondendo "sim" a tudo. Elas
servem à decisão da empresa na hora de avaliar quem se apresentou, e por isso viram "atende" ou
"não atende", nunca porcentagem, e a lista de interessados não é ordenada por elas.

**Alternativa recusada: tirar o autodeclarado do cálculo.** Resolveria o "não medida" de uma vez,
mas o item 3.2 do edital pede que a compatibilização considere experiências declaradas. O
autodeclarado continua na conta quando existe no perfil (30% do peso); o que muda é como aparece.

**Alternativa recusada: recalcular a compatibilidade com as respostas da manifestação.** Daria à
empresa um número por interessado, e um número por pessoa numa lista é ranking com outro nome.

**Consequência.** As respostas passam a ser obrigatórias no formulário, e a suíte de ponta a ponta
(`e2e/apoio/acoes.js`) as preenche pelo `name` do campo. Banco criado antes desta decisão precisa
da migração em `_arq/migracoes/`, que é idempotente; quem sobe do `estrutura.sql` já tem as colunas.

---

## D79 · A demonstração é povoada pelos serviços da plataforma, e a vitrine é limpa por encerramento

`24/09/2026` · E8 · `scripts/semear-demandas.php`

**Contexto.** Para testar a D78 de verdade faltava dado: havia duas demandas reais, duas
manifestações, e a vitrine de demandas abertas mostrava 75 "Demandas de verificação" que o
`verificar-e4.php` deixa a cada execução, na conta da equipe. Qualquer profissional via primeiro
o lixo de teste. A equipe pediu para povoar "usando a API, que reflete a realidade".

**Decisão.** Dividir pelo que cada fonte tem. **Candidatos pela API**: 30 profissionais e 9
empresas pelo `semear-candidatos.php`, o fluxo real do cadastro (cerca de 138 chamadas,
autorizadas pela equipe, abaixo do volume que pareceria varredura). **Demandas e interesses pelos
serviços da plataforma**, porque não existem na API: o `semear-demandas.php` cria, escolhe as
atividades e publica pelo `DemandaService`; monta o conjunto pelo `CompatibilizacaoService`, como a
empresa vê; e manifesta pelo `ManifestacaoService`, com as respostas da D78 variando entre os cinco
casos que a empresa encontra (aceita tudo, prefere conversar, não atende a região, não aceita o
regime, começa depois do prazo). As atividades de cada demanda foram escolhidas depois de olhar o
acervo, nunca antes, porque os vínculos ART → TOS da massa são aleatórios.

A vitrine foi limpa pelo mesmo `encerrar()` do botão da demanda: as 75 saem da vitrine, a trilha
registra, e nada é apagado.

**Alternativa recusada: inserir demandas e manifestações por SQL.** Seria mais rápido e produziria
dado quebrado do mesmo jeito que a D66 descreve para candidatos: sem auditoria, sem snapshot do
perfil, sem e-mail, sem sessão do motor, e sem passar pela validação que a tela aplica.

**Alternativa recusada: recarregar o banco para limpar a vitrine.** Apagaria os candidatos que
custaram chamadas registradas, e documento da massa usado uma vez fica consumido (D15).

**Consequência.** O `verificar-e4.php` continua criando demandas de verificação a cada execução; o
`semear-demandas.php` as encerra de novo quando roda. Os e-mails de aviso das 44 manifestações
foram para a fila local (Mailpit), como iriam de um clique.

---

## D80 · O rastro dos verificadores sai da vista pelos serviços, depois da bateria, e não é apagado

`25/09/2026` · E8 · `scripts/limpar-rastro-de-verificacao.php`, `src/Repository/DenunciaRepository.php`

**Contexto.** Os verificadores provam o fluxo criando dado de verdade (D66): o `verificar-e4.php`
publica "Demandas de verificação" e o `verificar-e6.php` abre denúncias, a cada execução. Em
25/09 a fila de moderação tinha 39 denúncias pendentes, todas de teste, e a vitrine já tinha
chegado a 75 demandas de teste (D79). Quem abrisse o painel na demonstração via primeiro o lixo.

**Decisão.** Um script que roda depois da bateria e resolve pelo caminho da tela: demanda de
verificação é encerrada pelo `DemandaService::encerrar`; denúncia de verificação é tratada como
**improcedente** pelo `DenunciaService::tratar`, a providência que não atinge ninguém, com o
administrador como moderador e a trilha registrando. Para não alcançar dado real, exige as duas
coisas juntas: conta de verificação (`camila@`, `cobaia@` ou `@verificacao.local`) e o texto exato
que o verificador escreve.

**Alternativa recusada: cada verificador apagar o que criou ao terminar.** A regra é que nada se
apaga (8.6j), a auditoria é insert-only, e um verificador que desfaz o que fez deixa de provar que
o dado persiste. Também deixaria de valer quando o verificador falha no meio, que é justamente
quando o rastro mais aparece.

**Alternativa recusada: excluir as linhas por SQL.** Mesmo motivo, e a trilha ficaria apontando
para denúncias que deixaram de existir.

**Consequência.** A ordem antes de uma demonstração passa a ser: bateria de verificação, depois
`limpar-rastro-de-verificacao.php`. O `semear-demandas.php` continua encerrando as demandas de
verificação que encontra, e as duas limpezas convivem sem conflito.

---

## D83 · A busca e o perfil público têm teto por IP, contado em arquivo e não em tabela

`25/09/2026` · véspera do Demo Day · `src/Support/LimiteDeRequisicoes.php`, `public/index.php`,
`templates/erro.html.twig`, `tests/Support/LimiteDeRequisicoesTest.php`

**Contexto.** A busca de profissionais é a única tela aberta a quem não tem conta (Anexo I item
3), e o perfil público por identificador é o passo seguinte dela. A visibilidade por campo decide
o que cada visitante vê, mas nada limitava quantas vezes ele podia pedir: um script percorria a
busca e `/perfil/1`, `/perfil/2`... e levava tudo o que os titulares abriram, em minutos. É a
coleta automatizada que o item 10.4 veda, e um limite que a proposta prometeu e não estava no
código.

**Decisão.** Teto por IP, com janela deslizante de 60 segundos, verificado no front controller
depois da autorização e antes do controller:

- `/profissionais`: 30 por minuto (`LimiteDeRequisicoes::BUSCA_MAXIMO`). Três vezes o ritmo de uma
  pessoa refinando a busca; a demonstração faz menos de dez.
- `/perfil/{id}`: 60 por minuto (`PERFIL_MAXIMO`), porque cada busca leva a vários perfis.

As duas contagens são separadas. Ao estourar, HTTP 429 com `Retry-After` e a tela de erro do
produto com um caso novo ("Muitas consultas seguidas"), que diz o tempo de espera em segundos.

**Quatro escolhas dentro da decisão:**

1. **O IP é o `REMOTE_ADDR` que o nginx repassa**, por `Requisicao::ip()`, que já ignorava
   `X-Forwarded-For`. Conferido por curl: com a contagem cheia, um pedido com `X-Forwarded-For`
   inventado continua recebendo 429.
2. **Recusa não conta.** Quem insiste durante a espera não a empurra para a frente, e o
   `Retry-After` é exato.
3. **Na falha de disco, libera** e registra no log. Recusar tudo derrubaria a busca pública por um
   problema de permissão num clone limpo, contra o item 8.8.
4. **O IP não fica em claro no disco**: o arquivo tem o nome do SHA-256 da chave e guarda só
   instantes. Contagens de quem não aparece há duas janelas são removidas de tempos em tempos; é
   cache descartável, não registro de negócio, e a regra de exclusão lógica não se aplica.

**Alternativa recusada: tabela de contagem no MariaDB.** Seria o caminho de `manifestacao.limite_hora`,
mas pede mudança de estrutura na véspera, com outra frente mexendo em privilégios do banco, e põe
uma escrita no banco a cada busca anônima. Arquivo em `storage/cache/limite/` com `flock`
exclusivo resolve a concorrência sem esquema novo.

**Alternativa recusada: janela fixa por minuto cheio.** Deixa passar o dobro do teto na virada
do minuto.

**Alternativa recusada: limitar só o anônimo.** Quem entra vê mais campos (alcance
`AUTENTICADO`), então a conta logada é o coletor mais valioso, não o menos.

**Limitações declaradas.** O teto é por IP: usuários atrás do mesmo NAT dividem a cota, e um
coletor com muitos IPs não é contido por ele. Os valores ficam em constante, e não em
`sis_parametros`, porque entrar na tela de Integrações pediria linha nova na carga inicial.
Na máquina da demonstração todo pedido do host chega com o IP do gateway do Docker, então a
suíte do navegador e a apresentação dividem a mesma contagem, folgada para as duas.

**Verificação.** `tests/Support/LimiteDeRequisicoesTest.php` (9 testes). Por curl em 25/09: 30
buscas seguidas deram 200 e a 31ª deu 429 com `Retry-After: 59`; a busca voltou a 200 sozinha
depois da janela; cinco buscas no ritmo da demonstração, todas 200; o 61º perfil seguido deu 429
enquanto a busca seguia em 200.

---

## D82 · A aplicação conecta ao banco com um usuário sem DELETE e sem estrutura, e a trilha é insert-only também por privilégio

`25/09/2026` · véspera do Demo Day · `_arq/usuarios.sh`, `_config.php`, `.env.example`,
`docker-compose.yml`

**Contexto.** A aplicação conectava com o usuário `prolink`, o que o contêiner do MariaDB cria a
partir de `MARIADB_USER`, com `ALL PRIVILEGES` em `prolink.*`. A proposta promete trilha de
auditoria imutável, e os triggers `trg_aud_bloqueia_update` e `trg_aud_bloqueia_delete` só
protegem contra quem não pode removê-los: com aquele usuário, uma injeção de SQL que escapasse
dos prepared statements, ou um controller comprometido, podia rodar `DROP TRIGGER` e depois
`DELETE FROM sis_auditoria`, ou `DROP TABLE` qualquer coisa. O menor privilégio estava prometido
e não estava no banco.

**Decisão.** Dois usuários. A requisição web conecta como `prolink_app` (`DB_APP_USERNAME`), que
tem, tabela por tabela: `SELECT, INSERT, UPDATE` em todas; **só `SELECT, INSERT` em
`sis_auditoria`**; `SELECT` na view `crea_evidencias`. Nenhum `DELETE`, porque o `src/` não tem
`DELETE` em lugar nenhum (exclusão é lógica, 8.6j), e nenhum privilégio de estrutura (`CREATE`,
`ALTER`, `DROP`, `INDEX`, `TRIGGER`, `REFERENCES`, `GRANT`). O `prolink` continua existindo como
administrativo, para migração, povoamento e verificadores.

O usuário nasce em `_arq/usuarios.sh`, montado como terceiro arquivo de
`docker-entrypoint-initdb.d`, depois da estrutura e da carga. A senha vem de `DB_APP_PASSWORD`
no `.env`; sem ela o script recusa e a inicialização para, em vez de criar usuário sem senha. A
lista de tabelas sai do `information_schema`, então tabela nova no `estrutura.sql` já nasce com o
privilégio padrão. O script é idempotente (`REVOKE ALL` e concede de novo) e serve também para o
banco que já existe.

`_config.php` escolhe: requisição web usa sempre o restrito; linha de comando usa o
administrativo, e `DB_CONEXAO=app` força o restrito para provar que um script vive sem
privilégio.

**Alternativa recusada: `GRANT SELECT, INSERT, UPDATE ON prolink.*` e revogar o `UPDATE` só na
auditoria.** O MariaDB não faz revogação parcial de privilégio de banco: o `UPDATE` em `prolink.*`
vale para `sis_auditoria` e não existe `REVOKE` que a exclua. Por isso o privilégio é por tabela.

**Alternativa recusada: um usuário só, restrito, também para os scripts.** Os verificadores
`verificar-e2.php` e `verificar-e4.php` limpam o próprio rastro com `DELETE`, e as migrações
precisam de `ALTER`. Dar esses privilégios ao usuário da web desfaria a decisão; trocar os
scripts na véspera, sem necessidade, arriscaria a bateria. A fronteira que importa é a da rede:
quem roda script já está dentro do contêiner e lê o mesmo `.env`.

**Alternativa recusada: criar o usuário no `carga-inicial.sql`.** Arquivo SQL de inicialização
não lê variável de ambiente, e a senha teria de estar escrita nele, o que o Anexo VI veta.

**Consequência.** Tabela criada por migração depois da subida não tem privilégio até rodar o
`usuarios.sh` de novo (o `_arq/README.md` diz como). O `.env` passa a ter uma senha a mais. O
arquivo vai com bit de execução e shebang: no Docker Desktop do macOS o entrypoint trata qualquer
arquivo montado como executável, e um `.sh` sem o bit falhou com "Permission denied" no teste de
clone limpo. Um `.env` anterior à D82, sem `DB_APP_USERNAME`, cai no administrativo para não
derrubar ninguém: é degradação conhecida, não silêncio, e está escrita aqui e no `_config.php`.
O `verificar-e1.php` segue conferindo o trigger (roda como administrativo na linha de comando).

**Verificação.** Em 25/09, no banco local já existente, sem recriar o volume: 29 concessões por
tabela. Como `prolink_app`: `UPDATE` e `DELETE` em `sis_auditoria`, `DROP TRIGGER`, `DELETE` em
`sis_usuarios`, `ALTER TABLE`, `CREATE TABLE`, `DROP TABLE`, `TRUNCATE` e `GRANT` recusados com
erro 1142; `SELECT` na view respondeu. As requisições web conectaram como `prolink_app`
(estatística por usuário do MariaDB, zero acesso negado) e os seis cenários do Anexo I em
`e2e/specs/cenarios.spec.js` passaram no desktop, com `seguranca.spec.js`. Clone limpo simulado
num MariaDB 10.11 descartável com os três arquivos de inicialização: usuário criado, 2000 códigos
TOS legíveis, `UPDATE` na auditoria recusado; com `DB_APP_PASSWORD` vazio a inicialização parou
com a mensagem do script. PHPUnit: 225 testes, 974 asserções, verde.

---

## D81 · HTTPS local com TLS 1.2 ou superior, certificado que nunca falta e HSTS de cinco minutos

`25/09/2026` · véspera do Demo Day · `docker/nginx/default.conf`, `docker/nginx/Dockerfile`,
`docker/nginx/escolher-certificado.sh`, `docker-compose.yml`, `scripts/gerar-certificado-local.sh`,
`.gitignore`, `e2e/playwright.config.js`, `README.md`

**Contexto.** A proposta prometeu transporte cifrado, e o ambiente só falava HTTP na 8080. A banca
sobe o projeto com um único `docker compose up` a partir de um clone limpo (item 8.8), e chave
privada não pode estar no repositório (Anexo VI). As duas coisas juntas proíbem o caminho óbvio,
que seria versionar um certificado.

**Decisão.** O nginx serve HTTPS na **8443** (mesma porta dentro e fora do contêiner, para o
redirecionamento valer dos dois lados) com `ssl_protocols TLSv1.2 TLSv1.3` e as suítes do perfil
*intermediate* da Mozilla. A **8080** continua de pé e só redireciona para o HTTPS. O certificado
nunca falta: a imagem do nginx (agora construída por `docker/nginx/Dockerfile`) fabrica no build
um autoassinado de reserva, e a cada subida `escolher-certificado.sh` usa o do mkcert quando
`scripts/gerar-certificado-local.sh` o gerou em `docker/nginx/certs/` (fora do versionamento), ou
o de reserva quando não. Um `server` só ouve nas duas portas, para a CSP continuar existindo num
lugar só, que é o que `verificar-padrao.php` compara com a política servida.

- **HSTS com `max-age=300`**, sem `includeSubDomains` e sem `preload`, só na resposta HTTPS. HSTS
  vale para o host inteiro, sem distinguir porta: um ano em `localhost` obrigaria o navegador a
  usar https em todo serviço local da máquina (Mailpit na 8025, qualquer outro projeto) até
  alguém limpar à mão. Em produção, com domínio próprio, o valor seria `31536000`.
- **Redirecionamento 307**, não 301: o 301 fica em cache no navegador sem prazo e seguiria
  mandando a 8080 desta máquina para a 8443 depois do Demo Day. O 307 também preserva o método.
- **Exceção do nome interno `nginx`**: os verificadores rodam no contêiner php e chegam por
  `http://nginx`, numa rede do Docker que não sai da máquina. Esse Host é atendido em HTTP sem
  redirecionar; o navegador nunca o envia, e quem forja o cabeçalho só deixa em claro a própria
  conexão.

**Alternativa recusada: versionar um certificado autoassinado.** Subiria no clone limpo, mas
poria uma chave privada no repositório, que o Anexo VI veda, e a mesma chave em toda máquina.

**Alternativa recusada: gerar o certificado na subida com `apk add openssl`.** A imagem oficial
não traz o openssl de linha de comando, e instalar na subida faria o `docker compose up` depender
de rede a cada início. No build, a dependência de rede é a mesma que o contêiner php já tem.

**Alternativa recusada: HSTS de um ano "porque é o recomendado".** É o recomendado para domínio
de produção; em `localhost` prende a máquina de quem avalia.

**Consequência.** `APP_URL` precisa passar a `https://localhost:8443` (`.env`, `.env.example` e o
padrão de `_config.php`), e o contêiner php ser recriado (`docker compose up -d --no-deps php`)
para reler o `.env`. Sem isso, a página servida em HTTPS monta CSS, JS e `action` de formulário
com `http://localhost:8080`, e a CSP (`'self'`, `form-action 'self'`) bloqueia tudo: a tela abre
sem estilo e o login não sai. Para o navegador abrir sem alerta, uma vez por máquina:
`mkcert -install` e `./scripts/gerar-certificado-local.sh`. A suíte de ponta a ponta aponta para
`https://localhost:8443` e aceita o certificado local.

**Verificação.** `curl -v` na 8443: TLSv1.3 negociado, certificado do mkcert verificado contra a
autoridade local, `Strict-Transport-Security: max-age=300` e a CSP de sempre. Forçando TLS 1.2,
conecta; TLS 1.0 e 1.1 recebem do servidor o alerta `protocol version` (70). A 8080 devolve `307`
para `https://localhost:8443` com o mesmo caminho e consulta. `http://nginx` de dentro do
contêiner php devolve 200 sem HSTS. `nginx -t` passou com e sem certificado montado, que é o
clone limpo. `verificar-padrao.php`: a política servida é a do arquivo versionado.

**Correção da verificação (25/09, mesma véspera).** A primeira passada deixou o `APP_URL` em
`http://localhost:8080` e o `docker-compose.yml` sem as linhas da D82 no mariadb. No navegador,
a página em HTTPS saiu sem estilo e o login não enviava (CSP); num clone limpo, o `prolink_app`
não existia e `/saude` dava 503. Corrigido: `APP_URL=https://localhost:8443` no `.env`, no
`.env.example` e no padrão do `_config.php`; no mariadb, `DB_APP_USERNAME`, `DB_APP_PASSWORD`
com `:?` (o compose recusa subir com a senha vazia) e o volume `03-usuarios.sh`. Conferido de
novo: fumaça pelo navegador em https://localhost:8443 (landing, login da empresa, busca,
demandas, compatíveis, perfil) com 3 de 3 folhas de estilo e zero erro de console; clone limpo
com `.env` saído do `.env.example` sobe, provisiona o `prolink_app` com 29 concessões e
responde `/saude` 200 com `tos_carregada: 2000`.
