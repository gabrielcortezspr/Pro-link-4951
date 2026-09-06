# Pro-Link — instalação, configuração e execução

Exigido pelo edital, Anexo I, item 8.3.2c.

## Requisitos mínimos

| Item | Versão |
|---|---|
| Docker Engine | 24+ |
| Docker Compose | v2 |
| Git | 2.30+ |

Nada mais precisa estar instalado na máquina hospedeira: PHP, Composer e MariaDB rodam dentro
dos contêineres. Para desenvolver fora do Docker seriam necessários PHP 8.2+ com as extensões
`pdo_mysql`, `mbstring`, `intl`, `openssl` e `curl`, Composer 2 e MariaDB 10.11+.

## Instalação

```bash
git clone <url-do-repositorio> pro-link
cd pro-link

cp .env.example .env
```

Preencha o `.env`. Três valores não têm padrão utilizável:

| Variável | Como obter |
|---|---|
| `APP_KEY` | `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"` — ou qualquer gerador de 32 bytes em base64 |
| `DB_PASSWORD` e `DB_ROOT_PASSWORD` | escolha livre; o MariaDB é criado com o que estiver aqui |
| `PROLINK_API_TOKEN` | token individual da equipe, na plataforma oficial do desafio |

Suba o ambiente:

```bash
docker compose up -d --build
docker compose exec php composer install
```

Na primeira subida o MariaDB executa sozinho `_arq/estrutura.sql` e `_arq/carga-inicial.sql`:
o banco já sobe com as 29 tabelas, os 5 perfis de acesso, as 25 modalidades, os 2000 códigos da
Tabela de Obras e Serviços e os parâmetros do motor.

Crie o usuário administrador (nenhuma credencial viaja no repositório):

```bash
docker compose exec php php scripts/criar-admin.php
```

## Verificação

```bash
curl -s http://localhost:8080/saude
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
| http://localhost:8080 | aplicação |
| http://localhost:8025 | Mailpit — os e-mails da RF07 em desenvolvimento |
| `localhost:3307` | MariaDB, se quiser conectar de fora |

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

Para recriar o banco do zero (apaga tudo):

```bash
docker compose down -v && docker compose up -d
```

## Problemas comuns

**`tos_carregada: 0`** — a carga só roda na criação do volume. `docker compose down -v` e suba
de novo.

**`banco: indisponivel`** — o MariaDB ainda está subindo, ou `DB_PASSWORD` no `.env` não bate
com o volume já criado. No segundo caso, `docker compose down -v`.

**`Token da API recusado`** — `PROLINK_API_TOKEN` vazio ou expirado. Confira na plataforma oficial.

**Porta 8080 ocupada** — mude o mapeamento no `docker-compose.yml` e o `APP_URL` no `.env`.
