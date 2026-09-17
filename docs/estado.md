# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

17/09/2026, madrugada — Camila, com Claude Code. Branch `claude/e7-lacunas`, **empurrada e não
mesclada**.

## Onde parou

**Os cinco itens da E7 que estavam sem dono foram fechados.** MER em `_arq/mer/` (8.3.2b), gerado
do banco ao vivo; texto real dos termos (11.3), na carga inicial e não só no banco daqui; os três
buracos do painel (indicadores, editor de parâmetros do 12.3, lixeira do 8.6j);
`sincronizar-status.php` (RF02); e a suíte de ponta a ponta em `e2e/`, que roda os seis cenários
pelo navegador e grava a jornada num vídeo com legenda.

Fechou também a última pendência de segurança da E7, **mudança de papel passa a valer na sessão
já aberta** (D68, requisição forjada: 303 antes, 403 depois), e as **catorze telas passaram a
caber em 390px**, o que levou o item 10 do Anexo VI de parcial para sim.

**`bash scripts/verificar-tudo.sh`: 9 de 9 no verde.** 188 testes · E1 80 · E2 151 · E4 32 ·
E6 53 · 36 telas · 59 conferências de padrão · 16 de navegador no desktop e 5 em 390px. Carga
inicial conferida num MariaDB limpo, e o `.zip` por `git archive` em 332 arquivos, sem `vendor/`,
`.env` nem `node_modules`.

## Próximo passo

**Decidir o merge de `claude/e7-lacunas` na `main`** — é de quem coordena, e não foi tomada.
Depois, nesta ordem: ensaiar o roteiro de `docs/roteiro-demo.md` duas vezes, recarregar o banco
como está escrito lá, `git tag entrega-fase3`, e subir repositório e `.zip` **antes das 18h**.

## Decisões pendentes

- `ARQUIVADA` em `man_situacao`: quem arquiva, e o que significa para quem manifestou.
- `prf_em_construcao` derivado na leitura em vez de coluna (a D63 recusou por agora).

## Lembrar

- **Rode a suíte de ponta a ponta ANTES de recarregar o banco**, nunca depois: ela cria demanda,
  manifestação e denúncia de verdade, pela interface.
- **`verificar-e2.php` falhou uma vez dentro da bateria e passou isolado nas cinco execuções
  seguintes.** Não reproduziu. Se repetir, o suspeito é a seção de sincronização, que mexe em
  visibilidade e nos carimbos de `prf_dt_sincronizacao` e os devolve ao fim.
- **A lixeira mostra 238 registros**, quase todos contas "Verificação E2" de rodadas anteriores. É
  a tela mais feia do painel se o banco não for recarregado antes da demonstração.
- **`verificar-e4.php` grava duas sessões do motor por execução** e `verificar-e6.php` abre
  denúncias: os dois sujam a primeira página do que a banca abre.
- **Não derrube o banco sem necessidade.** O volume guarda 19 candidatos semeados pelo fluxo real,
  que custaram perto de 40 chamadas registradas da API, e documento da massa usado uma vez fica
  consumido para sempre (D15).
- **Antes da demonstração**: recarregar e rodar `semear-candidatos.php`,
  `abrir-visibilidade-demo.php` e `preencher-declarados-demo.php`, nesta ordem.
- A conta `e2e.admin@verificacao.local` existe só para a suíte, e a senha vem do ambiente.
- **A senha do admin não é a das contas semeadas.**
- `ParametroRepository` faz cache estático por processo: mudar `sis_parametros` e chamar o serviço
  na mesma execução lê o valor antigo.
- `git pull` que toque `docker/nginx/default.conf` exige `up -d --force-recreate nginx`.
- `verificar-api.php` consome chamadas registradas: nunca rodar em laço.
