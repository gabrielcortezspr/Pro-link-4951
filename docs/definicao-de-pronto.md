# Definição de pronto

O que "pronto" significa neste projeto, em quatro escalas: a micro-etapa, a tela, a etapa do
backlog e a entrega. Existe porque "funciona na minha máquina" já passou duas vezes por pronto
aqui e voltou: a tela de auditoria que saiu em padrão de dump (E6) e as duas falhas de
autorização da D27, ambas aprovadas em revisão de código antes de alguém forjar uma requisição.

A regra que atravessa as quatro escalas: **pronto é o que uma máquina ou um olho humano
conferiu, nunca o que alguém leu e achou bom.** Verde no verificador é o piso, não a aprovação.

O critério de pronto de cada etapa individual (E0 a E7) continua no `backlog.md`, sob
"Pronto quando". Aqui está a régua que vale para qualquer trabalho, em qualquer etapa.

---

## 1. Micro-etapa (a unidade de trabalho de uma sessão)

Nenhuma micro-etapa fecha sem as quatro:

1. **Passa a suíte.** `docker compose exec -T php composer test` verde, sem teste pulado novo.
2. **Tem prova própria.** O comportamento novo é exercitado por um teste que falharia sem ele.
   Regra de negócio pura vai para `tests/`; caminho que atravessa o banco vai para o
   `scripts/verificar-eN.php` da etapa. Script de verificação é critério de pronto executável,
   não relatório (D12).
3. **A verificação toca o que o usuário toca.** Repositório no verde não prova que a página abre.
   Rota nova ou alterada é carregada por HTTP, com sessão do perfil certo, antes de fechar.
4. **Não deixa rastro de si.** Sem `var_dump`, sem rota de depuração, sem dado de teste gravado
   em tabela que a demonstração vai mostrar.

## 2. Tela

Além das quatro acima:

5. **Passou pelo `designer-ui` antes de virar código**, se é tela nova ou mudança de layout,
   hierarquia ou componente. Ligar campo existente ou corrigir rótulo não precisa.
6. **As duas verificações de padrão no verde:**
   ```
   docker compose exec -T php php scripts/verificar-telas.php
   docker compose exec -T php php scripts/verificar-padrao.php
   ```
7. **Entrou em `scripts/amostras.php`.** Tela fora das amostras não é conferida no HTML
   renderizado, e o verificador passa por cima dela sem reclamar.
8. **Os estados fora do caminho feliz estão desenhados**: vazio, erro, filtro sem resultado,
   texto que estoura, lista de 8 e lista de 5.000. Vazio é frase escrita, nunca traço mudo.
9. **Nenhum identificador de sistema visível.** Ação, tabela e coluna passam por
   `Support\Rotulos`. O valor cru pode ficar em `title=""`.
10. **Olho humano.** A régua é *delightful*: se der para olhar um elemento e pensar "dá pra
    passar", não está pronto.

## 3. Segurança e privacidade (quando a mudança toca dado, autorização ou fluxo crítico)

11. **Conferido com requisição forjada, não por leitura.** Todo formulário que aceita
    identificador de volta merece a sonda: alvo que não é do titular, valor fora da lista
    fechada, item inválido no meio de um lote válido.
12. **Autorização por operação, não só por rota.** Perfil certo na rota não basta se o registro
    é de outra pessoa.
13. **CSRF em todo POST**, escape no Twig sem `|raw`, e nenhuma `DELETE` física: `_status = 'X'`.
14. **Auditoria.** Operação que altera dado de outra pessoa, muda parâmetro do motor ou restringe
    acesso grava em `sis_auditoria`, com valor anterior e novo.
15. **Toda query é prepared statement, e só repositório escreve SQL.**

## 4. Etapa do backlog

16. O "Pronto quando" daquela etapa, no `backlog.md`, roda de ponta a ponta pelo navegador, não
    só pelo script.
17. As decisões com alternativa real foram registradas em `docs/decisoes.md`, com o campo
    *Alternativa recusada* preenchido. É a pergunta que a banca faz.
18. `docs/estado.md` reescrito pelo ritual `/encerrar`, apontando para frente.

## 5. Entrega (17/09/2026, até as 18h)

Esta é a única lista que precisa estar **inteira** no verde. Cada item é verificável por comando
ou por inspeção direta, não por memória.

### Obrigatório pelo edital

| # | Item | Fonte | Como se confere |
|---|---|---|---|
| 1 | `_arq/estrutura.sql` cria banco, tabelas, índices, chaves e carga inicial | 8.3.2 | clone limpo sobe e `/saude` responde |
| 2 | **MER em PDF/PNG** em `_arq/mer/` | 8.3.2b | arquivo existe e abre |
| 3 | README de instalação, configuração, execução e atualização | 8.3.2 | **testado em clone limpo**, não lido |
| 4 | Documentação técnica: arquitetura, diretórios, módulos, integrações, regras | 8.3.2 | `_arq/arquitetura.md` + `_arq/estrutura-diretorios.md` |
| 5 | Dependências com versões e licenças | 8.3.2 · 16.5 | `_arq/dependencias.md` conferido contra `composer.lock` |
| 6 | `_config.php` na raiz centralizando URLs, PATHs, `PATH_IMG`, banco, API, fuso, charset, SMTP | 8.3.1 | leitura do arquivo |
| 7 | `.env` entregue é **apenas modelo**, sem credencial real | 8.3.1 · Anexo VI | `git grep` por segredo antes do push |
| 8 | Exclusão lógica em tudo, com lixeira administrativa | 8.6j | restaurar um registro pela tela |
| 9 | Declaração de uso de IA, vieses e limitações | 12.3 · Anexo VI | seção em `_arq/arquitetura.md` |
| 10 | Termos de Uso e Política de Privacidade com texto real, cobrindo D03 e D05 | 11.3 | `/termos/uso` e `/termos/privacidade` |
| 11 | Ambiente sobe padronizado e automatizado, com banco carregado | 8.8 | `docker compose up -d --build` em clone limpo |
| 12 | Repositório no GitHub com histórico completo, mais `.zip` sem `vendor/`, `.env` e `storage/` | 8.7 | upload na plataforma antes das 18h |

### Os seis cenários (Anexo I, item 7)

Rodam ao vivo, pelo navegador, com os identificadores escritos no roteiro. Ensaiados duas vezes.
São a definição de pronto do MVP e não se cortam.

1. Profissional cria perfil e informa competência ligada a uma experiência.
2. Empresa publica demanda com escopo, localização e requisitos.
3. Sistema apresenta correspondências e explica os principais critérios.
4. Profissional manifesta interesse e a empresa visualiza o perfil.
5. Usuário corrige ou restringe dados e registra denúncia.
6. Administrador visualiza trilha de auditoria e atua na moderação.

### Autoavaliação do Anexo VI

Os doze itens da triagem da banca. Marcar só o que foi conferido de fato:

- [ ] Executa as funcionalidades essenciais
- [ ] Utiliza apenas dados sintéticos, anonimizados ou autorizados
- [ ] Possui instruções reproduzíveis de instalação e execução
- [ ] Identifica dependências, licenças e componentes de terceiros
- [ ] Não contém credenciais, segredos ou chaves reais
- [ ] Demonstra autenticação e segregação de perfis
- [ ] Prevê consentimento, correção e controle de visibilidade
- [ ] Possui trilhas mínimas de auditoria e moderação
- [ ] Apresenta critérios explicáveis de compatibilização
- [ ] Considera acessibilidade e uso em dispositivos móveis
- [ ] Documenta riscos, limitações e uso de inteligência artificial
- [ ] Entrega código-fonte e documentação no prazo

### Higiene de demonstração

Não é exigência do edital, é o que faz a demo não constranger:

- Banco recarregado e semeado na ordem: `semear-candidatos.php`, `abrir-visibilidade-demo.php`,
  `preencher-declarados-demo.php`.
- Lixo de teste fora da primeira página do que a banca vai abrir: `verificar-e4.php` grava duas
  sessões do motor por execução, e `verificar-e6.php` deixa denúncias.
- As demandas do roteiro escritas **depois** de olhar o índice de evidência, nunca antes: os
  vínculos ART para TOS da massa são aleatórios e nenhum cenário pode assumir coerência temática.

---

## O que não conta como pronto

- Teste que existe mas não falharia se o comportamento sumisse.
- Verificador no verde numa tela que ninguém abriu.
- Regra escrita em documento e não implementada no pipeline. Cobrança que se repete vira hook,
  linter ou verificador, nunca promessa de lembrar.
- Item marcado no Anexo VI por leitura de código em vez de execução.
