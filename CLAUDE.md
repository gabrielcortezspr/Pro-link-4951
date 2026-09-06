# CLAUDE.md

Contexto pra qualquer agente (Claude Code) que abrir este repositório.

## O projeto

Plataforma da **Equipe 49/51** pro **Desafio CREA Pro-Link** (II CENATEC 2026, CREA-AM).

Um marketplace que liga demandas técnicas a profissionais e empresas registrados no CREA usando
**evidência documental** (ARTs, CATs, CAO) em vez de autodeclaração. O elo entre "o que precisa
ser feito" e "quem já provou que sabe fazer" é a Tabela de Obras e Serviços (TOS):

```
Necessidade → Atividade TOS → Capacidade comprovada → Profissional / Empresa
```

Os dados vêm da API oficial do desafio, que é somente leitura e serve massa fictícia.

**Entrega: 17/09/2026 às 18h.** Demo Day presencial em 26/09.

## Stack

PHP 8.2 · MariaDB 10.11 · Twig · Bootstrap 5 · Docker · Composer. MVC com camada de serviço.
Tudo isso é exigência do edital, não escolha. Python em `scripts/` é só ferramenta offline.

## Índice

A tabela "leia X quando Y" está no `README.md`. Não a duplique aqui.

## Regras que não podem ser quebradas

**Token fora do repositório.** Vai em `PROLINK_API_TOKEN` no `.env`, e o `.env` no `.gitignore`.
Nenhuma credencial, nem de desenvolvimento, entra em arquivo versionado (Anexo VI do edital).

**Identificador é string, nunca int.** `pro_rnp` = `"0412340011"` e `emp_cnpj` = `"00123001000123"`
têm zero à esquerda. Converter pra número quebra a requisição.

**Código TOS não se ordena como texto.** `TOS_1.1.2.1` é hierárquico. Alfabeticamente `TOS_10`
vem antes de `TOS_2`. Sempre fazer split em `.` e comparar como inteiros — `Support\Tos::niveis()`.

**Texto tem acento e caixa mista.** Normalizar (NFD, remover diacríticos, casefold) antes de comparar.

**Nada de varredura.** Toda chamada é registrada pela organização e o item 10.4 do edital proíbe
coleta automatizada. Buscar no cadastro do candidato, cachear localmente, atualizar sob demanda.
`CreaApiClient` não tem método de listagem em massa de propósito.

**O cache não simula a API.** O item 8.4 veda base própria que simule os dados da API. As tabelas
`crea_*` guardam resposta real, datada e com hash — nunca dado escrito à mão, nunca fonte para
validação de documento.

**Sem ranking.** O item 10.1 veda ranking de profissionais. O score decide quem entra no pool;
a semente da sessão decide a ordem. Nunca exibir posição, nota ou "melhor match".

**CPF e CNPJ cifrados, nunca em claro.** Ficam em `*_documento_cif` (AES-256-GCM) com hash cego
separado para busca. A API não devolve CPF; guardá-lo é necessário só porque `pro_status` vem
apenas na busca por CPF.

**Toda query é prepared statement.** Só repositórios escrevem SQL. Nenhum controller ou template
toca o banco.

**Nada é apagado.** `_status = 'X'` marca exclusão (item 8.6j). `sis_auditoria` é insert-only,
com trigger.

**Nomenclatura do edital.** Tabela `modulo_entidade`, campo com prefixo de três letras, PK
`<prefixo>_id`, constraints `pk_`/`fk_`, e `_dt_registro`, `_log`, `_status` em toda tabela.

## Armadilhas já conhecidas

- O CAO tem estrutura diferente da que a organização documentou: objeto plano, com
  `quadro_tecnico` → profissional → `arts` → `atividades`. Não existe `cao_arts`.
- O quadro técnico expõe `qut_dt_fim`. Vínculo encerrado não herda acervo.
- Os vínculos ART → TOS da massa são **aleatórios**. Nenhum cenário de demonstração pode
  assumir coerência temática. Escolha a demanda depois de olhar o índice, não antes.
- `aat_descricao` e `art_objeto` não discriminam ninguém. Só `tos_codigo` discrimina.
- `tos_complementar` e `qut_dt_fim` nulos chegam como `null`, não string vazia.

## Estado e retomada

O estado vivo — onde parou, próximo passo, pendências — fica em `docs/estado.md`, sobrescrito a
cada fechamento com `/encerrar`. O hook `SessionStart` (`.claude/settings.json` →
`scripts/retomar.sh`) imprime esse arquivo, os últimos commits e o `git status` na abertura de
toda sessão. Não repita nada disso aqui: `CLAUDE.md` é para o que não muda.

Princípio de manutenção: **cada fato é escrito num lugar só.** Configuração de infraestrutura no
`.env`, parâmetros do motor em `sis_parametros`, índice de documentação no `README.md`, regras de
negócio do motor em `docs/matching.md`, estado em `docs/estado.md`. Diretório só é criado quando
o primeiro arquivo entra.
