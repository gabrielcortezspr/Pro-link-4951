# Backlog de desenvolvimento

Sequência única de trabalho até a entrega. Ordem por dependência e por cenário de demonstração:
cada etapa termina com algo que roda ao vivo. O que está aqui substitui a tabela que existia em
`_arq/arquitetura.md`.

**Definição de pronto do MVP:** os seis cenários mínimos do Anexo I, item 7, rodando de ponta a
ponta na demonstração. Cada etapa abaixo diz qual cenário destrava.

**Fontes:** `edital-requisitos.md` (RFs, segurança, entrega, rubrica) e `proposta-fase1.pdf`
(diferenciais prometidos, MoSCoW, cinco operações atômicas).

## Calendário

Entrega **17/09 às 18h**. Onze dias a partir de 07/09. As datas são referência; a ordem é o que
importa — se uma etapa atrasar, a seguinte espera, não pula.

| Etapa | Dias | O quê | Destrava | RF |
|---|---|---|---|---|
| E0 | 07 | fundação que faltou | — | — |
| E1 | 07–08 | identidade, consentimento, auditoria | cenários 1, 2, 5 (parte) | RF01 |
| E2 | 09–10 | integração com a API e portfólio | **cenário 1** | RF02, RF03 |
| E3 | 11 | demandas | **cenário 2** | RF04 |
| E4 | 12–13 | motor de compatibilização | **cenário 3** | RF04 |
| E5 | 14 | manifestação, mensagens, e-mail | **cenário 4** | RF05, RF07 |
| E6 | 15 | denúncias e painel administrativo | **cenários 5 e 6** | RF06 |
| E7 | 16–17 | endurecimento, documentação, demo, entrega | todos, ao vivo | — |

Divisão sugerida: Gabriel puxa E2 e E4 (API e motor); Camila puxa consentimento e auditoria em
E1, E6 inteira e a revisão de segurança de E7. O resto em dupla. RF01–RF05 são Must; RF06 e RF07
são Should — mas os cenários 5 e 6 são obrigatórios na demo, então E6 não é opcional de verdade.

---

## E0 — Fundação que faltou (07/09, meio dia)

> **Concluída** em 06/09, commit `502f5f4`. Critério verificado por HTTP em 08/09: 401 anônimo,
> 403 de perfil errado com linha em `sis_auditoria`. O repositório no GitHub existe.

Coisas pequenas que toda etapa seguinte usa. Fazer antes de qualquer RF.

- **Repositório no GitHub** e push. O edital exige o endereço na plataforma com histórico
  completo (8.7, 8.8). Quanto antes existir, mais histórico a banca vê.
- `Support/Auditoria::registrar(acao, entidade, id, campo, antes, depois)` — todo serviço
  escreve por aqui. Insert-only já está garantido pelo trigger.
- `Support/Sessao` — quem está logado, perfil, expiração por inatividade, regeneração de id no
  login. Middleware no front controller que lê os perfis da rota e barra quem não pode.
- `Support/Requisicao` — IP e user agent num critério só, para auditoria e força bruta.
- `Support/Flash` — mensagens de sucesso/erro entre requisições.
- `templates/layout/_form.html.twig` — macros de campo com erro, label, `_csrf` embutido.
- `tests/` com PHPUnit rodando: um teste de `Tos::afinidade` e um de `Crypto`. Não é exigido,
  mas "maturidade" vale 10 pontos e custa vinte minutos.

**Pronto quando:** `composer test` passa; rota marcada `ADMIN` devolve 401 para anônimo e 403
(com registro em `sis_auditoria`) para usuário de outro perfil.

---

## E1 — Identidade, consentimento e auditoria (07–08/09)

> **Concluída** em 08/09. Critério verificado por `php scripts/verificar-e1.php`: 71
> verificações por HTTP, todas passando. Decisões D07, D09, D10, D11 e D12. Fora da lista
> original: validação de CPF/CNPJ e separação da massa cadastrável (D07), e o próprio script de
> verificação (D12). `estrutura.sql` mudou — `con_dt_concessao` aceita nulo (D10); banco antigo
> se ajusta com um `ALTER`, sem recriar o volume.
>
> **Auditoria da etapa, resgatada em 14/09** (D30, D31): duas regras do `CLAUDE.md` que o próprio
> código desobedecia — SQL fora de repositório e `session_start()` fora de `Sessao` — passaram a
> valer. Nasceram `AuditoriaRepository` e `SaudeRepository`.

**Cobre:** RF01; edital 8.5a/b/f/g, 11.3; proposta cenário 01 (base).

- `Repository/UsuarioRepository`, `Service/AutenticacaoService`:
  cadastro com `password_hash()` (Argon2id), login, logout, contador de tentativas e bloqueio
  temporário (`usu_tentativas`, `usu_bloqueado_ate`), sessão em `sis_sessoes` com hash do token.
- Cadastro nos quatro perfis: Profissional, Empresa, Terceiro PF, Terceiro PJ. CPF/CNPJ entram
  cifrados (`Crypto::cifrar`) com hash cego para unicidade. A validação na API vem em E2; aqui
  o cadastro guarda o documento e cria o usuário.
- **Aceite de Termos e Política** (`sis_termos` versionado) e **consentimentos por finalidade**
  (`sis_consentimentos`: `CONSULTA_API`, `EXIBICAO_PERFIL`, `NOTIFICACOES`). Sem aceite não
  cria conta.
- Recuperação de senha: token em `sis_recuperacoes`, e-mail via `NotificacaoService` (versão
  mínima já aqui, apontando para o Mailpit; RF07 completa em E5).
- Painel de privacidade do usuário: revogar consentimento, exportar meus dados (JSON),
  solicitar exclusão → `usu_status = 'X'` e sessões encerradas. Atende descarte, portabilidade
  e revogação do item 11.3.
- Toda operação acima registra em `sis_auditoria` com IP e user agent.

**Pronto quando:** cada perfil faz cadastro → login → logout; cinco senhas erradas bloqueiam
por 15 minutos; a exportação devolve JSON; `sis_auditoria` mostra tudo isso.

---

## E2 — Integração com a API e portfólio (09–10/09)

> **Em andamento.** Prontos: o transporte injetável do `CreaApiClient` (D08, D13, D14, D16) e o
> `PortfolioService` com a operação atômica 1 — importação do acervo, associação de ART à mão,
> mescla que não apaga campo preenchido e Selo ART cobrindo as atividades (D17, D18). Verificado
> por `scripts/verificar-e2.php`: 138 conferências contra o banco, sem gastar chamada da API.
>
> A herança pelo CAO ficou destravada de graça: a regra é binária e a view já a implementa (D19).
>
> Também pronto: **cadastro de Profissional consultando a API** (`PerfilCreaService`, D20), com os
> três desfechos da consulta e importação do acervo no mesmo fluxo. Conferido de ponta a ponta
> pelo formulário contra a API real: PEDRO HENRIQUE ALVES entrou com RNP `0412340046`, modalidade,
> 2 ARTs seladas e `prf_em_construcao = 1` — o limiar de 3 ARTs funcionando sem ninguém forçar.
>
> Também pronta a **metade da empresa** (D24, D25, D26): `EmpresaCreaService` com os mesmos três
> desfechos do profissional, quadro técnico de `crea_quadro_tecnico`, acervo pelo CAO gravado sob
> o RNP de quem registrou, tela `/perfil` própria e o portão de visibilidade da empresa. A
> herança da D19 passou a ser verificada de ponta a ponta: encerrar o vínculo tira o acervo da
> empresa e não toca no do profissional.
>
> A revisão de segurança do fim da sessão achou duas falhas na tela de visibilidade — herdadas
> da D22 e agora corrigidas na D27: o POST aceitava alvo que não era do titular, e um nível
> inválido no meio do lote deixava metade das escolhas gravadas. Conferido com requisição forjada,
> antes e depois.
>
> Pronta também a **experiência autodeclarada** (D29): repositório, serviço, formulário na própria
> `/perfil` e o bloco `.dado-declarado` ao lado — nunca dentro — do acervo verificado. A ART
> vinculada é conferida contra o acervo do titular, e o número dela respeita a visibilidade da
> própria ART. Conferir isso expôs dois defeitos: o `<select>` montado com `merge` no Twig mandava
> o `art_id` errado, e `pro_visibilidade` duplicava linha a cada clique porque `uq_vis_alvo` não
> segura coluna nula (D28) — este último fazia a tela de privacidade **descartar alterações em
> silêncio**.
>
> Falta, na ordem: perfil público `/perfil/{id}`, CATs e `sincronizar-status.php`.

**Cobre:** RF02, RF03; edital 8.4, Anexo I item 6; proposta cenários 01 e 03A, diferenciais 2
(dado verificado) e "perfil em construção". **Destrava o cenário 1.**

- No cadastro de Profissional: `CreaApiClient::profissionalPorCpf` → grava `prf_rnp`,
  `prf_registro_crea`, `prf_nome_api`, `prf_status_api`, modalidades. CPF inexistente na API
  → cadastro segue como Terceiro PF, com aviso. Empresa: `empresaPorCnpj` → `pro_empresas`.
  > **Pronto** (D24, D25, D26): `EmpresaCreaService` espelha o `PerfilCreaService` — três
  > desfechos no vocabulário comum de `Support\DesfechoCrea`, guarda de registro já vinculado,
  > rebaixamento para Terceiro PJ no `200 []` e `emp_dt_sincronizacao` carimbada só na
  > sincronização completa. Três chamadas por empresa: CNPJ, quadro técnico e CAO. A empresa não
  > tem situação na API, então o portão de visibilidade dela é consentimento + validação
  > pendente — limitação declarada na D26, não dissimulada.
- **Importação automática das ARTs** no cadastro (proposta, jornada fase 02): pagina
  `artsDoProfissional`, grava `crea_arts` + `crea_art_atividades`, calcula `art_hash`. Mesmo
  para CATs via `catsDoProfissional` + `validarCat` para os vínculos.
- `Service/PortfolioService::associarArt(rnp, numero)` — **operação atômica 1** da proposta:
  `validarArt` → `atividadesDaArt` → grava ART, atividades e selo numa transação. `null` da
  API = "essa ART não é sua", `NaoEncontradoException` = "RNP não existe". Mensagens distintas.
- **Selo ART**: na exibição, recalcula o HMAC da linha e compara com `art_hash`. Confere →
  selo; não confere → aviso de divergência e registro em auditoria. Nunca uma flag.
- **Defeito latente, acorda com a tela da RF03:** `associarArt` muda a contagem de ARTs e não
  atualiza `prf_em_construcao`, então quem associa ARTs à mão até passar do limiar continua
  marcado como iniciante. Hoje não afeta ninguém porque nenhuma rota chama o método. A correção
  mínima é chamar `PerfilCreaService::atualizarEmConstrucao` depois de associar — mas é o mesmo
  padrão frágil que já falhou uma vez, e a alternativa é derivar a marca na leitura em vez de
  guardá-la em coluna, como a D01 fez com o índice de evidência. Decidir junto com a tela.
- Experiência autodeclarada (`pro_experiencias`), com ou sem ART. Template mostra dado da API
  e dado declarado com estilos distintos (`.selo-art` / `.dado-declarado`).
  > **Pronta** (D29). Sem ART é o caso principal, não a exceção. Com ART, a posse é conferida —
  > a chave estrangeira garante que a ART existe, não que é sua. Edição versiona antes e depois
  > em `sis_auditoria` (operação atômica 4 da proposta), e exclusão é lógica.
  > **Só o profissional tem**: `exp_prf_id` referencia `pro_profissionais`, porque quem tem
  > trajetória é a pessoa — a empresa tem quadro técnico.
- Visibilidade granular (`pro_visibilidade`): por campo do perfil e por ART. **Nada público
  por padrão.** Perfil público em `/perfil/{id}` respeita isso.
  > **Pronta e em uso** (D22): `Support\Visibilidade` (regras), `Support\Visao` (decisão em
  > memória), repositório, serviço com os dois portões globais e os controles na tela do perfil.
  > 17 testes. Falta o `fecharTudo` no lado da sincronização de status e o perfil público, que é
  > onde a `Visao` passa a filtrar para um espectador que não é o dono.
- **Herança de acervo pelo CAO** (`PortfolioService::importarCao`): a árvore da Certidão de
  Acervo Operacional achatada por `Support\Cao`, e cada ART gravada sob o RNP de quem a
  registrou — nunca sob a empresa (D24). Quem liga as duas identidades é a view
  `crea_evidencias`, e só ela.
  > **Pronta.** A regra binária da D19 deixou de ser só uma linha de SQL: `verificar-e2.php`
  > encerra o vínculo, confere que a evidência da empresa vai a zero e que a da profissional não
  > muda, e revalida para trazer tudo de volta sem duplicar linha.
- Perfil em construção: menos de `match.early_career.min_arts` ARTs → `prf_em_construcao = 1`
  e sinalização visual. Nunca sai do pool. **Não vale para empresa**: quadro técnico pequeno não
  é início de carreira, e rotular isso seria inventar informação.
- **Tela `/perfil` pronta**: acervo com Selo ART reconferido a cada exibição, marca de perfil em
  construção, aviso de perfil fechado, controles de visibilidade por campo e por ART, e o botão
  que resolve a pendência da D20 e da D21 (`validar meu registro` / `importar minhas ARTs`).
  Conferida no navegador contra dado real da API; sem rolagem horizontal a 390px.
- `scripts/sincronizar-status.php`: reconsulta `profissionalPorCpf` (decifrando o CPF) para
  quem passou de `api.sincronizacao.horas`; `pro_status != 'A'` zera visibilidade. Sem cron
  na entrega — a banca roda o script; documentar como rotina agendável.

**Pronto quando:** cenário 1 roda — profissional cria perfil, ARTs aparecem com selo,
adiciona uma experiência declarada, fecha um campo, o perfil público reflete.

---

## E3 — Demandas (11/09)

**Cobre:** RF04 (cadastro, publicação, acompanhamento, encerramento); proposta cenário 02.
**Destrava o cenário 2.**

- `Repository/DemandaRepository`, `Service/DemandaService`: criar, editar, publicar, encerrar.
  Situações `ABERTA`, `COM_INTERESSADOS`, `ENCERRADA`. Empresa e Terceiro publicam.
- **Seleção de códigos TOS** (`pro_demanda_tos`): busca textual sobre `crea_tos` com
  `Tos::normalizar`, e navegação grupo → subgrupo → obra/serviço. Peso 1.0 para principal,
  0.5 para secundária. Mostrar `tos_descricao` montada.
- Escopo, local (UF/município), tipo de contrato, alvo (profissional, empresa ou ambos).
- Painel do demandante: lista com situação e contagem de interessados.

**Pronto quando:** cenário 2 roda — empresa publica demanda com três códigos TOS, local e
requisitos, vê no painel, encerra.

---

## E4 — Motor de compatibilização (12–13/09)

> **Motor pronto e verificado em 14/09**, branch `claude/e4-motor`. A camada visível fica para o
> Gabriel: feed do demandante, explicação de compatibilidade no card e `/admin/sessoes/{id}`.
>
> Pronto: `Support\Compatibilidade` (as seis dimensões e a composição, 20 testes sem banco),
> `Support\Modalidade`, `Repository\Evidencia`, `Repository\Candidato`,
> `Repository\Compatibilizacao` e `Service\Compatibilizacao::executar()`, a **operação atômica
> 2** completa: lê os parâmetros, recorta o índice, pontua, filtra pelo limiar, sorteia pela
> semente e grava `mat_sessoes` mais `mat_sessao_pool` numa transação. Decisões D40 a D43.
> Critério verificado por `php scripts/verificar-e4.php`: 30 conferências contra o banco.
>
> A base foi povoada pelo fluxo real com `scripts/semear-candidatos.php`: 19 candidatos, 115
> linhas de evidência, 41 ARTs, 81 códigos TOS. Antes eram 2 candidatos, e um era conta de teste.
>
> `match.early_career.min_arts` **decidido em 3**, agora com medição: a distribuição real é 2 a 4
> ARTs, média 3,2. O limiar 3 marca 23% dos profissionais; 4 marcaria 62%, e rótulo que vale para
> a maioria não informa nada. O receio registrado aqui, de que 3 marcasse metade da plataforma,
> não se confirmou.
>
> **Revisão de 15/09 (commits `e0ada5a`, `0c417a6`): o motor rodava com quatro dimensões, não
> seis.** `prf_tipo_contrato` e `prf_disponibilidade` não eram escritas por caminho nenhum do
> código, e a dimensão de abrangência comparava a UF da demanda com um campo de texto livre, por
> igualdade exata. Os dois defeitos se escondiam: sem escrita, a comparação errada nunca era
> exercida; e dimensão nula sai da média sem reclamar, que é a regra que protege perfil
> incompleto. Corrigido com vocabulário fechado nos dois lados (D45), `PreferenciaService` dando
> a escrita, e o formulário no perfil. O portão de privacidade do pool, que era N+1, passou a
> resolver em lote.
>
> **Concluída em 16/09**, de `c901ead` a `3dcc455`. As três telas entraram: feed do demandante
> (`/demandas/{id}/compativeis`), busca ativa (`/profissionais`) e auditoria de sessões
> (`/admin/sessoes`). O motor deixou de ser inalcançável — `verificar-e4.php` não é mais o único
> caminho que o executa. Decisões D51 a D56.
>
> No caminho: GET relê a sessão gravada e POST recalcula, porque `executar()` sorteia semente nova
> a cada chamada e usá-lo no GET reembaralharia a lista sob quem está lendo; a ART fechada passou a
> contar para o match sem ser citada pelo número (D52); a busca e o perfil abriram ao anônimo
> (D54); e a auditoria mostra o pool como foi gravado, não como ficaria hoje (D55).
>
> Dado de demonstração povoado pelo fluxo real: `semear-candidatos.php` (14 candidatos, ~28
> chamadas da API), `abrir-visibilidade-demo.php` (visibilidade variada e determinística) e
> `preencher-declarados-demo.php` (as quatro dimensões autodeclaradas que saíam "Não medida").

**Cobre:** RF04 (compatibilização, pesquisa, filtros); edital 3.2, 10.1, 10.2, 12.2, 12.3;
proposta cenário 03, diferenciais 1 e 3. **Destrava o cenário 3 — o centro da avaliação.**
Desenho em `matching.md`; não reinventar aqui.

- `Service/CompatibilizacaoService::executar(demanda, usuario)` — **operação atômica 2**:
  1. lê pesos e limiar de `sis_parametros`;
  2. para cada código da demanda, consulta `crea_evidencias` por prefixo (`evi_nivel1..4`);
  3. score por dimensão: competência (afinidade × multiplicidade com raiz × bônus CAT), área
     (modalidade × grupo), localização, experiência declarada, contrato, disponibilidade —
     dimensão sem dado sai da média;
  4. filtra pelo limiar; gera semente; grava `mat_sessoes` e `mat_sessao_pool` com
     `msp_criterios` (score por dimensão + lista de ARTs/CATs que sustentam);
  5. devolve o pool **embaralhado pela semente**, sem score visível.
- Feed do demandante: cards sem posição, sem nota, sem "melhor". Cada card abre a explicação:
  "compatível porque ART AM…001 cobre TOS_x (mesma obra/serviço) e está na CAT 999001/2026".
- Busca ativa (Público, Empresa, Terceiro): especialidade (modalidade/grupo TOS), experiência,
  nome. Filtro "incluir perfis em construção", desligado por padrão.
- **Decidir o valor de `match.early_career.min_arts` antes de escrever estas duas telas.** Hoje
  vale `3`, número escolhido por nós: não vem do edital, e a proposta descreve o recurso quatro
  vezes sem citar quantidade. Ele não é cosmético — decide quem leva o rótulo no feed (onde
  ajuda, porque contextualiza) e quem some da busca ativa por padrão (onde atrapalha). A massa
  tem 290 ARTs para 100 profissionais, média 2,9: se a distribuição for uniforme, o limiar 3
  marca perto de metade da plataforma, e rótulo que serve para metade não informa nada. Não dá
  para medir a distribuição real sem consultar os 100 RNPs, o que o item 10.4 proíbe. O valor
  mora em `sis_parametros` e o administrador troca pelo painel, sem deploy.
- Perfil fechado (`EXIBICAO_PERFIL` revogado ou `prf_status_api != 'A'`) nunca entra no pool.
- Administrador: `/admin/sessoes/{id}` reproduz a sessão a partir da semente gravada.
- Testes do motor: afinidade, retorno decrescente, limiar, determinismo da semente.

**Pronto quando:** cenário 3 roda com candidatos cadastrados pelo fluxo real — feed aparece,
card abre e mostra ART, código e CAT. Mesma semente, mesma ordem.

---

## E5 — Manifestação, mensagens e notificações (14/09)

> **Concluída em 16/09**, de `26aad26` a `ce6a535`, cinco commits. O cenário 4 roda pelo navegador:
> o profissional manifesta, o e-mail chega no Mailpit e as duas partes conversam. Decisões D57 a
> D59.
>
> O gatilho da fila, herdado da E1, foi o primeiro passo e não o último: `despachar()` existia e
> nada o chamava, com 77 notificações paradas. Mora em `register_shutdown_function` porque o front
> controller sai por `exit` em seis caminhos, e roda depois de `fastcgi_finish_request()` para o
> SMTP não entrar no tempo de resposta.
>
> Três furos apareceram com a tela na frente, e os três eram do mesmo tipo — a regra existia e
> estava certa no serviço, e a interface não a conhecia: `dem_alvo` podia ser contornado pela
> vitrine, quem já tinha manifestado via o botão de manifestar, e o dono da própria demanda abria
> a tela de confirmação.
>
> **Falta só `ARQUIVADA`**: existe no vocabulário de `man_situacao`, não tem rota nem significado
> definido para quem manifestou. Botão sem destino é pior que ausência.

**Cobre:** RF05, RF07; proposta cenário 04, diferencial "snapshot". **Destrava o cenário 4.**

- `Service/ManifestacaoService::manifestar(demanda, usuario)` — **operação atômica 3**: grava
  `pro_manifestacoes` com `man_snapshot` (JSON do perfil visível + evidências) e hash, muda a
  demanda para `COM_INTERESSADOS`, enfileira notificação. Uma por (demanda, usuário).
- Demandante vê a lista de interessados e abre o perfil **como estava no snapshot**. Edição
  posterior do perfil não altera o que ele viu.
- `pro_mensagens`: canal simples dentro da manifestação, sem expor e-mail ou telefone.
- `NotificacaoService` completo: fila em `sis_notificacoes`, envio por PHPMailer com SMTP do
  `.env`, eventos cadastro / recuperação / manifestação / atualização de demanda / denúncia.
  Em desenvolvimento cai no Mailpit (`:8025`).
- **Gatilho da fila**, herdado da E1: `despachar()` existe e funciona, mas nada o chama — hoje o
  e-mail só sai à mão. Decidir entre cron no container e despacho pós-resposta, e pôr teto em
  `not_tentativas`: `pendentes()` hoje retenta uma falha permanente em toda execução.
- Rate limit de manifestações por usuário/hora (proposta, A04).

**Pronto quando:** cenário 4 roda — profissional manifesta, e-mail chega no Mailpit, empresa
abre o perfil e troca uma mensagem.

---

## E6 — Denúncias e painel administrativo (15/09)

> **Concluída** em 14/09, antecipada da data prevista porque não dependia da E3 nem da E4. Plano
> em `docs/sprint-2026-09-14-e6.md`, oito blocos; integrada em `integracao/design-e6`. Critério
> verificado por `php scripts/verificar-e6.php`: 19 conferências contra o banco, incluindo o render
> das três telas do painel. Decisões D39 a D43.
>
> Entrou: denúncia por qualquer conta autenticada, fila de moderação com filtro por situação, tela
> de detalhe, a **operação atômica 5** (tratar, bloquear a conta, revogar as sessões e registrar,
> numa transação) e a **trilha de auditoria** com filtro por conta, ação e período, paginação e
> severidade por evento. A tela de auditoria foi refeita pelo agente `designer-ui` depois de sair
> no padrão de dump de dados na primeira tentativa.
>
> Duas correções que nasceram aqui e valem para o projeto inteiro: o **relógio único** (D40), que
> conserta a comparação entre colunas gravadas por caminhos diferentes, e o **pipeline de padrão
> visual** (D42), que passa a barrar tela fora do padrão sem depender de revisão.
>
> A suposição da E7, de que o bloqueio só valeria no próximo login, estava errada e foi derrubada
> por medição (D39). Fica fora, por corte declarado: a lixeira com restauração, os indicadores da
> visão geral e o editor de parâmetros.

**Cobre:** RF06; edital 8.6j (lixeira), 8.5g; proposta cenários 05 e 06. **Destrava os
cenários 5 e 6.**

- Denúncia (`pro_denuncias`) sempre vinculada a um alvo: usuário, demanda, mensagem ou
  experiência. Tipo, descrição, evidência opcional.
- Painel `/admin`: fila de denúncias com `PENDENTE → EM_ANALISE → RESOLVIDA` e providências
  advertir / bloquear / remover / improcedente. Bloquear é a **operação atômica 5**: status,
  sessões encerradas, auditoria.
- Auditoria: listagem de `sis_auditoria` com filtro por usuário, ação, período. As ações do
  próprio admin aparecem — mostrar isso na demo.
- Indicadores: usuários por perfil, demandas ativas, manifestações, denúncias abertas.
- Parâmetros: editor de `sis_parametros` (pesos, limiar, early career). Supervisão humana do
  item 12.3 na prática.
- Lixeira: registros com `_status = 'X'`, com restauração.
- Edição de dados autodeclarados com versionamento em auditoria — **operação atômica 4**.

**Pronto quando:** cenários 5 e 6 rodam — usuário corrige um campo, restringe outro e denuncia;
admin vê a trilha, trata a denúncia e bloqueia.

---

## E7 — Endurecimento, documentação, demo e entrega (16–17/09)

> **Em andamento.** Dois itens fechados em 16/09, de `fef829c` a `dfe3762`:
>
> A **declaração de uso de IA, vieses e limitações** (12.3 e Anexo VI) entrou em
> `_arq/arquitetura.md`, em oito seções. Ela não existia — o documento era de 06/09, anterior a E2
> até E5, sem uma ocorrência de "viés". Declara que não há IA no produto, os vieses que a massa
> fictícia esconde e que os testes não conseguem detectar, as assimetrias entre o que o motor usa
> e o que a tela mostra, e o uso de IA na própria construção do sistema.
>
> **O front deixou de depender da rede** (D60), fechando a pendência que a D50 registrou e que
> ficou aberta por três sessões. Bootstrap e fontes servidos de `public/assets/vendor`, CSP em
> `'self'`, zero origens externas em qualquer tela. `dependencias.md` atualizado com as versões
> travadas do lock e as seis transitivas.
>
> **Sessão de 17/09 (madrugada), branch `claude/e7-lacunas`.** Fechou o que estava sem dono:
>
> · **MER** em `_arq/mer/` (8.3.2b), gerado do banco ao vivo, cinco diagramas no design system do
>   projeto. Ele levantou dois desvios de nomenclatura em `sis_auditoria`, declarados na D65.
> · **Texto real dos termos** (11.3), com a D03 e a D05 escritas em linguagem de titular, na carga
>   inicial e não só no banco daqui.
> · **Três buracos do painel**: indicadores da visão geral, que anunciava por escrito "os
>   indicadores entram em 15/09"; editor de `sis_parametros` (12.3, supervisão humana); e a
>   lixeira do 8.6j. Backend pronto e coberto, telas pelo `designer-ui`.
> · **`sincronizar-status.php`** (RF02), promessa do backlog da E2 desde 10/09.
> · **`prf_em_construcao`** volta a seguir a contagem de ARTs (defeito latente da E2, D63).
> · **Suíte de ponta a ponta** em `e2e/`: os seis cenários pelo navegador, mais a jornada completa
>   gravada em vídeo com legenda. D66.
> · **Definição de pronto** (`docs/definicao-de-pronto.md`) e **roteiro da demo**
>   (`docs/roteiro-demo.md`), que não existiam.
>
> Verificado: `verificar-e2` de 138 para 151 conferências, `verificar-e6` de 19 para 50, 188 testes
> de unidade, seis cenários verdes no navegador. Carga inicial conferida num MariaDB limpo.
>
> **Achado em aberto, com diagnóstico pronto: seis telas rolam na horizontal em 390px.** O Anexo VI
> cobra uso em dispositivo móvel como item de triagem. O padrão é um só, tabela larga sem
> contêiner de rolagem própria, e o excesso medido é:
>
> | Tela | Excesso | Elemento |
> |---|---|---|
> | `/admin/sessoes` | 781px | `table.pl-table.pl-log` e `div.pl-topbar-acoes` |
> | `/admin/auditoria` | 696px | idem |
> | `/demandas` | 194px | `table.pl-table` |
> | `/manifestacoes` | 54px | `table.pl-table` |
> | `/privacidade` | 3px | não isolado |
> | `/profissionais` | 2px | `article.pl-card` |
>
> `e2e/specs/responsivo.spec.js` trava isso no pipeline e **falha hoje**, de propósito: verde falso
> seria pior do que vermelho honesto.

> **Falta, em ordem de obrigatoriedade:** correção responsiva das seis telas acima; ensaio do
> roteiro duas vezes; tag, zip e upload antes das 18h de 17/09.

> **Parte do checklist de segurança saiu adiantada em 15/09** (`e0ada5a`, `0c417a6`), por ter
> aparecido numa revisão de código: CSP conferida por máquina contra o layout (D50), `try_files`
> no location PHP do nginx, sessão revogada conferida em toda requisição autenticada e não só nas
> rotas protegidas, `|raw` removido do macro de caixa, e `APP_DEBUG=false` no `.env.example` que o
> README manda copiar. **Nada disso foi exercitado com o Docker de pé** — ver `estado.md`.

Metade da nota depende disto. Não é "se sobrar tempo".

**Segurança (Camila, 16/09)**
- Checklist OWASP da proposta, item por item: autorização por operação em toda rota, CSRF em
  todo POST, escape no Twig, `cookie_secure` em produção, headers do nginx, sem stack trace.
- **Conferir com requisição forjada, não por leitura.** Foi assim que as duas falhas da D27
  apareceram, depois de já terem passado por revisão de código. Todo formulário que aceita
  identificador de volta (`nivel[ART:<id>]`, e os que vierem em E3 a E6) merece a mesma sonda.
- **Índice UNIQUE com coluna que aceita nulo não garante nada** (D25, D28), e já custou duas
  vezes. Varrer `estrutura.sql` atrás de todo `UNIQUE` com coluna opcional e conferir se o código
  que escreve nele usa `ON DUPLICATE KEY UPDATE` — se usar, está quebrado.
- **Melhoria pós-entrega anotada na D28**: fazer o banco garantir a unicidade do alvo de
  visibilidade com coluna gerada, em vez de depender de todo mundo passar pelo repositório.
- **`Sessao` guarda o perfil em `$_SESSION` e nunca o reconfere contra o banco.** O
  `sessaoTemRespaldo()` derruba sessão revogada, mas mudança de papel — o rebaixamento para
  Terceiro da D20/D26, e principalmente o bloqueio pelo administrador da E6 — só vale no próximo
  login. Decidir junto com a operação atômica 5, que é quem depende disso.
- Rodar o Anexo VI do edital como autoavaliação. Todos os doze itens.
- `grep` por segredo no repositório antes do push final.

**Acessibilidade e responsivo (12.1, Anexo I item 5)**
- Labels em todo campo, navegação por teclado, contraste, foco visível; conferir no celular.
- Baixar o Bootstrap para `public/assets/`. Hoje vem de CDN, e a demonstração é presencial: a
  plataforma não pode depender da rede do auditório para ter aparência.
- Escrever o texto real de `sis_termos`, hoje espaço reservado. As decisões D03 (guardamos o CPF
  cifrado) e D05 (exclusão lógica com revogação de acesso) precisam estar na Política de
  Privacidade, não só no código — é o que o item 11.3 cobra.

**Documentação de entrega (`_arq/`)**
- MER em PDF/PNG (`_arq/mer/`) — gerar do banco real.
- `arquitetura.md`: seção de **declaração de uso de IA, vieses e limitações** (12.3, Anexo VI).
  Inclui: sem ML treinado, semente contra position bias, early career sinalizado, vínculos da
  massa aleatórios, localização não discrimina na massa fictícia.
- `dependencias.md` conferido contra `composer.lock`. Bootstrap baixado para `public/assets/`.
- `README.md` de `_arq/` testado num clone limpo: `cp .env.example .env` → `up` → `/saude`.

**Demonstração**
- Cadastrar pelo fluxo real uns 8 profissionais e 4 empresas da massa; **consultar
  `crea_evidencias` para ver quais códigos concentram acervo**; só então escrever as demandas
  de demonstração. Ordem inversa é o único jeito com esta massa (`matching.md`).
- Roteiro escrito dos seis cenários, com os identificadores usados. Ensaiar duas vezes.
- Vídeo demonstrativo. Curto, os seis cenários, sem narração de código.

**Entrega (17/09, manhã)**
- `git tag entrega-fase3`, push, `.zip` do repositório sem `vendor/`, `.env` e `storage/`.
- Endereço do GitHub e `.zip` na plataforma **antes das 18h**. Não deixar para as 17h.

---

## Se apertar: o que cortar, nesta ordem

1. Mensagens dentro da manifestação (E5) → só notificação por e-mail e exibição do contato
   após aceite.
2. Lixeira com restauração (E6) → só listagem do que está em `'X'`.
3. Filtro de early career na busca ativa (E4) → sinalização no card basta.
4. Importação automática de CATs no cadastro (E2) → só ARTs automáticas, CAT manual.
5. Exportação de dados em JSON (E1) → manter a revogação e a exclusão, que são as que a banca
   testa.

**Nunca cortar:** os seis cenários, o selo ART, a explicação de critérios, a semente auditável,
o `_arq/` completo, o Docker subindo limpo.

## O que a proposta prometeu e fica fora do MVP

Declarar em `arquitetura.md` como evolução, não esconder: aviso proativo de registro próximo do
vencimento; preview do pool antes de publicar a demanda; sincronização de status agendada
(entregue como script executável em 17/09); chat completo (entregue como mensagens simples).

**Certidão de Acervo Técnico (CAT)**, acrescentada em 17/09 (D67). A estrutura está inteira
(`crea_cats`, `crea_cat_arts`, os dois métodos do cliente da API, e a view `crea_evidencias` já
lendo as duas tabelas) e **nenhum serviço a alimenta**: as tabelas têm zero linhas. Implementar é
curto e foi recusado por onde o efeito cai, que é a evidência de cada candidato e o pool de toda
demanda. O portfólio da entrega é ART e acervo operacional.
