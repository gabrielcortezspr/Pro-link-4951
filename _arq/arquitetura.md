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

O script completo, com índices, chaves, a view e os triggers, está em `estrutura.sql`. O MER entra em `mer/` quando for gerado.

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
