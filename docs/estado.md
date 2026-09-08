# Estado do projeto

## Última sessão

08/09/2026 — Gabriel, com Claude Code. Commits `27b05d8`, `d85318b`, `bbd38e1`.

## Onde parou

E1 concluída (RF01). Na E2: transporte injetável (D08, D13, D14, D16) e **`PortfolioService`
pronto e verificado** — importação do acervo, associação de ART à mão, mescla que não apaga campo
preenchido e Selo ART cobrindo as atividades TOS (D17, D18).

103 testes offline · 28 conferências da E2 contra o banco (`verificar-e2.php`, sem gastar API) ·
38 contra a API real (`verificar-api.php`) · 74 da E1 por HTTP. Os três scripts são repetíveis.

## Próximo passo

Cadastro de Profissional consultando a API: `AutenticacaoService::cadastrar` chama
`profissionalPorCpf`, grava `pro_profissionais` (`prf_rnp`, `prf_registro_crea`, `prf_nome_api`,
`prf_status_api`) e as modalidades, e aí chama `PortfolioService::importarArts` — que já existe e
já está verificado. CPF que não está na API vira Terceiro PF, com aviso. Depois disso o cenário 1
tem começo, meio e fim.

A herança de acervo pelo CAO já está destravada: a regra é binária (D19), a view `crea_evidencias`
já a implementa e os documentos que prometiam recorte temporal foram corrigidos.

## Decisões pendentes


- MER (`_arq/mer/`): gerar do MySQL Workbench ou de ferramenta de linha de comando? Obrigatório
  na entrega (edital 8.3.2b).
- Liberar `desafio-prolink.crea-am.org.br` na política de rede do ambiente da nuvem, ou manter o
  desenvolvimento contra fixtures e validar só na máquina do Gabriel?

## Lembrar

- Banco local já alinhado ao `estrutura.sql` (D10 aplicada por `ALTER` em 08/09, sem perder o
  admin de desenvolvimento). Clone novo cria o schema certo direto do arquivo.
- **A fila de notificação não tem gatilho.** `enfileirar()` grava em `sis_notificacoes` dentro da
  transação, mas ninguém chama `despachar()` — os e-mails ficam parados. Esvaziar à mão com
  `docker compose exec php php -r 'require "/var/www/html/_config.php"; var_export((new ProLink\Service\NotificacaoService())->despachar());'`
  e conferir no Mailpit (`:8025`). O gatilho e o teto de tentativas (hoje `pendentes()` retenta
  falha para sempre) ficam para a E5, que é quando a RF07 entra em cenário de demonstração.
- `verificar-e1.php` rodado dentro do container precisa da URL do nginx:
  `docker compose exec php php scripts/verificar-e1.php http://nginx`. O padrão `APP_URL` é o
  endereço visto do host.
- Clicar as telas da E2 sem rede exige montar o cliente com `TransporteFixture` na mão: não há
  interruptor no `.env`, e a D13 explica por quê.
- `sis_termos` tem texto de espaço reservado. As decisões D03 e D05 precisam estar na Política de
  Privacidade antes da entrega, não só no código.
- Bootstrap vem de CDN; baixar para `public/assets/` antes da entrega.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
