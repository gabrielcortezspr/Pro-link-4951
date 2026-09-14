# Design system

O visual do Pro-Link. Uma fonte só para tokens, componentes e regras de cor — se um valor de cor,
raio ou fonte precisar ser decidido, é aqui. O *porquê* de cada escolha está em `docs/decisoes.md`
(D28 em diante); as telas que o originaram, em `docs/mockups/`.

## Relação com o Bootstrap

O edital exige Bootstrap 5 (Anexo I, tecnologias de front) e ele fica. É a base: grid, utilitários,
JS de componentes (modal, collapse, dropdown), macros de formulário. **Nosso design é um tema por
cima**, não um substituto — CSS3 também é exigência do edital, então tema próprio está em
conformidade. O que o tema faz:

- Sobrescreve as variáveis do Bootstrap (`--bs-*`: primária, fundo, fonte, raio) para a paleta e a
  tipografia daqui, de modo que a marcação Bootstrap existente já adote a identidade.
- Adiciona os componentes que o Bootstrap não tem, todos com **prefixo `pl-`** para nunca colidir
  com as classes do framework (`.btn`, `.card`, `.nav`, `.pill` e `table` são do Bootstrap — os
  nossos são `.pl-app`, `.pl-card`, `.pl-seal`, etc.).

As três classes que já existiam (`.selo-art`, `.dado-declarado`, `.perfil-em-construcao`) mantêm o
nome e ganham a aparência nova, para os templates atuais não quebrarem.

## Tokens

```
Neutros (fundo quente off-white, não cinza-azulado frio)
  --pl-page   #F2EEE7   fundo atrás dos quadros
  --pl-app    #FBFAF6   fundo da área de conteúdo
  --pl-card   #FFFFFF   cards (sempre branco, explícito — background não é herdado)
  --pl-border #EDE8DE   borda   ·   --pl-border-2 #F7F3EC  borda sutil
  --pl-ink    #0E1726   texto   ·   --pl-muted #5B6B82  ·  --pl-faint #8A9BB4

Marca / estrutura
  --pl-navy   #0B2A4A → #12315A   sidebar
  --pl-blue   #2E6BE6   link, marca, acento   ·   --pl-blue-2 #63A0F0

Papéis de cor (cada um é exclusivo)
  --pl-seal   #0E7C86   VERDE ÁGUA — só selo de verificação, nada mais
  --pl-data   #1B4079   AZUL ESCURO — barras de dado (aderência) e botão secundário (outline)
  --pl-accent #F26B21   LARANJA — só o CTA que compromete (uma única cor de ação preenchida)

Semânticas (status)
  ok #1FA971 · aviso #E8A317 · perigo #E24A4A

Tipografia
  display "Space Grotesk"  (títulos)   ·   corpo "Inter"   — via Google Fonts
Raio base 12px.
```

## Regra de cor

Proporção 60/30/10: neutro domina, azul/navy estrutura, laranja pontua a ação. Cada cor tem um
papel único e não invade outro:

- **Laranja** = a única ação preenchida (Publicar, Manifestar interesse). Nada mais é laranja.
- **Azul escuro** = botão secundário (contornado, "Ver detalhes") e barras de dado. Nunca preenchido concorrendo com o laranja.
- **Azul brilhante** = link textual e marca.
- **Verde água** = exclusivamente o selo de verificação.
- **Amarelo não existe** como sinal de "autodeclarado" — ver o selo abaixo.

## Verificação: o compromisso central, em símbolo

Dado verificado pela API nunca se confunde com dado informado pelo próprio usuário. A distinção é
um **símbolo inline, à direita do texto** que qualifica — nunca uma tarja de texto longo, nunca uma
coluna separada.

- **Verificado** = selo chanfrado (pontudo) verde água com um "check". Só acompanha texto curto de
  ART/CAT/TOS/perfil verificado.
- **Não verificado pela API** = mesmo tamanho, mas **círculo liso** (sem pontas) cinza com um "X".

Nada de rótulo "ART verificada" em tarja; nada de amarelo. Em tabela, o selo cola no identificador
(o nº da ART) e não há coluna de "verificada".

## Componentes (prefixo `pl-`)

- **`pl-app`** — shell das páginas logadas: sidebar navy colapsável + topbar + conteúdo. As páginas
  públicas (landing, perfil público, busca) usam a top-nav do `base.html.twig`.
- **`pl-card`** — quadro branco (fundo explícito), borda `--pl-border`, raio 16px, sombra leve.
- **`pl-seal` / `pl-mark`** — o símbolo de verificação (verde água) e o de não-verificado (círculo cinza com X).
- **`pl-pill`** — status, vocabulário FIXO e cor por estado (Aberta=verde, Encerrada=cinza, etc.), nunca ad-hoc.
- **`pl-bar` / `pl-dim`** — barras de aderência por dimensão (azul escuro). Nunca nota única nem
  ranking (item 10.1) — cada dimensão é uma barra, a ordem do pool vem da semente da sessão.
- **`pl-kpi`** — tile de indicador (dashboard).
- **`pl-tos`** — código TOS legível: "TOS 1.1.1.1" com o nome ao lado, sem underline.
- **Tabelas** — uma ação por linha ("Ver"); demais ações no detalhe, atrás de um menu de três pontos.
- **Campo obrigatório** — asterisco vermelho no rótulo + estado de erro (borda vermelha + texto),
  nunca "(obrigatório)" no rótulo.

## Convenções de texto

Sem emoji na UI (ícone SVG monocromático). Sem travessão (—) na cópia visível. Largura total, nada
de coluna estreita centralizada com laterais vazias.
