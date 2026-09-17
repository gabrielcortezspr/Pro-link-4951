# Autoavaliação do Anexo VI

O Anexo VI é o filtro de triagem: a banca pode usar os doze itens para decidir o que segue para
avaliação e o que não segue. Este documento responde item a item, **com o lugar onde cada resposta
se verifica**, para que ninguém precise acreditar na afirmação.

Onde a resposta é parcial, ela está escrita como parcial. Triagem é o pior lugar para descobrir
que uma afirmação não se sustenta, e o edital pontua maturidade, que inclui saber o que ainda não
está pronto.

---

## 1. Executa as funcionalidades essenciais

**Sim.** Os seis cenários mínimos do Anexo I, item 7, rodam de ponta a ponta no navegador:
`e2e/specs/cenarios.spec.js`. `e2e/specs/demonstracao.spec.js` percorre os mesmos seis em um
contexto só e grava o vídeo da execução.

Roteiro por escrito, com as contas e o motivo de cada escolha: `docs/roteiro-demo.md`.

## 2. Utiliza apenas dados sintéticos, anonimizados ou autorizados

**Sim, e só os da organização.** A massa vem da API oficial do CREA-AM fornecida para o desafio, e
nenhuma base própria simula a API, o que o item 8.4 veda. As tabelas `crea_*` são cache datado da
resposta real, reconstruível a partir dela: o cabeçalho de cada uma diz isso, e `docs/api.md`
registra o que foi consumido.

Nenhum dado pessoal real entra na plataforma. As contas de demonstração usam o domínio
`@prolink.local`, que não existe.

## 3. Possui instruções reproduzíveis de instalação e execução

**Sim.** `_arq/README.md`: requisitos mínimos, instalação, configuração, execução e atualização.
Sobe com `docker compose up -d`, e `curl http://localhost:8080/saude` responde o estado das peças.

A ordem de preparo do dado, que importa e não é adivinhável, está em `docs/estado.md` e resumida
no `docs/roteiro-demo.md`.

## 4. Identifica dependências, licenças e componentes de terceiros

**Sim.** `_arq/dependencias.md`: cada biblioteca com versão, licença e por que está no projeto,
separando o que é servido ao usuário do que é ferramenta de desenvolvimento.

## 5. Não contém credenciais, segredos ou chaves reais

**Sim.** O `.env` não é versionado, e o `.env.example` entregue traz **todos os campos sensíveis
vazios**: `APP_KEY`, `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `PROLINK_API_TOKEN`, `MAIL_PASSWORD`.

A senha da conta de administração usada pela suíte de testes vem do ambiente
(`PROLINK_E2E_ADMIN_SENHA`), sem valor padrão: sem ela o teste **pula**, em vez de trazer uma
credencial embutida para dentro do repositório.

## 6. Demonstra autenticação e segregação de perfis

**Sim.** Quatro perfis, com o que cada um pode fazer definido no Anexo I, item 3. A sessão é do
servidor, com identificador rotacionado; a senha usa Argon2id; o token de sessão é conferido a
cada requisição, e o perfil é **reconferido no banco** a cada requisição, e não lido do que ficou
guardado na sessão.

O que a plataforma precisa recusar está em `e2e/specs/seguranca.spec.js`, que tenta o acesso
indevido e cobra a recusa.

## 7. Prevê consentimento, correção e controle de visibilidade

**Sim, os três.**

- **Consentimento** com data, hora e versão do texto aceito, em `/privacidade`.
- **Correção** em `/perfil`, com o valor anterior e o novo indo para a trilha.
- **Visibilidade** por campo e por documento, em três níveis, com o padrão em "Só eu": nada é
  público sem escolha.

Também estão lá a exportação dos dados em JSON e a exclusão de conta, com o efeito escrito na
frente de quem clica.

## 8. Possui trilhas mínimas de auditoria e moderação

**Sim.** `sis_auditoria` é insert-only **no banco**, por gatilho (`trg_aud_bloqueia_update` e
`trg_aud_bloqueia_delete` em `_arq/estrutura.sql`): nem o administrador altera o que já foi
registrado, e a garantia não depende de disciplina da aplicação.

Moderação em `/admin/denuncias`, com providência registrada. A ação do próprio administrador
aparece na trilha, e isso é parte da demonstração.

## 9. Apresenta critérios explicáveis de compatibilização

**Sim, e é a tese do projeto.** Seis dimensões com peso declarado, exibidas separadas na tela em
vez de uma nota agregada. As três que vêm da API oficial somam 0,70; as três autodeclaradas somam
0,30.

Três coisas sustentam a explicabilidade:

- **A ordem não é classificação.** O score decide quem entra no conjunto; a ordem vem de uma
  semente sorteada e gravada. O item 10.1 veda ranking de profissionais, e a plataforma sorteia de
  verdade em vez de contornar a vedação com eufemismo.
- **A sessão é reproduzível na frente de quem audita.** Em `/admin/sessoes/{id}` a plataforma
  refaz o sorteio a partir da semente e compara com o que ficou gravado.
- **Os pesos são editáveis com trilha**, em `/admin/parametros`, com a faixa aceitável declarada
  por campo. É o item 12.3 deixando de ser uma frase na documentação.

O raciocínio completo está em `docs/matching.md`.

## 10. Considera acessibilidade e uso em dispositivos móveis

**Parcialmente, e o limite está declarado.** O que é verificável por máquina é verificado, em
`e2e/specs/responsivo.spec.js`, a 390px: ausência de rolagem horizontal, rótulo associado em todo
campo, hierarquia de título sem salto, imagem com texto alternativo e foco de teclado visível.

O que **não** foi feito: auditoria com leitor de tela real e conferência de contraste em toda
combinação de cor. São julgamento humano, e afirmar conformidade sem ter feito seria falso.

## 11. Documenta riscos, limitações e uso de inteligência artificial

**Sim.** `_arq/arquitetura.md`, seção "Declaração de uso de inteligência artificial, vieses e
limitações": **não há IA no produto**. Nenhum modelo decide, ordena ou pontua. Foi usada
assistência de IA no desenvolvimento, e isso está declarado como o item 12.3 pede.

A mesma seção traz os vieses conhecidos do motor, incluindo o que a dimensão de experiência
declarada premia, e o que a plataforma faz a respeito.

## 12. Entrega código-fonte e documentação no prazo

Entrega de 17/09/2026, até 18h.

---

## O que esta autoavaliação não afirma

- Que a plataforma está pronta para produção. É protótipo de desafio, com massa fictícia.
- Que a cobertura de teste é completa. Ela cobre os seis cenários, a responsividade, a segurança
  que se pode exercitar pelo navegador, e a fidelidade das telas ao desenho.
- Que a acessibilidade foi auditada por pessoa usuária de leitor de tela. Não foi.
