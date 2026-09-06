# Glossário

Termos do sistema Confea/CREA que aparecem na API. Escrito pra quem não é da área.

**CREA** (Conselho Regional de Engenharia e Agronomia). Autarquia estadual que registra e fiscaliza profissionais e empresas de engenharia, agronomia e geociências. O CREA-AM organiza o desafio.

**Confea**. Conselho federal que coordena os CREAs e define tabelas nacionais, como a TOS.

**RNP** (Registro Nacional Profissional). Número único nacional do profissional. É o identificador usado em toda a API depois que você achou a pessoa pelo CPF.

**Registro CREA**. Número do registro no conselho regional. Existe pra profissional e pra empresa, no mesmo formato. Pra empresa, é a chave dos endpoints de quadro técnico e CAO.

**Modalidade**. Área de formação do profissional (Engenharia Civil, Elétrica, Agronomia...). Diz o que a pessoa **pode** fazer por atribuição legal. Um profissional pode ter mais de uma.

**ART** (Anotação de Responsabilidade Técnica). Documento que o profissional registra no CREA para cada obra ou serviço técnico que assume. Prova que ele **fez**, ou se responsabilizou por, aquilo. É a unidade básica de evidência.

**Atividade da ART**. Cada ART lista uma ou mais atividades técnicas. Cada atividade aponta pra um código da TOS mais uma descrição livre escrita pelo profissional. É o nível mais granular do sistema, e é onde mora o poder de discriminar quem faz o quê.

**CAT** (Certidão de Acervo Técnico). Certidão emitida pelo CREA em nome do profissional que agrupa uma ou mais ARTs para comprovar experiência, normalmente para licitação. Tem prazo de validade. Uma CAT com atestado (documento do contratante confirmando a execução) vale mais que uma sem.

**Acervo técnico**. O conjunto de ARTs e CATs de um profissional. O histórico comprovado dele.

**Quadro técnico**. Lista de profissionais vinculados a uma empresa como responsáveis técnicos, com cargo e data de início. É a ponte que permite a empresa herdar o acervo dos seus profissionais.

**CAO** (Certidão de Acervo Operacional). O equivalente do acervo técnico, mas para a empresa: reúne o que os profissionais do quadro técnico fizeram. Na API não é uma tabela, é um objeto montado em tempo real.

**TOS** (Tabela de Obras e Serviços). Vocabulário padronizado de atividades técnicas do Confea, organizado em quatro níveis: grupo, subgrupo, obra/serviço e complementar. É o que permite comparar uma necessidade com uma capacidade de forma objetiva, sem depender de texto livre.

**Atribuição x experiência.** Vale guardar a diferença, porque ela sustenta o ranking: a **modalidade** diz o que o profissional tem permissão de fazer; a **ART** diz o que ele efetivamente fez; a **CAT** diz o que foi certificado por terceiro. Peso crescente de evidência nessa ordem.
