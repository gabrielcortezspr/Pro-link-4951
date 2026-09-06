# Pro-Link — Equipe 49/51

Plataforma que liga demandas técnicas a profissionais e empresas registrados no CREA-AM usando
**evidência documental** — ARTs, CATs e acervo operacional — em vez de autodeclaração.

Desafio CREA Pro-Link · II CENATEC 2026 · CREA-AM.

## Subir o projeto

```bash
cp .env.example .env      # preencha APP_KEY, DB_PASSWORD e PROLINK_API_TOKEN
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php scripts/criar-admin.php
curl -s http://localhost:8080/saude
```

O passo a passo completo, com requisitos e solução de problemas, está em
[`_arq/README.md`](_arq/README.md).

## Onde está o quê

| Caminho | Conteúdo |
|---|---|
| `_arq/` | documentação de entrega exigida pelo edital: SQL, MER, README, arquitetura, dependências |
| `_config.php` | configuração central (item 8.3.1 do edital) |
| `public/` | document root; front controller |
| `src/` | Controller, Service, Repository, Model, Support |
| `templates/` | Twig |
| `docker/` | ambiente reproduzível |
| `docs/` | documentação de projeto: API, modelo de dados, motor, requisitos do edital |
| `data/` | massa fictícia da organização |
| `fixtures/` | respostas reais da API, para desenvolver sem chamá-la |
| `scripts/` | utilitários de linha de comando |

## Documentação

| Arquivo | Quando ler |
|---|---|
| [`CLAUDE.md`](CLAUDE.md) | orientação para qualquer agente que abrir o repositório |
| [`docs/edital-requisitos.md`](docs/edital-requisitos.md) | o que o edital exige, destilado |
| [`docs/glossario.md`](docs/glossario.md) | ART, CAT, CAO, RNP, TOS |
| [`docs/api.md`](docs/api.md) | base URL, token, paginação, erros |
| [`docs/endpoints.md`](docs/endpoints.md) | os 10 endpoints com respostas reais |
| [`docs/modelo-de-dados.md`](docs/modelo-de-dados.md) | entidades da API e anatomia do código TOS |
| [`docs/massa-de-dados.md`](docs/massa-de-dados.md) | o que existe na base fictícia |
| [`docs/matching.md`](docs/matching.md) | desenho do motor de compatibilização |
| [`_arq/arquitetura.md`](_arq/arquitetura.md) | como a aplicação está construída |

## Equipe

Gabriel Cortez de São Paulo Rozeno · arquitetura, back-end, integração com a API, motor
Camila Vasconcelos Moi · segurança, privacidade e LGPD, auditoria, revisão

## Aviso

Os dados consumidos são **fictícios**, fornecidos pela organização do desafio, e não têm validade
legal. A correspondência entre perfil e demanda é apenas indicativa: não constitui ranking,
certificação de qualidade nem recomendação institucional (edital, itens 10.1 e 10.2).
