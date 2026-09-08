# Estado do projeto

## Última sessão

08/09/2026 — Gabriel, com Claude Code. Commits `27b05d8`, `d85318b`, `bbd38e1`.

## Onde parou

**E1 concluída** (RF01): cadastro nos quatro tipos, login com bloqueio, recuperação de senha,
painel de privacidade com revogação, portabilidade e exclusão, tudo auditado. Camada
`src/Repository/` criada — SQL só mora lá. 62 testes e 71 verificações HTTP passando.

## Próximo passo

Transporte injetável no `src/Service/CreaApiClient.php` (decisão D08): extrair o cURL do método
privado `obter()` para uma interface, com implementação de produção e outra que lê de `fixtures/`.
Meia hora, e destrava escrever a E2 inteira offline. Só depois começar `PortfolioService`.

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
- **`verificar-e1.php` roda uma vez por volume.** Ele cadastra quatro documentos fixos da massa e
  encerra as contas com `_status = 'X'`, mas `documentoEmUso()` não filtra status, de propósito —
  então a segunda execução falha nos quatro cadastros. Para rodar de novo hoje: `docker compose
  down -v && up -d` e `criar-admin.php`. Corrigir escolhendo documento livre por execução.
- Capture local uma fixture que pagine de verdade — `?p=profissionais/{rnp}/arts&limit=2`, duas
  páginas — senão o laço de paginação da E2 fica sem cobertura.
- `sis_termos` tem texto de espaço reservado. As decisões D03 e D05 precisam estar na Política de
  Privacidade antes da entrega, não só no código.
- Bootstrap vem de CDN; baixar para `public/assets/` antes da entrega.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
