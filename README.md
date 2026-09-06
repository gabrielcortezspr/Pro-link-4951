# Pro-Link — Equipe 49/51

Plataforma que liga demandas técnicas a profissionais e empresas registrados no CREA-AM usando
**evidência documental** — ARTs, CATs e acervo operacional — em vez de autodeclaração.

Desafio CREA Pro-Link · II CENATEC 2026 · CREA-AM.

```bash
cp .env.example .env      # preencha APP_KEY, DB_PASSWORD, DB_ROOT_PASSWORD e PROLINK_API_TOKEN
docker compose up -d --build
docker compose exec php composer install
docker compose exec php php scripts/criar-admin.php
curl -s http://localhost:8080/saude
```

Guia completo de instalação, atualização e problemas comuns: [`_arq/README.md`](_arq/README.md).

## Documentação

| Leia | Quando |
|---|---|
| [`docs/estado.md`](docs/estado.md) | ao retomar: onde parou e o próximo passo |
| [`docs/edital-requisitos.md`](docs/edital-requisitos.md) | antes de qualquer decisão de escopo, estrutura ou nomenclatura |
| [`_arq/arquitetura.md`](_arq/arquitetura.md) | vai mexer no código: camadas, módulos, segurança, backlog |
| [`_arq/estrutura-diretorios.md`](_arq/estrutura-diretorios.md) | onde cada coisa mora |
| [`_arq/estrutura.sql`](_arq/estrutura.sql) | nome de tabela, coluna ou view |
| [`docs/matching.md`](docs/matching.md) | vai mexer no motor de compatibilização |
| [`docs/api.md`](docs/api.md) | vai chamar a API: base, token, paginação, erros |
| [`docs/endpoints.md`](docs/endpoints.md) | os 10 endpoints com respostas reais |
| [`docs/modelo-de-dados.md`](docs/modelo-de-dados.md) | entidades da API e anatomia do código TOS |
| [`docs/massa-de-dados.md`](docs/massa-de-dados.md) | o que existe na base fictícia |
| [`docs/glossario.md`](docs/glossario.md) | ART, CAT, CAO, RNP, TOS |
| [`fixtures/`](fixtures/) | uma resposta real por endpoint, para desenvolver sem chamar a API |
| [`docs/edital.pdf`](docs/edital.pdf) · [`docs/proposta-fase1.pdf`](docs/proposta-fase1.pdf) | os originais |

## Equipe

Gabriel Cortez de São Paulo Rozeno · arquitetura, back-end, integração com a API, motor
Camila Vasconcelos Moi · segurança, privacidade e LGPD, auditoria, revisão

## Aviso

Os dados consumidos são **fictícios**, fornecidos pela organização do desafio, e não têm validade
legal. A correspondência entre perfil e demanda é apenas indicativa: não constitui ranking,
certificação de qualidade nem recomendação institucional (edital, itens 10.1 e 10.2).
