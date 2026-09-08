# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado — histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

08/09/2026 — Gabriel, com Claude Code. Commits `dc21111`, `3160083`, `6888398`, `2373e41`,
`73fe87e`.

## Onde parou

E1 concluída. Na E2, prontos o transporte injetável, o `PortfolioService` e o **cadastro de
Profissional consultando a API** (`PerfilCreaService`, D13 a D20). Conferido pelo formulário
contra a API real: PEDRO HENRIQUE ALVES entrou com RNP, modalidade, 2 ARTs seladas e
`prf_em_construcao = 1`.

Quatro critérios repetíveis: 103 testes offline, `verificar-e2.php` (52, sem rede),
`verificar-api.php` (38, contra a API) e `verificar-e1.php http://nginx` (74, uma chamada).

## Próximo passo

Tela de "validar meu registro", que fecha a pendência criada pela D20: usuário com perfil
PROFISSIONAL e sem linha em `pro_profissionais` — API fora do ar no cadastro — precisa de um botão
chamando `PerfilCreaService::vincularProfissional($usuarioId)`, sem CPF, que ele decifra sozinho.
Pouca coisa, e completa o desfecho que hoje só tem metade. Depois: cadastro de Empresa.

## Decisões pendentes

- `match.early_career.min_arts` vale `3`, número escolhido por nós. Decidir antes das telas de
  feed e busca ativa — o porquê e os números estão na E4 do `backlog.md`.
- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita? O caso pendente está na E2 do `backlog.md`.
- MER (`_arq/mer/`): Workbench ou linha de comando? Obrigatório na entrega (8.3.2b).
- Liberar `desafio-prolink.crea-am.org.br` na rede da nuvem, ou seguir contra fixtures?

## Lembrar

- **Nenhum e-mail sai sozinho**: `despachar()` existe e nada o chama. Esvaziar a fila à mão,
  conferir no Mailpit (`:8025`). O gatilho é item da E5.
- `estrutura.sql` ganhou `uq_prf_usu` (D20): `ALTER TABLE pro_profissionais ADD CONSTRAINT
  uq_prf_usu UNIQUE (prf_usu_id)` — já aplicado aqui, falta nas outras máquinas.
- Documento da massa usado uma vez fica consumido para sempre (D15), inclusive por conta
  excluída. Para demonstrar, use um CPF livre do CSV — `...109`, `...290`, `...370` e `...451` já
  foram.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
