# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

08/09/2026 — Gabriel, com Claude Code. De `dc21111` a `HEAD`, onze commits.

## Onde parou

E1 concluída. Na E2, a metade do profissional está de pé e **clicável**: cliente da API com
transporte injetável, `PortfolioService` com Selo ART, cadastro consultando o CREA, camada de
visibilidade e a tela `/perfil` (D13 a D23). A metade da empresa não começou.

120 testes offline · `verificar-e2.php` (57, sem rede) · `verificar-api.php` (38, contra a API) ·
`verificar-e1.php http://nginx` (74, uma chamada). Todos repetíveis.

## Próximo passo

**Experiência autodeclarada** (`pro_experiencias`): repositório, serviço e formulário dentro da
própria `/perfil`, com estilo visualmente distinto do dado verificado — a proposta promete não
misturar o que a API confirma com o que a pessoa afirma, e é o que falta para o cenário 1.

Depois, na ordem, com a lista completa na E2 do `backlog.md`: perfil público `/perfil/{id}` (onde
a `Visao` passa a filtrar para quem não é o dono, fechando o cenário 1) → cadastro de Empresa →
CATs → herança de acervo pelo CAO → `sincronizar-status.php`.

## Decisões pendentes

- `match.early_career.min_arts` vale `3`, número escolhido por nós. Decidir antes das telas de
  feed e busca ativa — o porquê e os números estão na E4 do `backlog.md`.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita? O caso pendente está na E2 do `backlog.md`.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b).
- Liberar `desafio-prolink.crea-am.org.br` na rede da nuvem, ou seguir contra fixtures?

## Lembrar

- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Gatilho é item da E5.
- `estrutura.sql` ganhou `uq_prf_usu` (D20): `ALTER TABLE pro_profissionais ADD CONSTRAINT
  uq_prf_usu UNIQUE (prf_usu_id)` — já aplicado aqui, falta nas outras máquinas.
- Documento da massa usado uma vez fica consumido para sempre (D15), inclusive por conta
  excluída. Para demonstrar, use um CPF livre do CSV — `...109`, `...290`, `...370`, `...451` já
  foram.
- `ATTR_EMULATE_PREPARES` está desligado: placeholder nomeado **não** pode repetir na mesma query.
  Já mordeu duas vezes.
- Conta de demonstração pronta: `pedro.alves@prolink.local` / `ProLinkDemo2026!` — profissional
  com 2 ARTs reais, selo conferindo e perfil aberto.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
