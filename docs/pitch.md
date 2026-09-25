# Roteiro do pitch

O pitch do Demo Day, 26/09. O edital (item 8.3) dá **até 8 minutos** de apresentação oral, depois
até 5 de demonstração ao vivo e até 7 de perguntas da banca. Este documento é só os 8 minutos e a
preparação para as perguntas; a demonstração está em [`roteiro-demo.md`](roteiro-demo.md).

O texto falado soma perto de 1.000 palavras, o que dá uns 7min15 em ritmo de apresentação. A
folga de 45 segundos é de propósito: nervosismo acelera, mas pausa e troca de slide comem tempo.

## A estratégia

Todas as equipes vão explicar o mesmo problema, com as mesmas palavras do edital. A banca já o
conhece. Então o problema leva **menos de um minuto**, dito do nosso ângulo, e o resto do tempo é
gasto no que só nós temos: **as decisões**, cada uma com a alternativa que recusamos.

A frase que precisa sobrar na cabeça da banca: **o dado existe, a ponte não. A ponte é a TOS.**

Cada bloco foi escrito para pontuar num critério da Ficha B (Anexo IV):

| Bloco | Tempo | Quem | Critério da Ficha B que ele alimenta |
|---|---|---|---|
| 1 · Abertura | 0:00 · 0:25 | Gabriel | |
| 2 · O problema | 0:25 · 1:15 | Gabriel | Aderência ao desafio (15) |
| 3 · A TOS como elo | 1:15 · 2:50 | Gabriel | Inovação (10), Viabilidade técnica (15) |
| 4 · Compatível, não ranqueado | 2:50 · 4:05 | Gabriel | Segurança, privacidade e ética (15) |
| 5 · Confiança no dado | 4:05 · 5:35 | Camila | Segurança, privacidade e ética (15) |
| 6 · Respeito à fonte e honestidade | 5:35 · 6:40 | Camila | Ética, Maturidade (10) |
| 7 · Maturidade e impacto | 6:40 · 7:30 | Gabriel | Maturidade (10), Impacto institucional (15) |
| 8 · Fechamento | 7:30 · 7:45 | Camila | |

A divisão segue o papel de cada um no projeto: Gabriel fala do motor e da integração, Camila da
segurança, da privacidade e da auditoria. Segurança, privacidade e ética é o **primeiro critério
de desempate** (13.7a), e por isso tem dois blocos.

---

## O texto

Entre colchetes, a indicação do slide. O visual vem depois; aqui o que importa é o que cada slide
precisa mostrar para o texto funcionar.

### 1 · Abertura · Gabriel · 0:00

> [Slide: só uma frase, em fundo escuro. "Quem já fez planejamento urbano em Manaus?"]

Uma empresa em Manaus precisa de alguém para um projeto de planejamento urbano. Hoje ela pergunta
a um conhecido, recebe um currículo e acredita no que está escrito.

Só que o CREA-AM já sabe quem fez planejamento urbano. Está registrado, ART por ART.

Nós somos a equipe 49/51, e isto é o Pro-Link.

### 2 · O problema · Gabriel · 0:25

> [Slide: o plano de dois eixos, X e Y, achatado. O eixo Z entra depois.]

Vocês vão ouvir o problema de todas as equipes hoje, então a gente diz em uma frase: **o dado
existe, a ponte não.**

Na proposta, descrevemos o mercado de engenharia como um plano de dois eixos. **Confiabilidade**:
o profissional declara o que sabe, e a empresa acredita ou não. **Compatibilidade**: a busca
devolve uma lista, não uma correspondência com a demanda. E faltava o terceiro eixo, o
**contato**: um ponto fixo e oficial onde as duas partes se encontram.

> [O eixo Z sobe, e o plano vira volume.]

O que torna este desafio diferente é que a prova já existe. ART, CAT, acervo operacional. Só que
ela está presa na fiscalização, e o mercado não a enxerga.

### 3 · A TOS como elo · Gabriel · 1:15

> [Slide: a cadeia, da esquerda para a direita. Necessidade → Atividade TOS → Capacidade
> comprovada → Profissional ou Empresa.]

Então a pergunta deixou de ser "como montar um perfil bonito" e virou "como ligar uma necessidade
a uma prova". A resposta estava dentro da própria API: a **Tabela de Obras e Serviços**, a TOS.

A empresa não descreve a demanda em texto livre. Ela escolhe as atividades técnicas da TOS. E
cada ART que a API devolve já traz as atividades TOS em que o profissional atuou. Os dois lados
passam a falar o mesmo vocabulário, e esse vocabulário é do Sistema Confea/Crea, não nosso.

> [Slide: dois códigos, TOS_1.1.2.1 e TOS_1.1.2.5, com os três primeiros níveis destacados.]

E o código TOS é hierárquico. 1.1.2.1 e 1.1.2.5 são a mesma obra com material diferente. A
afinidade é quantos níveis os dois códigos compartilham: quatro níveis iguais vale 1; três, 0,75;
dois, 0,40. Compatibilidade tem grau, e o grau se explica em uma frase.

A empresa também tem acervo: ela herda as ARTs de quem está no quadro técnico **hoje**. Vínculo
encerrado não conta.

> [Slide: barra 70 / 30. Verificado contra autodeclarado.]

E nada que o usuário digita entra na parte forte do cálculo sem passar pela API. As três
dimensões verificadas, competência, área de atuação e localização, somam **70%** do peso. As
autodeclaradas, 30%. É a tradução em número de "evidência antes de declaração".

### 4 · Compatível, não ranqueado · Gabriel · 2:50

> [Slide: três linhas. O score decide **quem entra**. A semente decide **a ordem**. A explicação
> diz **por quê**.]

Aí vem a restrição que mais moldou a arquitetura. O item 10.1 proíbe ranking de profissionais. E
ordenar por nota é ranking, com qualquer nome que a gente dê.

Então separamos três perguntas. O score decide quem entra no pool. Uma semente sorteada por sessão
decide a ordem. E cada card mostra por que aquela pessoa entrou: qual ART, com qual código TOS.

Quem entrou com 0,9 e quem entrou com 0,6 aparecem lado a lado. Sem posição, sem estrela, sem
"melhor match". A empresa vê que cada um é compatível e por quê, e decide sozinha, que é o que o
item 10.2 pede.

> [Slide: uma linha do log de sessão. Empresa, data, semente, pool, limiar.]

E a semente fica gravada junto com o pool e o limiar. O administrador reabre qualquer sessão
passada e vê exatamente o que aquela empresa viu. Os pesos também não são segredo: ficam numa tela
da administração, com a faixa aceita, e cada alteração entra na trilha de auditoria.

Isso é o item 12.3 inteiro: critério explicável, viés declarado, supervisão humana. E não há
inteligência artificial decidindo nada no produto. É regra, e regra se lê.

### 5 · Confiança no dado · Camila · 4:05

> [Slide: o selo verde água ao lado de uma ART, e embaixo: "prova que não foi alterado".]

Se a tese é evidência, a evidência precisa ser protegida.

Cada ART importada ganha um **selo**: uma assinatura HMAC sobre os campos que vieram da API e
sobre a lista de atividades TOS. Ele é recalculado toda vez que a ART aparece na tela. Se alguém
trocar um código TOS direto no banco, que é exatamente onde uma fraude compensaria, o selo quebra,
a tela avisa e a divergência vai para a auditoria.

E dizemos com precisão o que ele prova: que ninguém mexeu no dado depois que ele chegou.
Procedência, só o CREA poderia assinar. A gente não afirma mais do que consegue defender.

> [Slide: quatro itens curtos, um por linha.]

Privacidade desde a concepção. **Nenhum campo é público por padrão**: o profissional abre campo
por campo, ART por ART. CPF e CNPJ ficam cifrados com AES-256, com um hash cego separado para a
busca. Nada é apagado fisicamente, a exclusão é lógica. E a trilha de auditoria só aceita
inserção, garantida por gatilho no próprio banco, e alcança também o administrador.

Quando alguém manifesta interesse, o sistema congela o que a outra parte podia ver naquele
momento. Mudar o perfil depois não reescreve o que já foi visto.

### 6 · Respeito à fonte e honestidade · Camila · 5:35

> [Slide: "Nada de varredura." e "O cache não simula a API."]

Duas regras do edital que é fácil contornar, e que a gente tratou como requisito.

**Nada de varredura.** Toda chamada à API é registrada pela organização, e o item 10.4 proíbe
coleta automatizada. O Pro-Link só consulta a API no cadastro do candidato e sob demanda. O nosso
cliente da API não tem método de listagem em massa, de propósito.

**O cache não simula a API.** O que guardamos é resposta real, datada, com hash, reconstruível a
partir da fonte. Nunca dado escrito à mão.

> [Slide: "O que declaramos", três linhas.]

E honestidade sobre os limites. Perfil sem nenhuma ART não entra no feed, porque recomendar alguém sem
evidência contradiria a tese; ele continua encontrável na busca. E quem está começando, com uma
ou duas ARTs, entra no pool sinalizado, sem ser punido por campo vazio.

São **79 decisões registradas**, cada uma com a alternativa que recusamos e o motivo.

### 7 · Maturidade e impacto · Gabriel · 6:40

> [Slide: a stack em uma linha, e três números. 216 testes · 6 cenários de ponta a ponta · 79
> decisões.]

Tudo isso na stack que o CREA-AM já usa: PHP 8.2, MariaDB, Twig e Bootstrap 5, em MVC com camada
de serviço, e sobe com um único `docker compose`. São 216 testes automatizados, e uma suíte que
percorre os seis cenários do edital no navegador.

> [Slide: três colunas. CREA-AM · Profissional · Quem contrata.]

Para o CREA-AM, o dado que o Conselho já mantém vira valor de mercado, e cada contratação formal
gera ART nova: o acervo cresce. Para o profissional, ser encontrado pelo que provou fazer. Para
quem contrata, saber em quem está confiando.

### 8 · Fechamento · Camila · 7:30

> [Slide: a marca, e a frase.]

O Pro-Link não é banco de vagas. É **identidade técnica verificada**: o que aparece no perfil, o
profissional provou.

Agora a gente mostra funcionando.

---

## O que não dizer

Cada item abaixo é uma frase natural que a banca pode derrubar numa pergunta.

- **"Melhor candidato", "top", "mais compatível", "primeiro lugar".** É ranking (10.1). O que se
  diz é "compatível" e "por quê".
- **"O selo prova que o dado veio do CREA."** Não prova. Prova integridade em repouso (D17).
- **"A CAT dá pontos ao profissional."** Não dá. Ela reforça a competência **na atividade que
  certifica**, e só enquanto vigente (D76). Não é dimensão própria e não cria diferença entre quem
  já tem o código exato.
- **"Perfil em construção nunca sai do pool."** Vale para quem tem pelo menos uma ART (D37).
- **"Usamos IA."** Não há IA no produto. Houve assistência de IA no desenvolvimento, e isso está
  declarado (12.3).
- **Qualquer número de desempenho** que não foi medido.

## Preparação para as perguntas

Sete minutos, provavelmente quatro ou cinco perguntas. Resposta curta primeiro, detalhe só se
pedirem. Quem responde é quem falou do assunto no pitch.

**"Se não é ranking, a empresa não perde tempo olhando todo mundo?"** · Gabriel
O limiar já corta quem não é compatível. O pool é pequeno, e cada card diz o motivo. A decisão de
quem é melhor para aquela obra é da empresa, e o edital (10.2) diz que tem de ser.

**"A semente não é só ordem aleatória? Qual a vantagem?"** · Gabriel
Aleatória e reproduzível. Sorteio sem registro não se audita; com a semente gravada, o
administrador refaz qualquer sessão e vê o que a empresa viu. Ela também combate o viés de
posição: ninguém fica sempre em primeiro.

**"Como o sistema sabe o que a demanda precisa?"** · Gabriel
A empresa escolhe as atividades na TOS. Não interpretamos texto livre: isso seria classificar
linguagem natural, que o 12.3 obrigaria a declarar como IA, e erraria calado.

**"Os dados fictícios fazem sentido tematicamente?"** · Gabriel
Não, e a gente descobriu cedo: os vínculos ART → TOS da massa são aleatórios. Por isso a demanda
da demonstração foi escolhida depois de olhar o índice, e não antes. Nenhum cenário depende de
coerência que a massa não tem.

**"E se a API cair?"** · Gabriel
O cadastro não se perde: fica pendente, e a sincronização completa numa próxima tentativa (D20).
O motor roda sobre o índice local, então a busca e o feed continuam.

**"Profissional com registro suspenso aparece?"** · Camila
Não. A situação do registro só vem na consulta por CPF, e é por isso que guardamos o CPF, cifrado.
Suspenso fecha a visibilidade (D64).

**"Por que guardar CPF, se a LGPD pede minimização?"** · Camila
Porque sem ele não dá para saber se o registro continua ativo, e mostrar profissional suspenso é
um risco maior para quem contrata. Fica cifrado, com hash cego para busca, e a decisão está
registrada (D03).

**"O administrador pode apagar a trilha de auditoria?"** · Camila
Não. A trava é um gatilho no banco, não uma regra da aplicação: nem o código da plataforma
consegue alterar ou apagar uma linha. E as ações do próprio administrador entram nela.

**"O que acontece se o profissional mudar o perfil depois de manifestar interesse?"** · Camila
A empresa continua vendo o que viu quando o interesse foi registrado. O snapshot congela a visão
dela naquele momento, não o perfil inteiro (D57).

**"Isso escala para o CREA-AM de verdade?"** · Gabriel
A stack é a do Conselho, o motor roda local sobre uma view indexada, e a API só é chamada no
cadastro. O que muda em produção é a origem do dado, não a arquitetura. E a licença do edital
(Anexo V) já prevê a evolução institucional.

**"O que vocês fariam com mais tempo?"** · qualquer um
O reforço da CAT proporcional à pertinência da ART, que hoje pesa mais justamente onde a evidência
é mais fraca (decisão adiada em 24/09). O convite ativo do demandante com modelo de dados próprio, se
o Conselho entender que não fere o 10.1. E auditoria de acessibilidade com quem usa leitor de
tela, que não foi feita.
