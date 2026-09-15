# Estado do projeto

Sobrescrito a cada fechamento de sessão (`/encerrar`). Nunca acrescentado, histórico é `git log`.
Máximo de ~30 linhas: se passar disso, algo aqui deveria estar num commit ou num doc.

## Última sessão

14/09/2026, madrugada. Camila: integração das duas frentes, E6 fechada e o motor da E4.

## Onde parou

Tudo integrado na `main`: design system, pipeline de padrão visual, E3 e experiência declarada
(Gabriel), E6 completa e o **motor da E4** com a operação atômica 2 e semente auditável.
A base foi povoada pelo fluxo real: 19 candidatos, 115 linhas de evidência, 41 ARTs.
Verificado: 176 testes · E2 138 · E4 30 · E6 19 · 24 telas · padrão visual sem violação.

## Próximo passo

**O feed do demandante**, em `templates/` e num controller novo: é a saída da E4 e o cenário 3.
O pool já sai de `CompatibilizacaoService::executar()` embaralhado e com `criterios` por candidato
(score por dimensão, o que saiu da média, e as ARTs que sustentam cada código). Mockup aprovado em
`docs/mockups/prolink-feed-imersivo-v3.html`, e tela nova passa pelo agente `designer-ui`.

Depois: explicação no card, `/admin/sessoes/{id}`, e a E5.

## Decisões pendentes

- `prf_em_construcao` é derivado guardado em coluna e já causou um defeito: derivar na leitura
  (como a D01) ou ponto único de escrita?
- Nenhuma tela mostra `perfil.campos` (e-mail, telefone, resumo). A resposta natural é junto com
  `/perfil/{id}`.
- MER (`_arq/mer/`) e a declaração de uso de IA (12.3) seguem **sem dono**, e os dois são
  obrigatórios na entrega.

## Lembrar

- **Antes da demonstração: recarregar o banco e rodar `semear-candidatos.php`.** Resolve de uma
  vez a massa de teste que suja a trilha e o feed, e o histórico de auditoria anterior a 14/09,
  que está 4h adiantado. `sis_auditoria` bloqueia UPDATE e DELETE por trigger.
- **Escreva as demandas do roteiro olhando o índice**, nunca antes: os vínculos ART para TOS da
  massa são aleatórios, e demanda escolhida por tema não encontra ninguém.
- `verificar-e1.php` acusa uma falha quando a API está acessível. Acoplamento do teste, não
  regressão: o script gera CNPJ sintético e a D26 rebaixa para Terceiro PJ.
- Nenhum e-mail sai sozinho: `despachar()` existe e nada o chama. Gatilho é item da E5.
- Contas locais: `camila@prolink.local` (admin) e as 17 semeadas, todas com `ProLinkDemo2026!`.
- Se a sessão abrir sem o bloco "Retomada": rode `/hooks` uma vez ou reinicie o Claude Code.
