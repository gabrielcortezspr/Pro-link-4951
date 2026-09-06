---
name: encerrar
description: Rotina de fechamento de sessão do Pro-Link — reescreve docs/estado.md com onde parou e o próximo passo, confere o que ficou sem commit e propõe a mensagem. Use quando o usuário disser /encerrar, "vou parar por aqui", "fechar a sessão" ou "salvar onde paramos".
---

# Encerrar sessão

Objetivo: a próxima sessão — sua ou da outra pessoa da equipe — começar sabendo exatamente onde
esta parou. O hook `SessionStart` imprime `docs/estado.md` automaticamente na abertura, então o
que você escrever aqui é a primeira coisa que o próximo agente vai ler.

## Passos

1. **Resuma a sessão em duas ou três linhas** para o usuário: o que foi feito, o que foi
   verificado, o que ficou pela metade. Sem lista de arquivos — isso o `git log` conta.

2. **Reescreva `docs/estado.md` inteiro** (nunca acrescente), mantendo as cinco seções fixas:
   - `Última sessão` — data e quem.
   - `Onde parou` — estado factual, no máximo quatro linhas.
   - `Próximo passo` — uma ação concreta, com o arquivo por onde começar. Não uma lista de
     desejos: a *primeira* coisa a fazer.
   - `Decisões pendentes` — só o que bloqueia ou muda o rumo. Resolvido sai.
   - `Lembrar` — armadilhas operacionais de curto prazo. Regra permanente vai para `CLAUDE.md`.

   Se o arquivo passar de ~30 linhas, algo nele pertence a um commit, a `CLAUDE.md` ou a `docs/`.

3. **Confira o `git status`.** Se houver mudança sem commit, liste em uma linha o que é e
   proponha uma mensagem de commit. **Não commite sem o usuário pedir.** Se o working tree
   estiver limpo, diga isso.

4. **Feche com uma linha**: o próximo passo, e como retomar (`claude` no diretório — o hook faz
   o resto).

## O que não fazer

- Não duplicar no `estado.md` o que está no `git log` ou em `CLAUDE.md`.
- Não reescrever `CLAUDE.md` por reflexo. Só se uma *regra permanente* mudou nesta sessão.
- Não deixar o `estado.md` descrever o passado em detalhe. Ele aponta para frente.
