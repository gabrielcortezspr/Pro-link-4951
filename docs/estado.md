# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

08/09/2026 — Gabriel, com Claude Code. Commits `dc21111`, `3160083`, `6888398`, `2373e41`,
`73fe87e`.

## Onde parou

E1 concluída. Na E2, prontos o transporte injetável do `CreaApiClient` e o `PortfolioService`
inteiro — importação do acervo, associação de ART à mão, mescla que não apaga campo preenchido e
Selo ART cobrindo as atividades TOS (D13 a D19). Quatro critérios de pronto, todos repetíveis:
103 testes offline, `verificar-e2.php` (28, sem gastar API), `verificar-api.php` (38, contra a
API oficial) e `verificar-e1.php http://nginx` (74).

## Próximo passo

Cadastro de Profissional consultando a API, em `src/Service/AutenticacaoService.php`: chamar
`profissionalPorCpf`, gravar `pro_profissionais` e as modalidades, e então
`PortfolioService::importarArts`, que já existe e já está verificado. CPF ausente na API vira
Terceiro PF, com aviso. Fecha o cenário 1.

## Decisões pendentes

- MER (`_arq/mer/`): MySQL Workbench ou ferramenta de linha de comando? Obrigatório na entrega
  (edital 8.3.2b).
- Liberar `desafio-prolink.crea-am.org.br` na rede do ambiente da nuvem, ou desenvolver contra
  fixtures e validar só na máquina do Gabriel?

## Lembrar

- **Nenhum e-mail sai sozinho**: `despachar()` existe mas nada o chama. Esvaziar a fila à mão e
  conferir no Mailpit (`:8025`). O gatilho é item da E5 no backlog.
- `sis_termos` tem texto de espaço reservado. D03 e D05 precisam estar na Política de Privacidade
  antes da entrega, não só no código.
- Bootstrap vem de CDN; baixar para `public/assets/` antes da entrega.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
