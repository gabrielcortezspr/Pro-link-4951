# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

14/09/2026 — Gabriel, com Claude Code. Empresa, experiência declarada e dois defeitos achados.

## Onde parou

E2 quase fechada. O perfil tem as duas metades (profissional e empresa) e agora tem também a
**experiência autodeclarada**, ao lado — nunca dentro — do acervo verificado (D29). Conferir a
posse da ART vinculada expôs dois defeitos, os dois corrigidos: o `<select>` mandava o `art_id`
errado por causa do `merge` do Twig, e `pro_visibilidade` duplicava linha a cada clique, fazendo a
tela de privacidade **descartar alterações em silêncio** (D28).

145 testes offline · `verificar-e2.php` (138, sem rede) · `verificar-e1.php` (74, por HTTP) ·
`verificar-api.php` (38, contra a API — **não rodou nesta sessão**, não há token aqui).

## Próximo passo

**Perfil público `/perfil/{id}`**: rota pública em `public/index.php`, método novo no
`PerfilController` passando `Sessao::usuarioId()` como espectador (pode ser null) e despachando
pelo perfil do **dono**, não pelo de quem olha. `PerfilService` e `PerfilEmpresaService` já
aceitam espectador diferente do dono e já filtram — é onde a `Visao` finalmente trabalha de
verdade. Fecha o cenário 1.

Depois: CATs → `sincronizar-status.php`.

## Decisões pendentes

- `match.early_career.min_arts` vale `3`, número escolhido por nós. Decidir antes das telas de
  feed e busca ativa — o porquê e os números estão na E4 do `backlog.md`.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita? O caso pendente está na E2 do `backlog.md`.
- Nenhuma tela mostra `perfil.campos` (e-mail, telefone, resumo). O filtro já funciona; falta
  decidir onde aparecem — a resposta natural é junto com `/perfil/{id}`.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b).

## Lembrar

- **Rode isto nas outras máquinas**, nesta ordem — os três primeiros são de sessões anteriores:
  ```sql
  ALTER TABLE pro_profissionais ADD CONSTRAINT uq_prf_usu UNIQUE (prf_usu_id);
  ALTER TABLE pro_empresas ADD CONSTRAINT uq_emp_usu UNIQUE (emp_usu_id);
  ALTER TABLE crea_quadro_tecnico ADD COLUMN qut_pro_nome VARCHAR(150) NULL AFTER qut_pro_rnp;
  -- D28: tira as duplicatas que o defeito gravou, mantendo a escolha mais recente
  DELETE v FROM pro_visibilidade v JOIN pro_visibilidade novo
    ON novo.vis_usu_id = v.vis_usu_id AND novo.vis_entidade = v.vis_entidade
   AND novo.vis_entidade_id <=> v.vis_entidade_id AND novo.vis_campo <=> v.vis_campo
   AND novo.vis_id > v.vis_id;
  ```
- **`ON DUPLICATE KEY UPDATE` só é confiável se nenhuma coluna do índice aceitar nulo.** Já custou
  duas vezes (D25, D28). Conferir antes de usar, sempre.
- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Gatilho é item da E5.
- `PrivacidadeService::exportar` não leva perfil, acervo nem experiências. A exportação do 11.3
  está incompleta.
- **Relógios diferentes**: `*_dt_consulta` vem do `date()` do PHP (Manaus), `*_dt_sincronizacao`
  do `NOW()` do MariaDB (UTC). 4h de diferença na tela. O `sincronizar-status.php` vai comparar.
- Documento da massa usado uma vez fica consumido para sempre (D15). CPFs livres: `...290`,
  `...370`, `...451`. Empresa: só as 15 da `massa-de-dados.md` passam no DV, e a que tem CAO
  capturado é a AMAZÔNIA (`00123001000123`, registro 61859).
- `ATTR_EMULATE_PREPARES` desligado: placeholder nomeado **não** pode repetir na mesma query.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
