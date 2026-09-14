# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

14/09/2026 — Gabriel, com Claude Code. A metade da empresa da E2, de `4016e60` a `HEAD`.

## Onde parou

E1 concluída. Na E2, as **duas metades** estão de pé e clicáveis. A do profissional como estava
(D13 a D23). A da empresa é nova: `EmpresaCreaService` com os três desfechos no vocabulário comum
de `Support\DesfechoCrea`, quadro técnico do endpoint próprio (D25), acervo pelo CAO gravado sob o
RNP de quem registrou (D24), tela `/perfil` e portão de visibilidade próprios (D26). A herança da
D19 deixou de ser só SQL: encerrar o vínculo zera a evidência da empresa e não toca na da pessoa.

134 testes offline · `verificar-e2.php` (104, sem rede) · `verificar-api.php` (38, contra a API) ·
`verificar-e1.php http://nginx` (74, uma chamada). Todos repetíveis e conferidos nesta sessão.

## Próximo passo

**Experiência autodeclarada** (`pro_experiencias`): repositório, serviço e formulário dentro da
própria `/perfil`, com estilo visualmente distinto do dado verificado — a proposta promete não
misturar o que a API confirma com o que a pessoa afirma, e é o que falta para o cenário 1.

Depois, na ordem: perfil público `/perfil/{id}` (onde a `Visao` passa a filtrar para quem não é o
dono, fechando o cenário 1) → CATs → `sincronizar-status.php`.

## Decisões pendentes

- `match.early_career.min_arts` vale `3`, número escolhido por nós. Decidir antes das telas de
  feed e busca ativa — o porquê e os números estão na E4 do `backlog.md`.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita? O caso pendente está na E2 do `backlog.md`.
- Nenhuma tela mostra `perfil.campos` (e-mail, telefone, resumo) — nem a do profissional, nem a
  da empresa. O filtro já funciona; falta decidir onde eles aparecem, junto com `/perfil/{id}`.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b).
- Liberar `desafio-prolink.crea-am.org.br` na rede da nuvem, ou seguir contra fixtures?

## Lembrar

- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Gatilho é item da E5.
- `PrivacidadeService::exportar` leva conta, consentimentos, sessões e auditoria — e nada de
  `pro_profissionais`, `pro_empresas` nem acervo. Era pouco relevante na E1; com as duas
  metades do perfil de pé, a exportação do 11.3 está incompleta para os dois lados.
- `estrutura.sql` ganhou três mudanças que faltam nas outras máquinas — aplique nesta ordem:
  `ALTER TABLE pro_profissionais ADD CONSTRAINT uq_prf_usu UNIQUE (prf_usu_id)` (D20),
  `ALTER TABLE pro_empresas ADD CONSTRAINT uq_emp_usu UNIQUE (emp_usu_id)` (D24) e
  `ALTER TABLE crea_quadro_tecnico ADD COLUMN qut_pro_nome VARCHAR(150) NULL AFTER qut_pro_rnp`.
- **Relógios diferentes, e ninguém decidiu isso**: `*_dt_consulta` vem de `date()` do PHP
  (America/Manaus) e `*_dt_sincronizacao` vem de `NOW()` do MariaDB (UTC no container). Dá 4h de
  diferença na tela, em "Consultado em". Não quebra nada hoje porque nada compara os dois, mas
  vai quebrar quando o `sincronizar-status.php` comparar. Decidir antes dele.
- Documento da massa usado uma vez fica consumido para sempre (D15), inclusive por conta
  excluída. Para demonstrar, use um CPF livre do CSV — `...109`, `...290`, `...370`, `...451` já
  foram. Do lado da empresa, só as 15 da `massa-de-dados.md` passam no DV, e a que tem CAO
  capturado é a AMAZÔNIA (`00123001000123`, registro 61859).
- `ATTR_EMULATE_PREPARES` está desligado: placeholder nomeado **não** pode repetir na mesma query.
  Já mordeu duas vezes.
- Conta de demonstração pronta: `pedro.alves@prolink.local` / `ProLinkDemo2026!` — profissional
  com 2 ARTs reais, selo conferindo e perfil aberto.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
