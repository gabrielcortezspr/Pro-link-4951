# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

08/09/2026 — Gabriel, com Claude Code. De `dc21111` a `HEAD`.

## Onde parou

E1 concluída. Na E2: cliente da API, `PortfolioService`, cadastro de Profissional consultando o
CREA, camada de visibilidade e a **tela `/perfil`** (D13 a D22). A metade do profissional está de
pé e clicável; a da empresa não começou.

120 testes offline · `verificar-e2.php` (57, sem rede) · `verificar-api.php` (38, contra a API) ·
`verificar-e1.php http://nginx` (74, uma chamada). Todos repetíveis.

## Próximo passo

**Experiência autodeclarada** (`pro_experiencias`): repositório, serviço e formulário na própria
`/perfil`, com estilo visualmente distinto do dado verificado — é a promessa da proposta de não
misturar o que a API confirma com o que a pessoa afirma.

Depois, o perfil público `/perfil/{id}`, que é onde a `Visao` passa a filtrar para um espectador
que não é o dono, e aí o cenário 1 fecha.

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
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
