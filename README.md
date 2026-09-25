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
docker compose exec php php scripts/verificar-e1.php http://nginx   # 74 verificações da RF01, por HTTP
docker compose exec php php scripts/verificar-e2.php                # 138 verificações do portfólio, sem rede
docker compose exec php php scripts/verificar-api.php              # 38 verificações contra a API oficial
```

Dentro do container, o `verificar-e1.php` precisa da URL do nginx: o `APP_URL` padrão é o
endereço visto do host. O `verificar-api.php` gasta quinze chamadas à API oficial, que a
organização registra — rode para confirmar que nada mudou do lado deles, não em laço.

Guia completo de instalação, atualização e problemas comuns: [`_arq/README.md`](_arq/README.md).

## Documentação

| Leia | Quando |
|---|---|
| [`docs/estado.md`](docs/estado.md) | ao retomar: onde parou e o próximo passo |
| [`docs/backlog.md`](docs/backlog.md) | a sequência de etapas até a entrega, com critério de pronto |
| [`docs/decisoes.md`](docs/decisoes.md) | por que algo é como é, e qual alternativa foi recusada |
| [`docs/sprint-2026-09-14-e6.md`](docs/sprint-2026-09-14-e6.md) | o registro longo da sessão que fechou a E6, para quem precisar do detalhe |
| [`docs/edital-requisitos.md`](docs/edital-requisitos.md) | antes de qualquer decisão de escopo, estrutura ou nomenclatura |
| [`_arq/arquitetura.md`](_arq/arquitetura.md) | vai mexer no código: camadas, módulos, segurança, backlog |
| [`_arq/estrutura-diretorios.md`](_arq/estrutura-diretorios.md) | onde cada coisa mora |
| [`_arq/estrutura.sql`](_arq/estrutura.sql) | nome de tabela, coluna ou view |
| [`docs/matching.md`](docs/matching.md) | vai mexer no motor de compatibilização |
| [`docs/fluxos.md`](docs/fluxos.md) | o caminho das telas e os seis cenários da demo |
| [`docs/roteiro-demo.md`](docs/roteiro-demo.md) | vai apresentar: o roteiro dos seis cenários, com os identificadores que funcionam |
| [`docs/pitch.md`](docs/pitch.md) | vai apresentar no Demo Day: o texto dos 8 minutos de pitch, o que não dizer e as perguntas prováveis |
| [`docs/definicao-de-pronto.md`](docs/definicao-de-pronto.md) | o que conta como pronto, da micro-etapa à entrega |
| [`e2e/README.md`](e2e/README.md) | a suíte de ponta a ponta: como rodar, e o que ela confere |
| [`docs/design.md`](docs/design.md) | vai mexer no visual: tokens, cor, selo de verificação, componentes |
| [`docs/mockups/`](docs/mockups/) | as sete telas de referência do design |
| [`docs/api.md`](docs/api.md) | vai chamar a API: base, token, paginação, erros |
| [`docs/endpoints.md`](docs/endpoints.md) | os 10 endpoints com respostas reais |
| [`docs/modelo-de-dados.md`](docs/modelo-de-dados.md) | entidades da API e anatomia do código TOS |
| [`docs/massa-de-dados.md`](docs/massa-de-dados.md) | o que existe na base fictícia |
| [`docs/glossario.md`](docs/glossario.md) | ART, CAT, CAO, RNP, TOS |
| [`fixtures/`](fixtures/) | uma resposta real por endpoint, para desenvolver sem chamar a API |
| [`docs/edital.pdf`](docs/edital.pdf) · [`docs/proposta-fase1.pdf`](docs/proposta-fase1.pdf) | os originais |

## Equipe

- **Gabriel Cortez de São Paulo Rozeno** · arquitetura, back-end, integração com a API, motor de
  compatibilização, autenticação e cadastro
- **Camila Vasconcelos Moi** · arquitetura, motor de compatibilização, segurança, privacidade e
  LGPD, auditoria e trilha, painel administrativo, interface e experiência, revisão

## Aviso

Os dados consumidos são **fictícios**, fornecidos pela organização do desafio, e não têm validade
legal. A correspondência entre perfil e demanda é apenas indicativa: não constitui ranking,
certificação de qualidade nem recomendação institucional (edital, itens 10.1 e 10.2).
