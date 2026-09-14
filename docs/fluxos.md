# Fluxos

O caminho completo da aplicação, tela a tela. Cada bloco é uma tela, cada seta uma ação, e a nota
diz qual chamada de API dispara ali. Serve para ver o todo antes de construir cada template e para
amarrar os seis cenários do Anexo I (item 7) a um caminho concreto. Em que etapa cada cenário
destrava está no `backlog.md`; os endpoints, em `endpoints.md`; o motor, em `matching.md`; o visual
de cada tela, em `design.md`.

```mermaid
flowchart TD
  LAND["Landing pública<br/>com barra de pesquisa"]
  RESULTS["Resultados da busca<br/>perfis públicos, só de quem autorizou busca sem cadastro"]
  GATEC{"Visitante tem cadastro?"}
  PERFC["Perfil público do profissional<br/>/perfil/{id} — respeita a visibilidade"]
  LOGIN["Login<br/>CPF ou CNPJ"]
  ESC{"Novo cadastro:<br/>qual perfil?"}

  LAND -->|Entrar| LOGIN
  LAND -->|Usar a plataforma| ESC
  LAND -->|Barra de pesquisa| RESULTS
  RESULTS -->|Clicar num perfil| GATEC
  GATEC -->|Sim| PERFC
  GATEC -->|Não, precisa cadastrar| ESC
  LOGIN -->|Profissional| DASHP
  LOGIN -->|Empresa| DASHE
  ESC -->|Profissional| CADP
  ESC -->|Empresa| CADE

  subgraph PROF["PERFIL PROFISSIONAL"]
    CADP["Cadastro profissional<br/>+ consentimento LGPD<br/>API: profissionais?cpf, /arts, /cats"]
    DASHP["Dashboard profissional"]
    PORT["Portfólio verificado + selo ART<br/>API: validação de art, art/atividades, validação de cat"]
    VISP["Visibilidade + dados complementares"]
    FEEDP["Demandas compatíveis"]
    CADP --> DASHP
    DASHP --> PORT
    DASHP --> VISP
    DASHP --> FEEDP
  end

  subgraph EMP["PERFIL EMPRESA"]
    CADE["Cadastro empresa<br/>+ consentimento LGPD<br/>API: empresas?cnpj, quadro-técnico, cao"]
    DASHE["Dashboard empresa"]
    PERFE["Acervo operacional da empresa<br/>API: cao"]
    PUBD["Publicar demanda<br/>seleção de TOS. API: tos?search"]
    MIND["Minhas demandas"]
    FEEDE["Feed de compatíveis<br/>matching local, aderência por dimensão, SEM ranking"]
    PERFV["Perfil do profissional<br/>snapshot no momento do match"]
    CADE --> DASHE
    DASHE --> PERFE
    DASHE --> PUBD
    DASHE --> MIND
    PUBD --> FEEDE
    FEEDE -->|Ver compatível| PERFV
  end

  FEEDP -->|Manifestar interesse| MANIF
  PERFV -->|Manifestar interesse| MANIF

  MANIF["Manifestar interesse<br/>grava snapshot do perfil"]
  COMM["Mensagens simples na plataforma<br/>sem expor contato, sem chat completo"]
  NOTIF["Notificação por e-mail<br/>fila + SMTP"]
  MANIF --> COMM
  MANIF --> NOTIF
  NOTIF -.-> DASHP
  NOTIF -.-> DASHE

  subgraph ADMG["ADMIN (RF06)"]
    ADM["Painel admin"]
    AUD["Trilha de auditoria<br/>log imutável"]
    DEN["Fila de denúncias + moderação"]
    USRG["Gestão de usuários"]
    ADM --> AUD
    ADM --> DEN
    ADM --> USRG
  end
```

**Legenda:** seta cheia = navegação por ação do usuário; seta pontilhada = notificação automática.

**Cobertura da API.** Os 10 endpoints estão amarrados a alguma tela: `profissionais`/`arts`/`cats`
no perfil do profissional; `empresas`/`quadro-técnico`/`cao` no perfil da empresa; `tos` na
demanda; validação de `arts`/`cats` no selo. Nenhuma varredura — a consulta é sempre pelo cadastro
do candidato (item 10.4).

## Fluxos críticos vs. não-críticos

O diferencial que fica visível na documentação e no vídeo: separar o que **toca a API oficial ou
grava/altera dado** do que é só leitura. É onde a revisão de segurança da E7 concentra o esforço.

- **Críticos** — autenticação (login/cadastro), validação de ART/CAT pela API (o selo), escrita no
  histórico/acervo (importação, associação de ART, herança pelo CAO), manifestação com snapshot,
  e moderação (bloqueio/denúncia). São transacionais e auditados.
- **Não-críticos** — busca pública, ver perfil público, visualização do feed, notificação por
  e-mail. Falha aqui degrada a experiência, não a integridade.

## Os seis cenários (Anexo I, item 7) no fluxo

O `backlog.md` diz em que etapa cada um destrava; aqui, o caminho:

1. **Profissional cria perfil e vê acervo com selo** — CADP → DASHP → PORT (destrava na E2).
2. **Empresa publica demanda** — CADE → DASHE → PUBD (E3).
3. **Compatibilização** — PUBD → FEEDE, card abre a explicação por dimensão, sem ranking (E4).
4. **Manifestação e a empresa vê o perfil** — FEEDP/PERFV → MANIF → COMM/NOTIF (E5).
5. **Correção e denúncia** — VISP + denúncia; ADM → AUD/DEN (E6).
6. **Admin vê auditoria e modera** — ADM → AUD → DEN, bloqueio (E6).
