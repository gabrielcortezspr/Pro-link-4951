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

- **`estrutura.sql` mudou** (D10): recrie o banco com `docker compose down -v && up -d`.
- Capture local uma fixture que pagine de verdade — `?p=profissionais/{rnp}/arts&limit=2`, duas
  páginas — senão o laço de paginação da E2 fica sem cobertura.
- `sis_termos` tem texto de espaço reservado. As decisões D03 e D05 precisam estar na Política de
  Privacidade antes da entrega, não só no código.
- Bootstrap vem de CDN; baixar para `public/assets/` antes da entrega.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
