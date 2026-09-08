# Estado do projeto

## Última sessão

08/09/2026 — Gabriel, com Claude Code. Commits `27b05d8`, `d85318b`, `bbd38e1`.

## Onde parou

E1 concluída (RF01) e a **E2 começada pelo transporte**: `Support\Transporte` com implementação
cURL e implementação de fixtures (D08, D13, D14). O cliente da API foi verificado contra a API
oficial de ponta a ponta — `scripts/verificar-api.php`, 38 verificações, todas as fixtures
idênticas às capturas de 06/09 (D16). 87 testes offline e 74 verificações HTTP da E1, repetíveis
(D15).

## Próximo passo

`src/Service/PortfolioService.php`, operação atômica 1 da proposta: `associarArt(rnp, numero)` →
`validarArt` → `atividadesDaArt` → grava `crea_arts` + `crea_art_atividades` + `art_hash` numa
transação só. O cliente já entrega tudo isso; o que não existe ainda é o repositório de gravação.
Escrever contra `TransporteFixture`, com `Crypto::selo` para o HMAC da linha.

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
