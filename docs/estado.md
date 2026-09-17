# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

17/09/2026, **dia da entrega** — Camila, com Claude Code. Branch `claude/e7-lacunas`, empurrada e
**não mesclada**.

## Onde parou

**A entrega virou de aparência.** As telas foram construídas seguindo o `design.md`, que é o
design system destilado dos mockups, e tinham se afastado deles. A autora reprovou, e a régua
passou a ser literal: `docs/mockups/` é o design final, e quem implementa porta e liga o dado
real (D73). Foram portadas a landing com a busca sem conta, a busca, a barra lateral navy em toda
tela de dentro da conta, os dois Início, o feed de compatíveis, o perfil, as interações, as
demandas, o painel administrativo e a família de entrada. A tela de entrar veio do mockup que a
autora entregou no meio do dia, e que não estava em `docs/mockups/`.

**Três lacunas de requisito fecharam.** `/admin/contas`, que era rota registrada sem tela e
devolvia 500; a busca da lista de contas, que devolvia 500 por marcador repetido na consulta
(`ATTR_EMULATE_PREPARES => false` recusa `:termo` duas vezes); e `/admin/integracoes`, a última
capacidade do Anexo I, item 3, sem caminho na aplicação (D75).

**A suíte cresceu de um vídeo para quatro**: a jornada dos seis cenários, mais uma por perfil, a
pedido da autora. E ganhou `specs/refinamento.spec.js`, que compara a tela com o desenho em vez de
com o funcionamento: uma barra por situação, largura total, console limpo, e o canvas da landing
pintando pixel de verdade.

**Placar**: 193 testes · E1 80 · E2 159 · E4 42 · E6 70 · 42 telas · 72 conferências de padrão ·
31 no navegador (26 desktop, dos quais 7 de refinamento; 5 em 390px).

## Próximo passo

**Decidir o merge de `claude/e7-lacunas` na `main`** — é de quem coordena, e não foi tomada.
Depois: `scripts/empacotar-entrega.sh`, que recusa empacotar com trabalho não commitado, com
credencial no `.env.example` ou sem os documentos do 8.3.2; `git tag entrega-fase3`; e subir
**antes das 18h**.

## Decisões pendentes

- `ARQUIVADA` em `man_situacao`: quem arquiva, e o que significa para quem manifestou.
- `prf_em_construcao` derivado na leitura em vez de coluna (a D63 recusou por agora).
- O índice `uq_man_dem_usu` não considera o status, então par excluído não é recriado (D74). Sem
  caminho de usuário que o alcance, e o conserto está escrito.

## Lembrar

- **Rode a suíte de ponta a ponta ANTES de recarregar o banco**, nunca depois: ela cria demanda,
  manifestação e denúncia de verdade, pela interface.
- **A senha da administração mora em `e2e/.env.local`**, fora do versionamento, e `e2e/rodar.sh` a
  carrega. Sem o arquivo, os cenários de administração **pulam** em vez de falhar.
- **As 631 contas `@verificacao.local` são rastro do volume local e não vão na entrega.** A carga
  inicial não cria conta nenhuma: quem sobe o banco do zero popula por `criar-admin.php` e
  `semear-candidatos.php`. Não vale apagá-las daqui: a trilha de auditoria é insert-only por
  gatilho e ficaria apontando para contas que deixaram de existir.
- **Não derrube o banco sem necessidade.** O volume guarda 19 candidatos semeados pelo fluxo real,
  que custaram perto de 40 chamadas registradas da API, e documento da massa usado uma vez fica
  consumido para sempre (D15).
- **Antes da demonstração**: recarregar e rodar `semear-candidatos.php`,
  `abrir-visibilidade-demo.php` e `preencher-declarados-demo.php`, nesta ordem.
- `verificar-e1.php` roda de dentro do contêiner com a URL interna: `php scripts/verificar-e1.php
  http://nginx`. Com `APP_URL` ele tenta `localhost:8080`, que lá dentro não existe.
- `ParametroRepository` faz cache estático por processo: mudar `sis_parametros` e chamar o serviço
  na mesma execução lê o valor antigo.
- `git pull` que toque `docker/nginx/default.conf` exige `up -d --force-recreate nginx`.
- `verificar-api.php` consome chamadas registradas: nunca rodar em laço.
