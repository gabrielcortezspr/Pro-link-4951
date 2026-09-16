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
