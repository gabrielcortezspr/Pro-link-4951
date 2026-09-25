# Pro-Link — instalação, configuração e execução

Exigido pelo edital, Anexo I, item 8.3.2c.

## Requisitos mínimos

| Item | Versão |
|---|---|
| Docker Engine | 24+ |
| Docker Compose | v2 |
| Git | 2.30+ |

Nada mais precisa estar instalado na máquina hospedeira para **subir e usar** a aplicação: PHP,
Composer e MariaDB rodam dentro dos contêineres.

Duas coisas ficam fora dos contêineres, e só são necessárias para quem quiser rodar a verificação
completa ou regerar os vídeos:

| Item | Versão | Para quê |
|---|---|---|
| Node.js e npm | 18+ | a suíte de ponta a ponta em `e2e/` (Playwright) |
| Python | 3.11+ | `scripts/atualizar_tos.py` e `scripts/gerar-mer.py`, que já rodaram e cujo resultado está versionado |

Para desenvolver fora do Docker seriam necessários PHP 8.2+ com as extensões `pdo_mysql`,
`mbstring`, `intl`, `zip`, `opcache`, `openssl`, `curl` e `json`, Composer 2 e MariaDB 10.11+.

## Instalação

```bash
git clone <url-do-repositorio> pro-link
cd pro-link

cp .env.example .env
```

Preencha o `.env`. Quatro valores não têm padrão utilizável:

| Variável | Como obter |
|---|---|
| `APP_KEY` | `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` — ou qualquer gerador de 32 bytes em base64 |
| `DB_PASSWORD` e `DB_ROOT_PASSWORD` | escolha livre; o MariaDB é criado com o que estiver aqui |
| `DB_APP_PASSWORD` | escolha livre, diferente das duas acima: é a senha do usuário restrito com que a aplicação conecta (D82). Vazia, o banco **não sobe** |
| `PROLINK_API_TOKEN` | token individual da equipe, na plataforma oficial do desafio |

Suba o ambiente:

```bash
docker compose up -d --build
docker compose exec php composer install
```

Na primeira subida o MariaDB executa sozinho `_arq/estrutura.sql`, `_arq/carga-inicial.sql` e
`_arq/usuarios.sh` (este cria o usuário da aplicação, ver "Usuários do banco" abaixo):
o banco já sobe com as **28 tabelas mais a view `crea_evidencias`**, 28 chaves estrangeiras, as
duas triggers que tornam a trilha de auditoria imutável, os 5 perfis de acesso, as 25 modalidades,
os 2000 códigos da Tabela de Obras e Serviços, os 12 parâmetros da aplicação (9 do motor de
compatibilização, 2 de integração e 1 geral) e o texto vigente dos Termos de Uso e da Política de
Privacidade.

**Nenhum usuário vem na carga**, de propósito: o Anexo VI do edital lista "não contém credenciais,
secrets ou chaves reais" como item de triagem, e uma senha padrão em `carga-inicial.sql` seria
exatamente isso.

Conferido em 17/09/2026 subindo um MariaDB limpo só com esses dois arquivos montados em
`docker-entrypoint-initdb.d`, que é o caminho que o `docker-compose.yml` usa.

### Usuários do banco

São dois, de propósito (D82):

| Usuário | Privilégios | Quem usa |
|---|---|---|
| `DB_APP_USERNAME` (`prolink_app`) | `SELECT, INSERT, UPDATE` por tabela; só `SELECT, INSERT` em `sis_auditoria`; `SELECT` na view. Nenhum `DELETE`, nenhum privilégio de estrutura | toda requisição web |
| `DB_USERNAME` (`prolink`) | `ALL PRIVILEGES` em `prolink.*` | scripts em `scripts/` na linha de comando: povoamento, verificadores, migração |

A escolha é do `_config.php`: requisição web conecta sempre como o restrito; `php` na linha de
comando conecta como o administrativo, a não ser que receba `DB_CONEXAO=app`:

```bash
docker compose exec -T -e DB_CONEXAO=app php php scripts/despachar-fila.php --fila
```

Para conferir que o restrito é restrito (todas devem falhar com erro 1142):

```bash
docker compose exec -T mariadb sh -c 'mariadb -h127.0.0.1 -uprolink_app -p prolink \
  -e "UPDATE sis_auditoria SET aud_acao = aud_acao LIMIT 1"'
# idem com DELETE FROM sis_auditoria LIMIT 1 e com DROP TRIGGER trg_aud_bloqueia_update
```

Crie o usuário administrador da aplicação (nenhuma credencial viaja no repositório):

```bash
docker compose exec php php scripts/criar-admin.php
```

Em ambiente automatizado, onde ninguém digita, o mesmo script aceita argumentos. A senha fica no
histórico do shell e na lista de processos, então use assim só com credencial descartável:

```bash
docker compose exec -T php php scripts/criar-admin.php \
  --nome="Nome" --email="admin@exemplo.local" --senha="<uma senha de 12 ou mais>"
```

## Verificação

A bateria inteira, numa ordem que não se atrapalha e com placar no fim:

```bash
cd e2e && npm install && cd ..      # só na primeira vez; sem isso os passos de navegador PULAM
PROLINK_E2E_ADMIN_SENHA="<a do administrador da suíte>" bash scripts/verificar-tudo.sh
```

São nove passos: testes de unidade, compilação das telas, padrão visual, os quatro verificadores
por etapa (E1, E2, E4 e E6) e os dois de navegador (desktop e 390px). **Passo pulado não conta
como verde**, e o placar final diz quantos foram pulados: sem `e2e/node_modules` ou sem a senha da
administração, os dois últimos pulam em vez de rodar.

A senha pode vir do ambiente, como acima, ou de `e2e/.env.local`, que o `.gitignore` recusa e que
tanto `scripts/verificar-tudo.sh` quanto `e2e/rodar.sh` carregam sozinhos:

```bash
cat > e2e/.env.local <<'ENV'
PROLINK_E2E_ADMIN_EMAIL=e2e.admin@verificacao.local
PROLINK_E2E_ADMIN_SENHA=<a que você escolheu>
ENV
```

Nenhum passo dela chama a API oficial. `verificar-api.php` e `semear-candidatos.php` ficam de fora
de propósito: cada chamada é registrada pela organização, e uma bateria que gasta cota não pode
ser rodada à vontade.

Os passos individuais, se você quiser um de cada vez:

```bash
docker compose exec -T php composer test                          # 193 testes de unidade
docker compose exec -T php php scripts/verificar-telas.php        # as 42 telas compilam
docker compose exec -T php php scripts/verificar-padrao.php       # 72 conferências de padrão
docker compose exec -T php php scripts/verificar-e1.php http://nginx   # identidade e consentimento
docker compose exec -T php php scripts/verificar-e2.php           # portfólio e sincronização
docker compose exec -T php php scripts/verificar-e4.php           # motor de compatibilização
docker compose exec -T php php scripts/verificar-e6.php           # denúncias e painel
cd e2e && ./rodar.sh                                              # a suíte pelo navegador
```

`verificar-e1.php` roda **de dentro do contêiner e com a URL interna** (`http://nginx`): com
`APP_URL` ele tentaria `localhost:8443`, que lá dentro não existe.

E o estado das peças, sem entrar em contêiner nenhum:

```bash
curl -sk https://localhost:8443/saude   # -k: sem mkcert, o certificado é o autoassinado de reserva (D81)
```

Resposta esperada:

```json
{
  "aplicacao": "Pro-Link",
  "ambiente": "dev",
  "banco": "ok",
  "tos_carregada": 2000,
  "momento": "2026-09-06T18:00:00-04:00"
}
```

`tos_carregada: 2000` confirma que a carga inicial entrou.

| Endereço | O quê |
|---|---|
| https://localhost:8443 | aplicação (TLS 1.2 ou 1.3, D81) |
| http://localhost:8080 | só redireciona para o HTTPS |
| http://localhost:8025 | Mailpit — os e-mails da RF07 em desenvolvimento |
| `localhost:3307` | MariaDB, se quiser conectar de fora |

## Demonstração

O roteiro dos seis cenários mínimos do Anexo I, item 7, com as contas, os identificadores que
funcionam e a ordem de preparo do dado, está em `../docs/roteiro-demo.md`. A versão executável dele
é `e2e/specs/demonstracao.spec.js`, e há mais três jornadas gravadas, uma por perfil, em
`e2e/specs/jornadas.spec.js`.

## Atualização

```bash
git pull
docker compose up -d --build
docker compose exec php composer install
```

Mudanças no schema entram como arquivo novo em `_arq/migracoes/` e são aplicadas com:

```bash
docker compose exec -T mariadb mariadb -u root -p"$DB_ROOT_PASSWORD" prolink < _arq/migracoes/NNN-descricao.sql
```

O privilégio do usuário da aplicação é **por tabela**. Migração que cria tabela exige rodar de
novo o provisionamento, senão a tela que usa a tabela nova recebe "command denied". O mesmo
comando serve para um banco criado antes da D82, que não tem o `prolink_app` (acrescente antes
`DB_APP_USERNAME` e `DB_APP_PASSWORD` ao `.env` e rode `docker compose up -d php` para o PHP ler):

```bash
DB_APP_PASSWORD="$(grep '^DB_APP_PASSWORD=' .env | cut -d= -f2-)" \
  docker compose exec -T -e DB_APP_PASSWORD -e DB_APP_USERNAME=prolink_app mariadb bash -s < _arq/usuarios.sh
```

É idempotente: revoga tudo e concede de novo a partir da lista de tabelas do banco.

Para recriar o banco do zero (apaga tudo):

```bash
docker compose down -v && docker compose up -d
```

## Problemas comuns

**`tos_carregada: 0`** — a carga só roda na criação do volume. `docker compose down -v` e suba
de novo.

**`banco: indisponivel`** — o MariaDB ainda está subindo, ou `DB_APP_PASSWORD` no `.env` não
bate com o usuário `prolink_app` do volume já criado. No segundo caso, rode de novo o
provisionamento de "Atualização" (ele redefine a senha), sem apagar o volume.

**`command denied to user 'prolink_app'`** — uma tabela nova entrou por migração e ainda não tem
privilégio. Rode o provisionamento de "Atualização".

**O MariaDB para na primeira subida com `DB_APP_PASSWORD vazio`** — preencha no `.env`. Como a
inicialização parou no meio, o volume ficou incompleto: `docker compose down -v` e suba de novo.

**`Token da API recusado`** — `PROLINK_API_TOKEN` vazio ou expirado. Confira na plataforma oficial.

**Porta 8443 ocupada** — mude o mapeamento no `docker-compose.yml`, a porta do `listen` e do redirecionamento em `docker/nginx/default.conf` (dentro e fora são a mesma, D81) e o `APP_URL` no `.env`.
