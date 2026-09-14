# Telas de referência

Os sete grupos de telas desenhados na fase de design, HTML estático com CSS próprio inline. São
**referência visual**, não a aplicação: não usam Bootstrap, não são servidos e não entram no
`public/`. A implementação real mora nos templates Twig, que reconstroem este visual sobre o
Bootstrap 5 conforme o `docs/design.md`.

| Arquivo | Cobre |
|---|---|
| `prolink-landing.html` | página pública inicial |
| `prolink-busca.html` | resultados da busca pública + perfil público do profissional |
| `prolink-profissional.html` | dashboard, portfólio, visibilidade e demandas compatíveis do profissional |
| `prolink-empresa-1.html` | dashboard, publicar demanda e minhas demandas da empresa |
| `prolink-empresa-2.html` | feed de compatíveis, perfil do profissional (visão empresa) e acervo/CAO |
| `prolink-interacoes.html` | manifestação de interesse e registrar denúncia |
| `prolink-admin.html` | painel administrativo (RF06): visão geral, denúncias, auditoria, usuários, integrações |
| `prolink-ds.css` | o design system inline usado por todas as telas acima |

O que cada decisão de cor, selo e layout significa está em `docs/design.md`; o porquê, em
`docs/decisoes.md` (D28 em diante).
