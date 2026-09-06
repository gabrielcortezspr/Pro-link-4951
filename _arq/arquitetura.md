# Documentação técnica

Exigido pelo edital, Anexo I, item 8.3.2d: arquitetura, componentes, módulos, integrações e
principais regras de negócio.

A estrutura de pastas e o desenho em camadas estão em `estrutura-diretorios.md`. Este documento
trata do que a aplicação faz e por quê.

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

## Módulos do banco

| Prefixo | O quê | Tabelas |
|---|---|---|
| `sis_` | identidade, privacidade, auditoria, parâmetros | 9 |
| `crea_` | cache das respostas da API oficial | 8 |
| `pro_` | domínio: perfis, demandas, manifestações, moderação | 10 |
| `mat_` | sessões do motor, com semente e pool auditáveis | 2 |

O MER está em `mer/`. O script completo, com índices, chaves e triggers, em `estrutura.sql`.

## Integração com a API oficial (RF02)

`src/Service/CreaApiClient.php`. Roteamento por query string, `Authorization: Bearer`, dez
endpoints. Os formatos e comportamentos foram confirmados contra a API real e estão detalhados
em `../docs/endpoints.md`, com uma resposta de referência por endpoint em `../fixtures/`.

Quatro regras que o cliente impõe por construção:

**Identificador é string.** `pro_rnp` (`"0412340011"`) e `emp_cnpj` (`"00123001000123"`) têm zero
à esquerda; convertidos a inteiro, a busca devolve 404.

**Não existe método de listagem em massa.** O item 10.4 do edital proíbe coleta automatizada e
toda chamada é registrada pela organização. A API é consultada em dois momentos apenas: quando
um candidato se cadastra ou pede atualização, e quando alguém informa um documento para validar.

**`200 []` e `404` significam coisas diferentes.** Array vazio quer dizer que a chave é válida
mas nada casou — na validação de documento, é uma reprovação legítima ("essa ART não é sua").
`404` quer dizer que o identificador não existe na base do CREA. A interface trata os dois de
forma distinta; o cliente devolve `null` no primeiro caso e lança `NaoEncontradoException` no
segundo.

**O cache não substitui a API.** `crea_*` guarda o que a API respondeu, com `_dt_consulta` e hash
de integridade, e é reconstruível a partir dela. Nenhuma consulta de validação é atendida pelo
cache. O item 8.4 do edital veda base própria que **simule** os dados da API; cache de resposta
real, documentado como tal e datado, é outra coisa — mas vale a distinção estar escrita.

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

O desenho completo está em `../docs/matching.md`. O resumo do que o código faz:

1. A demanda vira um conjunto de códigos TOS (`pro_demanda_tos`), com peso por código.
2. Cada candidato tem seu acervo indexado em `crea_evidencias`, com os quatro níveis do código
   em colunas separadas e indexadas.
3. A afinidade entre dois códigos é o número de componentes iniciais iguais — `src/Support/Tos.php`.
4. Seis dimensões viram um score composto: competência via ART/CAT, área de atuação e
   localização (verificadas pela API) somam 0.70; experiência declarada, tipo de contrato e
   disponibilidade (autodeclaradas) somam 0.30.
5. Score acima do limiar entra no pool. **O score não ordena.**

### Por que não há ranking

O item 10.1 do edital veda "ranking de profissionais", e o 10.2 diz que a correspondência é
apenas indicativa. Ordenar por score é ranking, com outro nome. Então:

```
score      →  decide QUEM ENTRA no pool (limiar)
semente    →  decide EM QUE ORDEM o pool aparece
critérios  →  mostram POR QUE cada um entrou
```

A semente é gerada por sessão e gravada em `mat_sessoes` junto com limiar, pesos vigentes e
`pool_ids`. Qualquer sessão passada é reproduzível pelo administrador — atende de uma vez os
itens 10.1, 12.3 e 8.5g. Os pesos e o limiar são editáveis em `sis_parametros` pelo painel
administrativo, o que satisfaz a exigência de supervisão humana do item 12.3.

O item 12.2 veda correspondência por raça, cor, sexo, gênero, deficiência, idade, religião,
origem ou condição social. Nenhum desses campos existe no modelo de dados.

## Segurança

| Controle | Onde | Item do edital |
|---|---|---|
| Senha com `password_hash()` (Argon2id) | `_config.php`, `PASSWORD_ALGO` | 8.5b |
| Prepared statements em toda query | `Support/Database.php` + repositórios | 8.5c |
| Escape de saída automático | Twig, `autoescape: html` | 8.5d |
| Token CSRF em toda escrita | `Support/Csrf.php`, front controller | 8.5e |
| Segregação de perfis | `sis_perfis` + perfil por rota no `Router` | 8.5f |
| Trilha de auditoria imutável | `sis_auditoria` + triggers que bloqueiam UPDATE/DELETE | 8.5g |
| Cifragem em repouso de CPF/CNPJ | `Support/Crypto.php`, AES-256-GCM | 11.3 |
| Expiração de sessão e bloqueio por tentativas | `sis_sessoes`, `sis_usuarios` | 11.3 |
| Consentimento revogável | `sis_consentimentos` | 11.3 |
| Exclusão lógica | `_status = 'X'` em todas as tabelas | 8.6j |

A autorização é validada **por operação**, não só no login: todo endpoint confere titularidade e
perfil antes de agir (OWASP A01).

### Operações atômicas

Cinco operações rodam dentro de transação, via `Database::transacao()`, porque falha parcial
deixaria o sistema inconsistente:

1. associação de ART ao portfólio (valida na API, grava ART, atividades, evidências e selo);
2. sessão de recomendação (grava semente, pool e critérios juntos);
3. manifestação de interesse (grava manifestação, snapshot e notificação);
4. edição de perfil com versionamento (grava novo valor e a linha de auditoria);
5. bloqueio de usuário (bloqueia, encerra sessões e registra a moderação).

## Onde o desenvolvimento continua

O esqueleto tem front controller, roteador, camada de apresentação, conexão, cifragem, o cliente
da API e as primitivas do motor. Falta implementar, na ordem do backlog:

| Ordem | Bloco | RF |
|---|---|---|
| 1 | cadastro, login, RBAC, recuperação de senha, consentimento | RF01 |
| 2 | validação de ART/CAT, portfólio, selo, visibilidade granular | RF02, RF03 |
| 3 | publicação de demanda, seleção de códigos TOS | RF04 |
| 4 | motor: pool, semente, explicação de critérios | RF04 |
| 5 | manifestação, snapshot, canal de mensagem | RF05 |
| 6 | painel admin, auditoria, denúncias, indicadores | RF06 |
| 7 | notificações SMTP | RF07 |

Os seis cenários mínimos do Anexo I, item 7, são a definição de pronto: cada um precisa rodar de
ponta a ponta na demonstração ao vivo.
