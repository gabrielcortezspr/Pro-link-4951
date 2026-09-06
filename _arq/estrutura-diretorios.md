# Estrutura de diretórios

Exigido pelo edital, Anexo I, item 8.3.2f. Reflete o que está no repositório; diretório só é
criado quando o primeiro arquivo entra, porque o git não versiona pasta vazia.

```
pro-link/
├── _arq/                    documentação de entrega (obrigatório — item 8.3a)
│   ├── estrutura.sql        criação do banco: 28 tabelas, 1 view, índices, FKs, triggers
│   ├── carga-inicial.sql    perfis, parâmetros do motor, 25 modalidades, 2000 códigos TOS
│   ├── README.md            instalação, configuração, execução e atualização
│   ├── arquitetura.md       documentação técnica: camadas, módulos, segurança, backlog
│   ├── estrutura-diretorios.md  este arquivo
│   └── dependencias.md      bibliotecas de terceiros e licenças
│
├── _config.php              configuração central (obrigatório — item 8.3b)
├── .env.example             modelo de variáveis de ambiente, sem segredos
├── composer.json / .lock    dependências PHP
├── docker-compose.yml       ambiente reproduzível (item 8.8)
│
├── public/                  document root do nginx; o único diretório exposto
│   ├── index.php            front controller
│   └── assets/              css, js, img
│
├── src/                     código da aplicação, namespace ProLink\
│   ├── Controller/          recebe requisição, devolve resposta; sem regra de negócio
│   ├── Service/             regra de negócio e integrações
│   └── Support/             infraestrutura: Database, Router, View, Sessao, Flash, Auditoria,
│                            Requisicao, Crypto, Csrf, Tos
│
├── templates/               Twig (item 8.2: sem PHP no HTML)
│   ├── layout/              esqueleto, macros de formulário (_form) e componentes
│   └── admin/               painel administrativo
│
├── docker/                  Dockerfile do PHP, php.ini, virtual host do nginx
├── docs/                    documentação de projeto, edital e proposta
├── data/csv/                massa de referência da organização
├── fixtures/                respostas reais da API
├── scripts/                 utilitários de linha de comando
└── storage/                 cache do Twig e logs — fora do controle de versão
```

Diretórios que entram conforme o backlog: `src/Repository/` e `src/Model/` (primeiro repositório
da RF01), `templates/auth|perfil|demanda/` (primeira tela de cada bloco), `_arq/mer/` (quando o
MER for gerado) e `_arq/migracoes/` (primeira alteração de schema após a entrega inicial).
