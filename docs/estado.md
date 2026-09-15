# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

15/09/2026, tarde. Gabriel: revisão de código do repositório inteiro, e os consertos que ela achou.

## Onde parou

Branch `claude/main-branches-status-9k0bun`, dois commits, **não integrada na `main`**.
O motor rodava com quatro das seis dimensões do item 3.2 — contrato e abrangência não tinham
caminho de escrita, e a abrangência comparava UF com texto livre (D45). Corrigidos, com formulário
no perfil. Mais: documento cifrado lido num ponto só, portão de privacidade do pool em lote (era
N+1), CSP conferida por máquina (D50), `|raw` fora do macro de caixa.
Verificado: 188 testes · 24 telas · 25 conferências estáticas, 0 violações.

## Próximo passo

**Subir o Docker e rodar os quatro itens de "Lembrar"** — é curto e destrava o resto. Depois, **o
feed do demandante**, camada visível da E4 e cenário 3: mockup aprovado em
`docs/mockups/prolink-feed-imersivo-v3.html`, tela nova passa pelo `designer-ui`, e
`CompatibilizacaoService::executar()` segue sem nenhum chamador HTTP.

## Decisões pendentes

- **Servir Bootstrap e as fontes localmente** e fechar a CSP em `'self'` — ver Consequência da D50.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita?
- MER (`_arq/mer/`) e a declaração de uso de IA (12.3) seguem **sem dono**, ambos obrigatórios na
  entrega. A `decisoes.md` chegou a 50 entradas: a matéria-prima da 12.3 está lá.

## Lembrar

**Esta sessão mudou configuração que não teve como exercitar. Com o Docker de pé, nesta ordem:**

1. Abrir uma tela e **olhar o console do navegador**. A CSP nova é o que mais pode quebrar, e
   bloqueio dela não dá erro visível — se o Bootstrap não carregar, a tela aparece sem estilo.
2. `curl -s localhost:8080/saude`. Entrou `try_files $uri =404` no `location ~ \.php$` do nginx;
   é o padrão canônico, mas se estiver errado **toda** rota dá 404.
3. `verificar-padrao.php` com banco: a parte de HTML renderizado não roda desde a mudança, e as
   quatro telas novas acabaram de entrar em `amostras.php` — é a estreia delas ali.
4. `verificar-e4.php` e `verificar-e6.php`, que não rodaram. O E4 exercita o motor consertado.

- **Antes da demonstração: recarregar o banco e rodar `semear-candidatos.php`.** Agora há um motivo
  a mais, na Consequência da D45: demanda antiga perde o tipo de contrato ao ser editada.
- **Escreva as demandas do roteiro olhando o índice**, nunca antes: os vínculos ART para TOS são
  aleatórios, e demanda escolhida por tema não encontra ninguém.
- `verificar-e1.php` acusa uma falha quando a API está acessível: o script gera CNPJ sintético e a
  D26 rebaixa para Terceiro PJ. Acoplamento do teste, não regressão.
- Nenhum e-mail sai sozinho: `despachar()` existe e nada o chama. Gatilho é item da E5.
- Contas locais: `camila@prolink.local` (admin) e as 17 semeadas, todas com `ProLinkDemo2026!`.
