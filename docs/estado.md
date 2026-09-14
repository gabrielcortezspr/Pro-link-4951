# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

14/09/2026 — Gabriel, com Claude Code. A metade da empresa da E2, e a revisão que veio depois.

## Onde parou

E1 concluída. Na E2, as **duas metades** do perfil estão de pé e clicáveis. A da empresa é nova:
identidade, quadro técnico e acervo pelo CAO, cada ART sob o RNP de quem a registrou (D24, D25,
D26). A herança da D19 deixou de ser só SQL — encerrar o vínculo zera a evidência da empresa e não
toca na da pessoa. A revisão de segurança do fim da sessão achou e corrigiu duas falhas na tela de
visibilidade, herdadas da D22 (D27).

140 testes offline · `verificar-e2.php` (108, sem rede) · `verificar-e1.php` (74, por HTTP) ·
`verificar-api.php` (38, contra a API — **não foi rodado nesta sessão**, não há token aqui).

## Próximo passo

**Experiência autodeclarada** (`pro_experiencias`): comece pelo `ExperienciaRepository`, depois o
serviço, depois o formulário dentro da própria `/perfil`, com estilo visualmente distinto do dado
verificado (`.dado-declarado` vs `.selo-art`). É o que falta para o cenário 1.

Depois, na ordem: perfil público `/perfil/{id}` → CATs → `sincronizar-status.php`.

## Decisões pendentes

- `match.early_career.min_arts` vale `3`, número escolhido por nós. Decidir antes das telas de
  feed e busca ativa — o porquê e os números estão na E4 do `backlog.md`.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita? O caso pendente está na E2 do `backlog.md`.
- Nenhuma tela mostra `perfil.campos` (e-mail, telefone, resumo), nos dois perfis. O filtro já
  funciona; falta decidir onde aparecem, junto com `/perfil/{id}`.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b).
- Liberar `desafio-prolink.crea-am.org.br` na rede da nuvem, ou seguir contra fixtures?

## Lembrar

- `estrutura.sql` ganhou três mudanças que faltam nas outras máquinas — aplique nesta ordem:
  `ALTER TABLE pro_profissionais ADD CONSTRAINT uq_prf_usu UNIQUE (prf_usu_id)` (D20),
  `ALTER TABLE pro_empresas ADD CONSTRAINT uq_emp_usu UNIQUE (emp_usu_id)` (D24) e
  `ALTER TABLE crea_quadro_tecnico ADD COLUMN qut_pro_nome VARCHAR(150) NULL AFTER qut_pro_rnp`.
- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Gatilho é item da E5.
- `PrivacidadeService::exportar` leva conta, consentimentos, sessões e auditoria — e nada de
  perfil nem de acervo. Com as duas metades de pé, a exportação do 11.3 ficou incompleta.
- **Relógios diferentes**: `*_dt_consulta` vem do `date()` do PHP (Manaus) e `*_dt_sincronizacao`
  do `NOW()` do MariaDB (UTC). Dá 4h de diferença em "Consultado em". Nada compara os dois hoje;
  o `sincronizar-status.php` vai comparar. Decidir antes dele.
- Documento da massa usado uma vez fica consumido para sempre (D15). CPFs livres: `...290`,
  `...370`, `...451`. Do lado da empresa só as 15 da `massa-de-dados.md` passam no DV, e a que
  tem CAO capturado é a AMAZÔNIA (`00123001000123`, registro 61859).
- `ATTR_EMULATE_PREPARES` está desligado: placeholder nomeado **não** pode repetir na mesma query.
- Conta de demonstração: `pedro.alves@prolink.local` / `ProLinkDemo2026!`. Existe só na máquina do
  Gabriel — banco novo não a tem.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
