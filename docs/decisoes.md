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
| E2 — integração com a API | D13, D14, D15, D16, D17, D18, D19, D20 |

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
