# Estrutura de diretórios

Exigido pelo edital, Anexo I, item 8.3.2f. Reflete o que está no repositório em 17/09/2026;
diretório só é criado quando o primeiro arquivo entra, porque o git não versiona pasta vazia.

```
pro-link/
├── _arq/                    documentação de entrega (obrigatório — item 8.3a)
│   ├── estrutura.sql        criação do banco: 28 tabelas, 1 view, índices, 28 FKs, 2 triggers
│   ├── carga-inicial.sql    5 perfis, 12 parâmetros, 25 modalidades, 2000 códigos TOS, termos
│   ├── README.md            instalação, configuração, execução, verificação e atualização
│   ├── arquitetura.md       camadas, módulos, integrações, segurança e a declaração de IA (12.3)
│   ├── estrutura-diretorios.md  este arquivo
│   ├── dependencias.md      bibliotecas de terceiros, versões e licenças (8.3.2e / Anexo V)
│   └── mer/                 o MER: um diagrama por módulo, o completo, o PDF e o README que os lê
│
├── _config.php              configuração central (obrigatório — item 8.3b)
├── .env.example             modelo de variáveis de ambiente, com todo campo sensível vazio
├── composer.json / .lock    dependências PHP; o lock é o que a banca recebe
├── docker-compose.yml       ambiente reproduzível (item 8.8): nginx, php-fpm, mariadb, mailpit
├── phpunit.xml              configuração da suíte de unidade
│
├── public/                  document root do nginx; o único diretório exposto
│   ├── index.php            front controller: rotas, sessão, perfil por rota, CSRF
│   └── assets/
│       ├── css/prolink.css  o design system da plataforma
│       ├── js/              comportamento de tela; nada de framework nem passo de build
│       └── vendor/          Bootstrap 5.3.3 e as fontes Inter e Space Grotesk, servidas daqui
│                            e nunca de CDN (a CSP do nginx está fechada em 'self')
│
├── src/                     código da aplicação, namespace ProLink\
│   ├── Controller/          recebe requisição, devolve resposta; sem regra de negócio
│   ├── Service/             regra de negócio, integrações e os limites de segurança
│   ├── Repository/          o único lugar que escreve SQL, sempre com prepared statement
│   └── Support/             infraestrutura: Database, Router, View, Sessao, Flash, Auditoria,
│                            Requisicao, Crypto, Csrf, Tos, Compatibilidade, Parametros,
│                            Visibilidade, Transporte (curl e fixture)
│
├── templates/               Twig (item 8.2: sem PHP no HTML). 42 telas ao todo
│   ├── layout/              esqueleto, macros de formulário (_form) e componentes (_ui)
│   ├── auth/                entrar, cadastrar, recuperar e redefinir senha
│   ├── inicio/              o Início de cada perfil
│   ├── perfil/              o perfil do profissional e o da empresa; o perfil público reusa os dois
│   ├── busca/               a busca de profissionais, aberta a quem não tem conta
│   ├── demanda/             demandas, o feed de compatíveis e a tela de abertas
│   ├── manifestacao/        manifestar interesse, interessados e as mensagens
│   ├── denuncia/            abertura de denúncia, sempre contra um alvo
│   ├── privacidade/         consentimento, exportação e exclusão de conta (item 11.3)
│   ├── email/               os corpos das notificações da RF07
│   └── admin/               painel: indicadores, auditoria, sessões do motor, denúncias,
│                            contas, parâmetros, integrações e lixeira
│
├── tests/                   suíte de unidade (193 testes): Service, Support, Dados e os dublês
├── e2e/                     suíte de ponta a ponta em Playwright, fora do contêiner
│   ├── specs/               cenarios, demonstracao, jornadas, refinamento, responsivo, seguranca
│   ├── apoio/               contas, ações comuns e as legendas sobrepostas aos vídeos
│   └── videos/              os quatro vídeos gravados (fora do controle de versão: são gerados)
│
├── scripts/                 utilitários de linha de comando: criar-admin, semear-candidatos,
│                            os verificar-* por etapa, verificar-tudo.sh, gerar-mer.py,
│                            atualizar_tos.py e empacotar-entrega.sh
├── docker/                  Dockerfile do PHP, php.ini e o virtual host do nginx
├── docs/                    documentação de projeto: edital, requisitos, endpoints da API,
│                            modelo de dados, matching, fluxos, backlog, roteiro da demonstração,
│                            a autoavaliação do Anexo VI, o registro de decisões e os mockups
├── data/csv/                massa de referência da organização
├── fixtures/                uma resposta real da API por endpoint, usada pelos testes
└── storage/                 cache do Twig e logs — fora do controle de versão
```

## O que fica de fora do repositório, e por quê

`vendor/`, `e2e/node_modules/`, `e2e/resultados/`, `e2e/relatorio/`, `e2e/videos/`, `storage/` e
`.env` estão no `.gitignore`. Os quatro primeiros são reproduzíveis com um comando; os vídeos
pesam dezenas de MB e `e2e/README.md` diz como refazê-los; o `.env` carrega segredo, e o item
8.3.1j manda que ele nunca seja versionado.

`_arq/migracoes/` entra quando houver a primeira alteração de schema depois da entrega inicial.
