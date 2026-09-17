# Suíte de ponta a ponta

O que os `scripts/verificar-*.php` não conseguem provar: que os **seis cenários mínimos do Anexo I**
rodam pelo navegador, clicando onde uma pessoa clicaria.

A diferença não é preciosismo. Os verificadores conversam com serviço e repositório, e provam a
regra de negócio; a tela de auditoria já subiu em 500 com dezesseis conferências no verde, porque
nenhuma delas tocava o template. Aqui o navegador carrega a página de verdade, com sessão, CSRF,
JavaScript e redirecionamento.

## Os dois arquivos, e por que são dois

| Arquivo | Finalidade |
|---|---|
| `specs/cenarios.spec.js` | **verifica**: seis testes, conferência dura, falha rápido. É o que se roda antes de commitar. |
| `specs/demonstracao.spec.js` | **mostra**: um teste, um contexto, **um vídeo só**, no ritmo de quem apresenta, com legenda sobreposta. É o insumo do vídeo demonstrativo e o ensaio da demo. |
| `specs/refinamento.spec.js` | **compara com o desenho**: prova que cada tela é o mockup de `docs/mockups/`, e não só que ela funciona. |
| `specs/responsivo.spec.js` | as mesmas telas em 390px. |
| `specs/seguranca.spec.js` | o que a plataforma precisa recusar. |

### O que o refinamento cobra, e por quê

Nasceu de uma reprovação: telas numa coluna estreita no meio do monitor, duas barras de navegação
diferentes convivendo, e a landing sem as animações do mockup. Nenhuma das três aparecia em teste
nenhum, porque todas as telas **funcionavam**. São quatro réguas:

1. **Uma barra por situação.** Barra lateral navy para quem está dentro da conta, barra pública do
   mockup para o resto, nunca as duas, e nunca a navbar do Bootstrap que o projeto usava antes.
2. **Largura total**, com 90% de folga: o alvo é a coluna de 960px no meio de um monitor de 1600.
3. **Console limpo.** Foi um bloqueio silencioso de política de segurança que apagou as animações.
4. **O canvas da landing pinta pixel.** Canvas existente e nunca desenhado dá o mesmo resultado
   visual de canvas nenhum, e foi por aí que a animação sumiu sem ninguém notar.

## Como rodar

O ambiente precisa estar de pé (`docker compose up -d`) e respondendo em `http://localhost:8080`.

As telas do painel administrativo só são conferidas com uma conta de administração no ambiente, e
o teste **pula** em vez de falhar quando ela não está configurada, porque falta de configuração de
quem roda não é defeito da aplicação:

```bash
export PROLINK_E2E_ADMIN_EMAIL=e2e.admin@verificacao.local
export PROLINK_E2E_ADMIN_SENHA='...'        # nunca em arquivo versionado (Anexo VI)
```

A conta de verificação é criada por `scripts/criar-admin.php`, e é **outra** conta que não a de
demonstração: bloquear, moderar e mexer em parâmetro deixa rastro na trilha, e o rastro da suíte
não pode se misturar com o que a banca vai ler.

```bash
cd e2e
npm install                     # só na primeira vez

# um administrador com credencial conhecida, que a suíte usa nos cenários 5 e 6
docker compose exec -T php php scripts/criar-admin.php \
  --nome="Administração E2E" --email="e2e.admin@verificacao.local" --senha="<escolha uma>"

export PROLINK_E2E_ADMIN_SENHA="<a mesma>"

npx playwright test --project=desktop                      # os seis cenários
npx playwright test --project=celular                      # os marcados @responsivo, em 390px
npx playwright test specs/demonstracao.spec.js             # grava a jornada completa
npx playwright show-report relatorio                       # abre o relatório
```

O vídeo de cada execução fica em `resultados/<teste>/video.webm`, e o Playwright **apaga esse
diretório inteiro** no começo da execução seguinte. Para guardar o da jornada completa:

```bash
npx playwright test specs/demonstracao.spec.js \
  && cp resultados/demonstracao*/video.webm videos/demonstracao-jornada-completa.webm
```

A cópia é um passo de fora de propósito: `video.saveAs()` dentro do teste espera o arquivo fechar,
e o arquivo só fecha quando o contexto fecha, o que acontece depois do corpo do teste. Chamá-lo lá
trava a execução até o tempo estourar.

### Nenhuma senha mora neste diretório

`PROLINK_E2E_ADMIN_SENHA` não tem valor padrão, e a suíte falha com instrução clara se ela faltar.
O Anexo VI do edital lista "não contém credenciais, segredos ou chaves reais" como item de
triagem, e conta de administração com senha versionada seria exatamente isso.

A senha das contas de demonstração (`PROLINK_E2E_SENHA_DEMO`) tem padrão porque é a mesma que
`scripts/semear-candidatos.php` imprime ao criá-las, e já está no repositório desde que aquele
script existe.

## O que a suíte confere em toda tela

- **Sem erro de servidor** e sem rastro de pilha vazado (item 8.5).
- **Sem rolagem horizontal**, em 1440px e em 390px (Anexo I item 5, acessibilidade e mobile).
- **Sem identificador de sistema visível**: nada de `sis_usuarios`, `pro_denuncias` ou
  `ACESSO_NEGADO` na cara de quem lê. O valor cru pode ficar em `title=""`.

E, nos cenários onde cabe:

- **Selo de verificação presente** no acervo, distinguindo o que a API confirmou do declarado.
- **Nenhum sinal de ranking** na tela do motor, e a declaração de que a correspondência é
  indicativa (itens 10.1 e 10.2).
- **Confirmação antes de ação irreversível**: manifestar interesse não pode ser desfeito, e a
  plataforma avisa **antes** de agir. A suíte confere que o aviso existiu, e não apenas que a ação
  funcionou.

## Armadilhas que custaram tempo aqui

- **A confirmação de ação irreversível some sem o `dialog` ser aceito.** Formulário com
  `data-confirmar` é interceptado por JavaScript; o Playwright **recusa** diálogo por padrão,
  então o clique parece funcionar, o POST nunca sai, e o teste falha três passos adiante por um
  motivo que não é o verdadeiro. `apoio/acoes.js::aceitarConfirmacoes` resolve.
- **A busca da Tabela de Obras e Serviços é textual, não por código.** Procurar `10.4.2.3` não
  acha nada; procurar "Planejamento Urbano" acha. O teste escolhe o botão do formulário cujo
  `codigo` escondido bate exatamente, porque a busca devolve dezenas de resultados e clicar no
  primeiro traria um código vizinho, mudando o cenário em silêncio.
- **`/denuncias/nova` sem alvo devolve 404, e isso é regra.** Denúncia é sempre contra alguma
  coisa; genérica não tem o que moderar.
- **A demanda escolhida para o cenário sai do índice de evidência, não do gosto de quem escreve.**
  Os vínculos de ART para código TOS da massa fictícia são aleatórios: `TOS_10.4.2.3` foi escolhido
  por ser o código com mais candidatos, e só então a demanda foi escrita em torno dele. A ordem
  inversa produz um cenário sem compatível nenhum.

## Uma execução por vez

`workers: 1` limita o paralelismo **dentro** de uma execução, e não impede duas execuções
simultâneas de se atrapalharem. Elas compartilham o mesmo banco e as mesmas contas: uma publica a
demanda que a outra vai compatibilizar, as duas manifestam interesse com o mesmo profissional, e o
limite de manifestações por hora conta as duas juntas.

O sintoma é sempre o mesmo e engana: o cenário 4 estoura o tempo em `waitForURL`, como se o botão
de manifestar não funcionasse. Aconteceu em 17/09, com duas sessões de trabalho rodando a suíte ao
mesmo tempo, e o servidor respondia 303 normalmente quando sondado à mão.

Antes de acreditar numa falha do cenário 4, confira se não há outra execução no ar:

```bash
pgrep -fl playwright
```

## A suíte cria dado real

Ela cadastra experiência, publica demanda, manifesta interesse, denuncia e modera, tudo pela
interface. Isso é o ponto: atalho por SQL provaria que o sistema funciona por um caminho que
ninguém percorre.

A consequência é que o banco acumula registro de verificação a cada execução. **Rode a suíte antes
de recarregar o banco para a demonstração**, nunca depois. A ordem de preparo da demo está no
`docs/estado.md`.

## Licença da ferramenta

Playwright é **Apache-2.0**, roda fora do contêiner e nada dele é servido pela aplicação. Mesmo
estatuto do Python em `scripts/`: ferramenta de desenvolvimento, não dependência do produto. Está
declarado em `_arq/dependencias.md` (item 16.5 do edital).

## Os quatro vídeos, e por que são quatro

| Arquivo | O quê |
|---|---|
| `demonstracao.spec.js` | **a narrativa do desafio**: os seis cenários do Anexo I, item 7, na ordem em que são numerados, num vídeo só. Salta de conta em conta porque o cenário 4 exige os dois lados da mesma interação. |
| `jornadas.spec.js` | **uma conta por vídeo**, do login ao logout, percorrendo tudo que o Anexo I, item 3, diz que aquele perfil precisa poder fazer. Três vídeos: profissional, empresa e administração. |

A diferença é a pergunta que cada um responde. O primeiro responde "a plataforma
cumpre os seis cenários?". Os outros respondem "o que um profissional faz aqui?",
e quem avalia vê a resposta inteira sem trocar de papel no meio.

```bash
./rodar.sh specs/jornadas.spec.js --project=desktop
mkdir -p videos
cp "resultados/jornadas-*profissional*/video.webm"   videos/jornada-profissional.webm
cp "resultados/jornadas-*empresa*/video.webm"        videos/jornada-empresa.webm
cp "resultados/jornadas-*administra*/video.webm"     videos/jornada-administracao.webm
```

Os arquivos ficam fora do versionamento: pesam dezenas de MB e são gerados.

## A credencial da administração

Metade dos cenários precisa entrar como administração, e a senha não pode viver em
arquivo versionado (Anexo VI, item 5). Ela fica em `e2e/.env.local`, que o
`.gitignore` recusa, e `./rodar.sh` a carrega só para o processo do Playwright:

```bash
cat > e2e/.env.local <<'ENV'
PROLINK_E2E_ADMIN_EMAIL=...
PROLINK_E2E_ADMIN_SENHA=...
ENV

./rodar.sh                                   # a suíte inteira
./rodar.sh specs/cenarios.spec.js            # um arquivo
```

Sem o arquivo a suíte roda igual, e os testes de administração **pulam** em vez de
falhar: falta de configuração de quem roda não é defeito da aplicação.
