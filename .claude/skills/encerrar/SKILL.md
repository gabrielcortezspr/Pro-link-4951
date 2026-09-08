---
name: encerrar
description: Rotina de fechamento de sessão do Pro-Link — registra as decisões da sessão em docs/decisoes.md, atualiza o estado da etapa no backlog, reescreve docs/estado.md com onde parou e propõe a mensagem de commit. Use quando o usuário disser /encerrar, "vou parar por aqui", "fechar a sessão" ou "salvar onde paramos".
---

# Encerrar sessão

Objetivo: a próxima sessão — sua ou da outra pessoa da equipe — começar sabendo exatamente onde
esta parou. O hook `SessionStart` imprime `docs/estado.md` automaticamente na abertura, então o
que você escrever aqui é a primeira coisa que o próximo agente vai ler.

## Passos

1. **Resuma a sessão em duas ou três linhas** para o usuário: o que foi feito, o que foi
   verificado, o que ficou pela metade. Sem lista de arquivos — isso o `git log` conta.

2. **Registre as decisões da sessão em `docs/decisoes.md`.** Uma entrada por decisão que tinha
   **alternativa real** — escolha de desenho, interpretação de requisito ambíguo, armadilha da
   massa que mudou o rumo. Se não havia escolha, não é decisão: é exigência do edital, e mora em
   `edital-requisitos.md`.

   Numeração sequencial e permanente (`D09`, `D10`…), os quatro campos fixos (Contexto, Decisão,
   Alternativa recusada, Consequência) e a linha de referência com data, etapa, commit e o arquivo
   onde a regra vive. Atualize o índice por etapa no topo.

   **O arquivo só cresce.** Decisão que foi revista ganha entrada nova citando a antiga — nunca
   edite nem apague a original. O campo que mais importa é *Alternativa recusada*: é a pergunta
   que a banca faz.

   Na dúvida sobre se algo merece entrada, o teste é: "se alguém mexer nisso em três dias sem
   saber o porquê, quebra algo?" Se sim, registre.

3. **Atualize o estado da etapa em `docs/backlog.md`.** Uma linha de citação sob o título da etapa:
   concluída com data e commits, ou em andamento com o que já entrou. Não reescreva o plano da
   etapa — ele é o que foi combinado, e a diferença entre plano e execução é informação.

4. **Reescreva `docs/estado.md` inteiro** (nunca acrescente), mantendo as cinco seções fixas:
   - `Última sessão` — data e quem.
   - `Onde parou` — estado factual, no máximo quatro linhas.
   - `Próximo passo` — uma ação concreta, com o arquivo por onde começar. Não uma lista de
     desejos: a *primeira* coisa a fazer.
   - `Decisões pendentes` — só o que bloqueia ou muda o rumo. Resolvido sai.
   - `Lembrar` — armadilhas operacionais de curto prazo. Regra permanente vai para `CLAUDE.md`.

   Se o arquivo passar de ~30 linhas, algo nele pertence a um commit, a `CLAUDE.md` ou a `docs/`.

5. **Confira o `git status`.** Se houver mudança sem commit, liste em uma linha o que é e
   proponha uma mensagem de commit. **Não commite sem o usuário pedir.** Se o working tree
   estiver limpo, diga isso.

6. **Feche com uma linha**: o próximo passo, e como retomar (`claude` no diretório — o hook faz
   o resto).

## O que não fazer

- Não duplicar no `estado.md` o que está no `git log`, em `CLAUDE.md` ou em `decisoes.md`.
- Não transformar `decisoes.md` em changelog. Lista de arquivos alterados é trabalho do `git log`;
  ali vai só o raciocínio. Sessão sem nenhuma bifurcação real não gera entrada, e está tudo bem.
- Não reescrever entrada antiga de `decisoes.md`, nem para corrigir o português.
- Não reescrever `CLAUDE.md` por reflexo. Só se uma *regra permanente* mudou nesta sessão.
- Não deixar o `estado.md` descrever o passado em detalhe. Ele aponta para frente.
