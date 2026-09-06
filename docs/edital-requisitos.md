# Requisitos do Edital

Destilado de `edital.pdf` (25 páginas) e do seu Anexo I — Termo de Referência. O que está
aqui é obrigatório ou avaliado. A numeração é a do edital, para citar na documentação de entrega.

## Prazos

| Marco | Data |
|---|---|
| Desenvolvimento e mentorias | 27/08 a 16/09 |
| **Entrega do protótipo e documentação** | **17/09/2026, até 18h** |
| Testes e seleção dos finalistas | 18 a 21/09 |
| Divulgação dos finalistas | 22/09 |
| Credenciamento e ensaio técnico | 24/09 |
| Demo Day (pitch presencial) | 26/09 |

## Stack obrigatória (Anexo I, 8.1)

| Camada | Exigência |
|---|---|
| Back-end | PHP 8.2+, POO, Repository/DAO, MVC ou equivalente, Composer quando aplicável |
| Banco | MariaDB 10.11+, `utf8mb4`, `utf8mb4_unicode_ci` |
| Front | HTML5, CSS3, JavaScript, **Bootstrap 5**, jQuery ou equivalente |
| Templates | Twig, Smarty, Blade, Plates ou solução própria — **PHP não pode ser misturado no HTML** (8.2) |

Outras bibliotecas são permitidas desde que compatíveis e com licença de uso institucional.

## Estrutura de diretórios obrigatória (8.3)

Duas exigências literais, ambas fáceis de esquecer:

**a) O diretório `_arq/`**, para arquivos de apoio e documentação. Deve conter, no mínimo (8.3.2):

| Item | O quê |
|---|---|
| `estrutura.sql` | criação do banco, tabelas, índices, chaves, relacionamentos e carga inicial |
| MER | em PDF, PNG e/ou `.mwb` (MySQL Workbench) |
| README | instalação, configuração, execução e atualização, com requisitos mínimos |
| Documentação técnica | arquitetura, estrutura de diretórios, componentes, módulos, integrações, regras de negócio |
| Dependências | relação de bibliotecas e frameworks de terceiros com versões |
| Estrutura de diretórios | documento descrevendo a finalidade de cada pasta principal |

**b) O arquivo `_config.php` na raiz da aplicação**, centralizando (8.3.1):

URLs da aplicação; PATHs físicos; `PATH_IMG`; parâmetros de conexão com banco, APIs e serviços
externos; constantes globais; configuração de ambiente (dev/homologação/produção); fuso horário
**America/Manaus**; charset **UTF-8**; SMTP; e chaves/tokens preferencialmente vindos do `.ENV`.

O `.ENV` entregue deve ser **apenas modelo** (`.ENV.example`), sem credenciais reais.

## Banco de dados (8.6)

Integridade referencial, PKs, FKs e índices. Padrão de nomenclatura recomendado, que vale seguir
porque é o que a banca vai reconhecer:

| Regra | Exemplo |
|---|---|
| Tabela: prefixo do módulo + entidade, minúsculas, `_` | `sis_usuarios`, `pro_demandas`, `pro_arts` |
| Campo: prefixo de três letras da tabela + atributo | `usu_id`, `usu_nome`, `dem_titulo` |
| PK: `<prefixo>_id`; constraints `pk_` e `fk_` | `pk_usu_id`, `fk_dem_usu_id` |
| Campos de controle em toda tabela | `xxx_dt_registro`, `xxx_log`, `xxx_status` |
| `xxx_status` | `CHAR(1)`, default `'A'` (ativo) |

**Exclusão lógica obrigatória (8.6j).** Nada é apagado fisicamente. `xxx_status = 'X'` marca
excluído, some das consultas operacionais e continua acessível só por mecanismo administrativo
de lixeira.

## Segurança mínima (8.5)

Autenticação segura; senhas com `password_hash()`; proteção contra SQL Injection, XSS e CSRF;
controle de perfis de acesso; registro de auditoria.

O item 11.3 do edital principal acrescenta: criptografia em trânsito e repouso, gestão de
consentimento, descarte, portabilidade, correção, revogação de acesso e resposta a incidentes.

## Integração com a API (8.4)

Consumo **obrigatório**. Token individual por equipe, via plataforma oficial.

Duas vedações que moldam a arquitetura:

- **"É vedada a criação de base própria para simular os dados disponibilizados pela API oficial."**
  O índice local é cache de resposta real, reconstruível, nunca fonte substituta.
- **Item 10.4: proibida a coleta automatizada de dados.** Nada de varrer os 200 identificadores
  da massa. Busca no cadastro do candidato, atualização sob demanda.

A aplicação pode cadastrar PF e PJ **sem** registro no CREA-AM (perfil Terceiros).

## Limites da solução (10.1, 10.2, 12.2)

O que a plataforma **não** pode fazer: contratação automática, intermediação financeira, garantia
de preço, certificação de qualidade, recomendação institucional, **ranking de profissionais**,
reserva de mercado. A correspondência é apenas indicativa.

Vedado discriminar por raça, cor, sexo, gênero, deficiência, idade, religião, origem, condição
social ou qualquer fator alheio à capacidade técnica (12.2).

Uso de IA deve ser declarado, documentado e com supervisão humana; os critérios de recomendação e
os riscos de viés precisam ser explicados (12.3).

A consequência arquitetural está em `matching.md`: score filtra o pool, semente ordena.

## Perfis de usuário (Anexo I, 3)

| Perfil | Precisa poder |
|---|---|
| Público | pesquisar profissionais (especialidade, experiência, nome); ver perfil |
| Profissional (registrado) | tudo do público + criar/editar perfil, selecionar competências, publicar experiência com ou sem ART/CAT, controlar visibilidade, receber e responder oportunidades |
| Empresa (registrada) | tudo do público + criar/editar perfil, publicar demandas, publicar experiência, pesquisar profissionais, registrar interesse |
| Terceiros (PF/PJ sem registro) | tudo do público + criar perfil, publicar demandas, pesquisar, verificar informações, registrar interesse em profissional **ou empresa** |
| Administrador | painel próprio: moderar, gerir perfis, auditar, tratar denúncias, emitir relatórios, configurar integrações |

Note que Terceiros podem registrar interesse em **empresa**, não só em profissional — ou seja, a
empresa também é candidata do motor, não só demandante.

## Requisitos funcionais (Anexo I, 4)

RF01 usuários/perfis/privacidade · RF02 integração com a API · RF03 portfólio profissional ·
RF04 gestão e compatibilização de demandas · RF05 manifestação de interesse e comunicação ·
RF06 administração · RF07 notificações por e-mail com SMTP configurável.

## Cenários mínimos de demonstração (Anexo I, 7)

1. Profissional cria perfil e informa competência ligada a uma experiência.
2. Empresa publica demanda com escopo, localização e requisitos.
3. Sistema apresenta correspondências e explica os principais critérios.
4. Profissional manifesta interesse e a empresa visualiza o perfil.
5. Usuário corrige ou restringe dados e registra denúncia.
6. Administrador visualiza trilha de auditoria e atua na moderação.

São seis fluxos que precisam rodar ao vivo. Servem de definição de pronto do MVP.

## Entrega (8.7, 8.8)

Repositório GitHub informado na plataforma, com **histórico completo** de commits, mais um `.zip`.
O repositório precisa conter: código-fonte, histórico de commits, scripts SQL de criação e carga,
documentação técnica, README de instalação, e arquivos de ambiente automatizado —
preferencialmente Docker (`Dockerfile`, `docker-compose.yml`).

O ambiente precisa subir de forma **padronizada, reproduzível e automatizada** para a banca, com o
banco já carregado. Arquivos Docker não podem conter senha, token ou credencial real.

## Como a banca pontua (Anexo IV, Ficha B)

| Critério | Peso |
|---|---|
| Funcionalidade e experiência (fluxos, usabilidade, acessibilidade, estabilidade, demo ao vivo) | 20 |
| Viabilidade técnica (fidelidade ao Anexo I, arquitetura, interoperabilidade) | 15 |
| Segurança, privacidade e ética (LGPD, acessos, vulnerabilidades, viés) | 15 |
| Impacto e valor institucional | 15 |
| Aderência ao desafio e à proposta aprovada | 15 |
| Maturidade e continuidade (organização do código, implantação, documentação) | 10 |
| Inovação e diferencial | 10 |

Metade da nota (funcionalidade + viabilidade + maturidade = 45) depende de coisas que a
documentação e o ambiente Docker entregam, não só o código.

## Checklist de triagem (Anexo VI)

A banca pode usar como filtro. Vale rodar como autoavaliação antes de entregar:

- [ ] Executa as funcionalidades essenciais
- [ ] Utiliza apenas dados sintéticos/anonimizados/autorizados
- [ ] Possui instruções reproduzíveis de instalação e execução
- [ ] Identifica dependências, licenças e componentes de terceiros
- [ ] **Não contém credenciais, segredos ou chaves reais**
- [ ] Demonstra autenticação e segregação de perfis
- [ ] Prevê consentimento, correção e controle de visibilidade
- [ ] Possui trilhas mínimas de auditoria e moderação
- [ ] Apresenta critérios explicáveis de compatibilização
- [ ] Considera acessibilidade e uso em dispositivos móveis
- [ ] Documenta riscos, limitações e uso de inteligência artificial
- [ ] Entrega código-fonte e documentação no prazo
