# Estrutura de diretórios

Exigido pelo edital, Anexo I, item 8.3.2f.

```
pro-link/
├── _arq/                    documentação e apoio (obrigatório — item 8.3a)
│   ├── estrutura.sql        criação do banco: 29 tabelas, índices, FKs, triggers
│   ├── carga-inicial.sql    perfis, parâmetros, 25 modalidades, 2000 códigos TOS
│   ├── migracoes/           alterações de schema posteriores à entrega inicial
│   ├── mer/                 Modelo Entidade-Relacionamento (PDF, PNG, .mwb)
│   ├── README.md            instalação, configuração, execução e atualização
│   ├── arquitetura.md       documentação técnica da solução
│   ├── estrutura-diretorios.md  este arquivo
│   └── dependencias.md      bibliotecas de terceiros e licenças
│
├── _config.php              configuração central (obrigatório — item 8.3b)
├── .env.example             modelo de variáveis de ambiente, sem segredos
│
├── public/                  document root do nginx; o único diretório exposto
│   ├── index.php            front controller — toda requisição entra por aqui
│   └── assets/              css, js, imagens e uploads
│
├── src/                     código da aplicação, namespace ProLink\
│   ├── Controller/          recebe requisição, devolve resposta; sem regra de negócio
│   ├── Service/             regra de negócio e integrações (motor, API, notificação)
│   ├── Repository/          acesso a dados; o único lugar que monta SQL
│   ├── Model/               entidades do domínio
│   └── Support/             infraestrutura: Database, Router, View, Crypto, Csrf, Tos
│
├── templates/               Twig — apresentação, sem PHP misturado (item 8.2)
│   ├── layout/              esqueleto e componentes reaproveitados
│   ├── auth/                cadastro, login, recuperação de senha
│   ├── perfil/              perfil do profissional e da empresa
│   ├── demanda/             publicação, feed de compatíveis, manifestação
│   └── admin/               painel, moderação, auditoria
│
├── docker/                  ambiente reproduzível (item 8.8)
│   ├── php/                 Dockerfile e php.ini
│   └── nginx/               virtual host
│
├── data/                    massa de referência da organização
│   ├── csv/                 TOS, modalidades, profissionais, empresas, ARTs, CATs
│   └── prolink-massa-de-dados.xlsx
│
├── docs/                    documentação de projeto (API, modelo, motor, edital)
├── fixtures/                respostas reais da API, para desenvolver sem chamá-la
├── scripts/                 utilitários de linha de comando
├── storage/                 cache do Twig e logs — fora do controle de versão
└── tests/                   PHPUnit
```

## Por que MVC-S

O edital exige MVC ou equivalente com separação de apresentação, regra de negócio e acesso a
dados (itens 8.1.1c e 8.2). Adotamos MVC com **camada de serviço**, o que dá quatro camadas:

```
público  →  public/index.php  →  Controller  →  Service  →  Repository  →  MariaDB
                                      ↓             ↓
                                   Twig      CreaApiClient → API oficial
```

O motivo é concreto: o motor de compatibilização, o cliente da API, a trilha de auditoria e o
selo de integridade têm lógica demais para caber num controller sem virar bolo. Controllers
ficam finos — recebem, validam entrada, delegam, devolvem. Serviços concentram a regra e os
limites de segurança. Repositórios são o único lugar do código que escreve SQL, sempre com
prepared statement.

## Regra de dependência

Cada camada só conhece a de baixo. Controller chama Service, Service chama Repository,
Repository fala com o banco. Nada volta para cima: um Repository não instancia Controller, um
Service não monta HTML. Quando uma camada precisa de algo de fora, recebe por construtor.
