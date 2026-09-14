# Telas de referência

As telas desenhadas na fase de design, HTML estático com CSS próprio inline. São **referência
visual**, não a aplicação: não usam Bootstrap, não são servidas e não entram no `public/`. A
implementação real mora nos templates Twig, que reconstroem este visual sobre o Bootstrap 5
conforme o `docs/design.md`.

## Cobertura por requisito funcional

| Arquivo | Cobre |
|---|---|
| `prolink-landing.html` | página pública inicial |
| `prolink-busca.html` | resultados da busca pública + perfil público do profissional |
| `prolink-profissional.html` | dashboard, portfólio, visibilidade e demandas compatíveis do profissional |
| `prolink-empresa-1.html` | dashboard, publicar demanda e minhas demandas da empresa |
| `prolink-empresa-2.html` | feed de compatíveis, perfil do profissional (visão empresa) e acervo/CAO |
| `prolink-interacoes.html` | manifestação de interesse e registrar denúncia |
| `prolink-admin.html` | painel administrativo (RF06): visão geral, denúncias, auditoria, usuários, integrações |

## Diferenciais

Telas que existem para sustentar um diferencial declarado na proposta, não um requisito mínimo.

| Arquivo | Cobre |
|---|---|
| `prolink-feed-imersivo-v3.html` | o feed passivo de recomendação, um candidato em foco por vez, com a aderência por dimensão no lugar que um streaming daria à nota. Navegação vertical, evidência sob demanda, sem ranking e sem posição (item 10.1) |
| `prolink-early-career.html` | o marcador de perfil em construção nos quatro pontos onde aparece: card do feed, perfil próprio, visão da empresa e filtro da busca. Mitigação de viés declarada no item 12.3 |
| `prolink-estados-criticos.html` | a degradação digna dos fluxos críticos: selo de integridade suspenso (âmbar), registro não validado e acervo não importado (azul-info), mais a ficha da hierarquia de cor dos estados |
| `prolink-admin-sessoes.html` | as sessões do motor no admin: lista e detalhe com replay pela semente, que é a prova visual de que a mesma semente produz sempre a mesma ordem (itens 10.1 e 12.3) |

## Base

| Arquivo | Cobre |
|---|---|
| `prolink-ds.css` | o design system inline usado por todas as telas acima |

O que cada decisão de cor, selo e layout significa está em `docs/design.md`; o porquê, em
`docs/decisoes.md` (D28 em diante).
