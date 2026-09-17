# Documentação técnica

Exigido pelo edital, Anexo I, item 8.3.2d: arquitetura, componentes, módulos, integrações e
principais regras de negócio.

A árvore de diretórios está em `estrutura-diretorios.md`. Este documento trata de como a
aplicação é construída e por quê.

## Visão geral

Pro-Link liga demandas técnicas a profissionais e empresas registrados no CREA-AM usando
**evidência documental** — ARTs, CATs e o acervo operacional — em vez de autodeclaração. O elo
entre "o que precisa ser feito" e "quem já provou que sabe fazer" é a Tabela de Obras e Serviços:

```
Necessidade → Atividade TOS → Capacidade comprovada → Profissional / Empresa
```

## Contexto (C4 nível 1)

```
   Público · Profissional · Empresa · Terceiros · Administrador
                          │
                          ▼
                    ┌───────────┐
                    │ Pro-Link  │
                    └─────┬─────┘
              ┌───────────┴────────────┐
              ▼                        ▼
    API oficial CREA-AM          Servidor SMTP
    (REST, token via .env)       (notificações RF07)
```

## Contêineres (C4 nível 2)

| Contêiner | Tecnologia | Papel |
|---|---|---|
| `nginx` | nginx 1.27 | serve `public/`, encaminha PHP via FastCGI |
| `php` | PHP 8.2-FPM | front controller, controllers, serviços, repositórios |
| `mariadb` | MariaDB 10.11 | persistência; carga inicial automática |
| `mailpit` | Mailpit | captura de e-mail em desenvolvimento |

## Camadas

O edital exige MVC ou equivalente com separação de apresentação, regra de negócio e acesso a
dados (itens 8.1.1c e 8.2). Adotamos MVC com **camada de serviço**:

```
public/index.php  →  Controller  →  Service  →  Repository  →  MariaDB
                          ↓             ↓
                        Twig     CreaApiClient → API oficial
```

O motor de compatibilização, o cliente da API, a auditoria e o selo de integridade têm lógica
demais para caber num controller. Controllers ficam finos; serviços concentram regra e limites
de segurança; repositórios são o único lugar que escreve SQL, sempre com prepared statement.

Cada camada só conhece a de baixo. Nada volta para cima: repositório não instancia controller,
serviço não monta HTML. Dependência externa entra por construtor.

## Módulos do banco

| Prefixo | O quê | Tabelas |
|---|---|---|
| `sis_` | identidade, privacidade, auditoria, parâmetros | 9 |
| `crea_` | cache das respostas da API oficial | 7 + view `crea_evidencias` |
| `pro_` | domínio: perfis, demandas, manifestações, moderação | 10 |
| `mat_` | sessões do motor, com semente e pool auditáveis | 2 |

O script completo, com índices, chaves, a view e os triggers, está em `estrutura.sql`. O MER está
em `mer/`: um diagrama por módulo, o diagrama completo e o PDF, gerados por
`../scripts/gerar-mer.py` a partir do próprio schema.

## Integração com a API oficial (RF02)

`src/Service/CreaApiClient.php`. Roteamento por query string, `Authorization: Bearer`, dez
endpoints. Os formatos e comportamentos foram confirmados contra a API real e estão detalhados
em `../docs/endpoints.md`, com uma resposta de referência por endpoint em `../fixtures/`.

O cliente impõe por construção que identificador é string, que não existe listagem em massa (item
10.4 do edital) e que `200 []` e `404` são coisas diferentes — devolve `null` no primeiro caso e
lança `NaoEncontradoException` no segundo. As tabelas `crea_*` são cache datado e com hash da
resposta real, reconstruível; nunca fonte para validação de documento (item 8.4). As regras e o
raciocínio estão em `../docs/api.md`.

### Selo ART (integridade)

Ao validar uma ART, o serviço calcula `HMAC-SHA256` sobre a resposta **canonicalizada** (chaves
ordenadas recursivamente, JSON sem escape de barra) com a chave em `APP_KEY`, e guarda em
`crea_arts.art_hash`. O selo exibido no perfil é derivado desse hash no servidor, nunca de uma
coluna booleana editável — se alguém alterar a linha no banco, o selo deixa de conferir.
Canonicalizar importa porque a ordem das chaves no JSON não é garantida.

### Sincronização de status

`pro_status` só existe na busca por CPF. Para zerar a visibilidade de profissional com registro
suspenso (RF02) é preciso ter o CPF guardado, cifrado em AES-256-GCM. É decisão consciente de
LGPD: o documento é cifrado em repouso, tem hash cego separado para busca, e o consentimento
específico é registrado em `sis_consentimentos` com a finalidade `CONSULTA_API`.

## Motor de compatibilização (RF04)

Desenho completo, pesos e justificativas em `../docs/matching.md`. O que o código faz:

1. A demanda vira códigos TOS (`pro_demanda_tos`), com peso por código.
2. O acervo de cada candidato é lido da view `crea_evidencias`, com os níveis do código em
   colunas indexadas.
3. A afinidade entre dois códigos é o número de componentes iniciais iguais — `Support\Tos`.
4. Seis dimensões (item 3.2 do edital) viram um score composto; acima do limiar entra no pool.
5. **O score não ordena** — o item 10.1 veda ranking. A semente da sessão ordena, e `mat_sessoes`
   guarda semente, limiar, pesos e pool para o administrador reproduzir qualquer sessão.

Pesos e limiar vivem em `sis_parametros`, editáveis pelo painel — supervisão humana do item 12.3.
Nenhum campo vedado pelo item 12.2 existe no modelo.

## Segurança

| Controle | Onde | Item do edital |
|---|---|---|
| Senha com `password_hash()` (Argon2id) | `_config.php`, `PASSWORD_ALGO` | 8.5b |
| Prepared statements em toda query | `Support/Database.php` + repositórios | 8.5c |
| Escape de saída automático | Twig, `autoescape: html` | 8.5d |
| Token CSRF em toda escrita | `Support/Csrf.php`, front controller | 8.5e |
| Segregação de perfis | `sis_perfis`; perfis por rota no `Router`, conferidos a cada requisição em `public/index.php` via `Support/Sessao` | 8.5f |
| Trilha de auditoria imutável | `Support/Auditoria` é o único caminho de escrita em `sis_auditoria`; triggers bloqueiam UPDATE/DELETE | 8.5g |
| Cifragem em repouso de CPF/CNPJ | `Support/Crypto.php`, AES-256-GCM | 11.3 |
| Expiração de sessão e bloqueio por tentativas | `Support/Sessao` (inatividade, regeneração de id), `sis_sessoes`, `sis_usuarios` | 11.3 |
| Consentimento revogável | `sis_consentimentos` | 11.3 |
| Exclusão lógica | `_status = 'X'` em todas as tabelas | 8.6j |

A autorização é validada **por operação**, não só no login: todo endpoint confere titularidade e
perfil antes de agir (OWASP A01).

### Operações atômicas

Cinco operações foram desenhadas como atômicas, porque falha parcial deixaria o sistema
inconsistente. Todas as cinco estão implementadas, via `Database::transacao()`:

1. **associação de ART ao portfólio** — valida na API, grava ART, atividades, evidências e selo
   (`PortfolioService`, `PerfilCreaService`);
2. **sessão de compatibilização** — grava semente, limiar, pesos e pool juntos, porque sessão sem
   pool é registro de auditoria mentindo (`CompatibilizacaoService`);
3. **manifestação de interesse** — grava a manifestação com o retrato e o hash, move a demanda
   para `COM_INTERESSADOS`, registra na trilha e enfileira a notificação (`ManifestacaoService`);
4. **edição de perfil com versionamento** — grava o novo valor e a linha de auditoria
   (`PreferenciaService`, `ExperienciaService`, `VisibilidadeService`);
5. **bloqueio de usuário** — bloqueia, encerra as sessões e registra a moderação
   (`DenunciaService`).

O uso cresceu além dessas cinco: há 35 blocos transacionais em 16 serviços, porque a regra "trilha
de auditoria desfaz junto com o fato que ela registra" vale para toda escrita, não só para as
operações destacadas.

## Declaração de uso de inteligência artificial, vieses e limitações

Exigida pelo item 12.3 do edital e pelo Anexo VI ("documenta riscos, limitações e uso de
inteligência artificial"). O raciocínio por trás de cada escolha citada aqui está em
`../docs/decisoes.md`, que tem 75 entradas com o campo *Alternativa recusada* preenchido.

### 1. Não há inteligência artificial no produto

A compatibilização **não usa aprendizado de máquina**. Não há modelo treinado, não há dado de
treino, não há inferência estatística sobre comportamento de usuário e não há caixa-preta. O que
existe é uma fórmula determinística, escrita à mão, auditável linha a linha em
`src/Support/Compatibilidade.php` e documentada em `../docs/matching.md`:

- seis dimensões (item 3.2 do edital), cada uma com peso explícito em `sis_parametros`;
- a afinidade entre dois códigos da Tabela de Obras e Serviços é o número de componentes iniciais
  iguais, uma contagem, não uma semelhança aprendida;
- o score composto é a média ponderada das dimensões medidas.

**Consequência prática:** a mesma demanda com o mesmo acervo produz sempre o mesmo resultado, e
qualquer pessoa pode refazer a conta no papel. Não há "o algoritmo decidiu" — há uma fórmula que o
administrador vê, edita e reproduz.

### 2. A ordem não vem do score, e isso é uma decisão contra viés de posição

O item 10.1 veda ranking de profissionais. A implementação vai além de não numerar a lista:

- **o score filtra, não ordena.** Quem passa do limiar entra no pool; quem não passa, não entra;
- **a ordem vem de uma semente aleatória por sessão**, gravada em `mat_sessoes`. Dois candidatos
  que entraram com 0,9 e 0,6 aparecem em ordem sorteada;
- a semente é `bin2hex(random_bytes(16))` — aleatória de verdade, porque semente previsível
  permitiria a alguém posicionar um perfil;
- o embaralhamento ordena por hash de (semente + chave do candidato), e não por `shuffle()` com
  seed global, para não depender do estado nem da versão do gerador do PHP. A mesma semente
  reproduz a mesma ordem em qualquer máquina;
- **nem ao administrador o score composto é exibido.** A tela `/admin/sessoes/{id}` refaz o
  sorteio na frente de quem audita e mostra as dimensões individuais, que são o critério
  explicável; a nota agregada só serviria para ordenar.

O viés que isso ataca é conhecido e mensurável: em qualquer lista, quem aparece primeiro recebe
mais atenção. Ordenar por mérito calculado transformaria uma diferença de centésimos numa
diferença de oportunidade.

### 3. O que protege quem está começando

A massa tem profissionais com duas ARTs e outros com quatro. Sem cuidado explícito, um motor de
evidência documental vira uma máquina de concentrar trabalho em quem já tem trabalho:

- **multiplicidade tem retorno decrescente.** Cinco ARTs no mesmo código valem mais que uma e
  menos que cinco vezes uma, por raiz. Volume alto de acervo não pode virar ranking implícito por
  antiguidade de carreira;
- **dimensão sem dado sai da média, em vez de valer zero.** Quem não declarou regime de
  contratação não é penalizado por isso: a dimensão simplesmente não entra no cálculo daquele
  candidato. Zero é uma medida; ausência não é;
- **"perfil em construção" sinaliza, nunca exclui.** Abaixo de `match.early_career.min_arts` ARTs
  o perfil recebe uma marca de contexto e **continua no pool**. O limiar vale 3, e o número foi
  escolhido por medição, não por intuição: na base de demonstração os doze profissionais têm de 2 a
  4 ARTs, média 3,1: três com duas, cinco com três e quatro com quatro. O limiar 3 marca 3 de 12
  (25%); o limiar 4 marcaria 8 de 12 (67%), e rótulo que vale para dois terços da plataforma não
  informa nada. **Este é um valor calibrado contra uma
  massa fictícia de 12 profissionais**, e é dos primeiros que precisariam ser recalibrados com
  volume real.

**A exceção, declarada:** na busca ativa, o filtro "incluir quem está começando" nasce
**desligado**. É o único lugar da plataforma onde um atributo do perfil esconde alguém por padrão,
e está aqui porque a proposta aprovada o descreve assim. É o ponto do sistema que mais merece
revisão depois da fase de protótipo.

### 4. Vieses que a massa fictícia esconde, e que apareceriam com dado real

Esta é a seção que a banca deve ler com mais atenção, porque são limitações que os nossos testes
**não** conseguem detectar:

- **Os vínculos entre ART e código TOS na massa são aleatórios.** Um profissional cujo objeto de
  ART é uma obra civil pode ter atividades de agronomia vinculadas. Isso significa que **nenhum
  resultado de compatibilização nesta base tem coerência temática**, e que a qualidade real do
  motor não pôde ser avaliada. Os cenários de demonstração foram escritos *depois* de consultar
  quais códigos concentram acervo, e não antes — é o único modo honesto de demonstrar com esta
  massa;
- **A dimensão de localização não discrimina nesta base.** Quase todo o acervo é do Amazonas, e a
  demanda de demonstração também. Com dado real, localização seria a dimensão com maior potencial
  de efeito indireto: concentrar oportunidade na capital, em detrimento do interior, sem que
  nenhuma regra mencione região. Não conseguimos medir isso, e não vamos afirmar que está
  resolvido;
- **A dimensão de experiência declarada premia quem escreve mais.** Ela pesa 0,10, o menor peso
  junto com contrato e disponibilidade, e é a única alimentada por texto livre. Quem tem mais
  facilidade de redigir tende a preencher melhor. Foi mantida com peso baixo e visualmente
  separada do dado verificado, mas o efeito existe;
- **Nenhum critério vedado pelo item 12.2 existe no modelo de dados.** Não há raça, cor, sexo,
  gênero, deficiência, idade, religião ou origem em nenhuma tabela — não como campo oculto, não
  como campo opcional. O que não se coleta não pode ser usado, nem por engano. Conferível:
  nenhuma coluna de `estrutura.sql` casa com esses termos (as ocorrências textuais do arquivo são
  todas substrings de "validade", "finalidade", "entidade" e "disponibilidade").

### 5. Assimetrias conhecidas entre o que o motor usa e o que a tela mostra

- **ART fechada conta para a compatibilidade e não é citada pelo número** (decisão D52). O
  candidato que fecha um documento não perde posicionamento por isso — penalizar a privacidade
  empurraria todo mundo a abrir tudo —, mas o demandante vê um score sustentado por evidência que
  não pode conferir. É o preço explícito de não punir quem usa o controle de visibilidade que a
  plataforma oferece;
- **O retrato enviado na manifestação é o que o demandante já podia ver** (decisão D57).
  Manifestar interesse não abre campo fechado. Quem tem o perfil discreto envia pouco, e a tela de
  confirmação diz isso antes do envio, com o número exato de campos, ARTs e experiências que vão
  junto;
- **A busca ativa ordena alfabeticamente.** Não é ranking — o termo decide quem entra, e dentro de
  quem entrou ninguém está à frente. Mas o teto de 60 resultados existe (para que uma busca aberta
  a anônimo não vire endpoint de extração, item 10.4), e com ele um termo muito genérico favorece
  sistematicamente nomes no começo do alfabeto. Com a base atual, de 12 profissionais, o teto
  nunca é atingido e o efeito não aparece; com volume real seria preciso paginação ou outra ordem
  neutra. **É uma limitação que os nossos testes não conseguem exibir.**

### 6. Supervisão humana

Nenhuma decisão da plataforma é final ou automática. O item 10.1 é explícito e a implementação o
segue: não há contratação automática, intermediação financeira, garantia de preço, certificação de
qualidade nem recomendação institucional. **A correspondência é indicativa, e quem decide é a
pessoa.**

Os controles de supervisão, todos exercitáveis pelo painel:

| O quê | Onde |
|---|---|
| Pesos das seis dimensões e limiar de entrada | **`/admin/parametros`**, com a faixa aceitável declarada por campo, lote atômico e o valor anterior e o novo indo para a trilha |
| Limiar de "perfil em construção" | `match.early_career.min_arts`, na mesma tela |
| Limite de manifestações por hora | `manifestacao.limite_hora`, na mesma tela |
| Quem mudou cada parâmetro, e quando | a própria tela, ao lado do campo, lido de `sis_auditoria` |
| Reprodução de qualquer sessão passada | `/admin/sessoes/{id}`, que refaz o sorteio pela semente gravada e compara com a ordem registrada |
| Trilha imutável de toda escrita | `sis_auditoria`, insert-only por trigger |
| O que foi excluído, e por ordem de quem | `/admin/lixeira` (item 8.6j) |
| Contas da plataforma, com bloqueio e desbloqueio registrados na trilha | `/admin/contas` |
| Estado das integrações: a API oficial, o SMTP e os parâmetros de sincronização | `/admin/integracoes` (item 3 do Anexo I, "configurar integrações"). A tela **lê e configura, e não dispara chamada à API**, porque cada chamada consome cota registrada pela organização (D75) |

Até 16/09 os pesos só existiam como linha no banco, e a supervisão do 12.3 era uma frase nesta
documentação mais um `UPDATE` de quem tivesse acesso ao servidor. A tela mudou a natureza disso:
quem altera um critério de recomendação é uma pessoa identificada, o valor anterior e o novo ficam
na trilha, e a alteração aparece ao lado do campo na próxima vez que alguém abrir a tela.

### 7. Uso de IA na construção deste sistema

O item 12.3 pede que o uso de IA seja declarado. Ele foi usado — não no produto, mas para
construí-lo — e escondê-lo seria o oposto do que a exigência pede.

**O que foi usado.** Assistente de programação baseado em modelo de linguagem (Claude, da
Anthropic), operado pela equipe em sessões de pareamento, com o histórico de decisões registrado
em `../docs/decisoes.md` conforme as escolhas eram feitas.

**Em quê.** Escrita de código sob especificação da equipe, revisão de código, redação de
documentação, e investigação de defeitos. O desenho do produto, a leitura do edital, a modelagem
de dados e todas as decisões com alternativa real foram tomadas pela equipe — é por isso que o
campo *Alternativa recusada* existe em cada entrada do registro de decisões: ele mostra o que foi
considerado e descartado, por quem decidiu.

**Com que supervisão.** Todo código gerado passou por revisão humana e pelo conjunto de
verificação do repositório, que `scripts/verificar-tudo.sh` roda em nove passos: 193 testes
automatizados de unidade, a compilação das 42 telas, 72 conferências de padrão visual, quatro
verificadores por etapa que rodam contra o banco (E1 com 80 conferências, E2 com 159, E4 com 42 e
E6 com 70) e, desde 17/09, uma suíte de ponta a ponta que percorre os seis cenários mínimos pelo
navegador, mais sondas de autorização com requisição forjada, uma régua de fidelidade ao desenho e
as mesmas telas em 390px. Vários defeitos encontrados nesta construção, e registrados no histórico
de commits, foram achados exatamente porque a verificação não confiou no que o código dizia de si
mesmo.

Dois exemplos do que só apareceu por essa desconfiança, ambos de 17/09: a marca de "perfil em
construção" não era recalculada ao associar uma ART à mão, e ninguém tinha visto porque nenhuma
rota chamava o método; e a evidência do art. 18 da LGPD na lixeira era desfeita pelo próprio
script que a criava, porque ele terminava gravando a exclusão administrativa depois da exclusão do
titular.

**O que isso implica como limitação.** Código escrito com assistência de modelo de linguagem pode
conter erros sutis que passam por revisão, como qualquer código. A mitigação adotada foi
verificação executável em vez de leitura: cada afirmação relevante deste documento corresponde a
um teste ou a um script que pode ser rodado por quem avalia.

### 8. O que não foi entregue, e é promessa da proposta

Declarado aqui como evolução, não escondido:

- aviso proativo de registro próximo do vencimento;
- preview do pool antes de publicar a demanda;
- sincronização de status agendada — entregue como script executável, não como rotina automática;
- chat completo — entregue como mensagens simples dentro da manifestação;
- notificação por e-mail a cada mensagem: o RF07 lista os eventos que notificam e mensagem não
  está entre eles, então quem não voltar à plataforma não sabe que recebeu resposta.

## Onde o desenvolvimento continua

A sequência de etapas, com datas, responsáveis, critério de pronto e o que cortar se apertar,
está em `../docs/backlog.md`. A definição de pronto do MVP são os seis cenários mínimos do
Anexo I, item 7, rodando ao vivo.
