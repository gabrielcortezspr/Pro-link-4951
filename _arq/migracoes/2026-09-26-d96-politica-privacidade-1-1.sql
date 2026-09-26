-- D95/D96: Política de Privacidade 1.1. Os itens 4 e 8 passam a dizer que documento fechado não
-- entra na compatibilização nem na busca de quem não pode vê-lo. A 1.0 continua gravada: o aceite
-- de cada pessoa aponta para a versão que ela aceitou (con_ter_id), e a vigente é a de maior data.
-- Idempotente: a chave única (ter_tipo, ter_versao) faz a segunda execução não gravar nada.
INSERT IGNORE INTO sis_termos (ter_tipo, ter_versao, ter_conteudo, ter_dt_vigencia) VALUES
  ('PRIVACIDADE', '1.1', '1. Quem trata seus dados

A Equipe 49/51, autora do Pro-Link, entrega deste protótipo ao Desafio CREA Pro-Link do II CENATEC 2026. Não há operação comercial e não há compartilhamento de dados com terceiros.

Esta política descreve o tratamento real implementado no sistema. Onde o texto diz que algo acontece, existe código que faz aquilo, e trilha de auditoria que registra.

2. Que dados tratamos, e por quê

2.1. Dados que você informa

Nome, endereço de e-mail, senha, telefone quando você escolhe informar, e o número do seu CPF ou CNPJ. Finalidade: identificar você, permitir entrada na plataforma e falar com você.

2.2. Seu CPF ou CNPJ, e por que ele fica guardado

Este é o ponto que merece explicação, e não vamos escondê-lo em letra miúda.

A situação do seu registro no CREA muda com o tempo, e a plataforma inteira se apoia nela: um registro suspenso não pode continuar aparecendo com selo de verificação. Essa situação só é devolvida pela consulta por CPF na API oficial. Sem o documento guardado, não haveria como reconsultar, e o seu perfil circularia com uma informação desatualizada.

Por isso o documento é guardado, e guardado assim:
a) cifrado em repouso, com AES-256-GCM, e a chave fica fora do repositório de código;
b) acompanhado de um resumo criptográfico separado, que permite localizar a conta em uma busca exata sem decifrar o documento;
c) exibido sempre mascarado na interface, inclusive para você;
d) decifrado apenas no momento de reconsultar a sua situação na API oficial, uma consulta por vez.

A alternativa, descartar o documento depois do cadastro, é a postura mais limpa em proteção de dados: o dado que não existe não vaza. Ela foi recusada de propósito, porque custaria a atualização da sua situação, e um perfil suspenso continuar visível é pior para você e para quem contrata do que o documento guardado sob cifragem.

2.3. Dados que vêm da API oficial do CREA

Registro Nacional do Profissional (RNP), número de registro, situação, modalidades, ARTs, CATs e acervo operacional. São consultados apenas com o seu consentimento, apenas a partir do seu próprio cadastro, e ficam guardados como cache datado da resposta recebida. A plataforma não cria base própria simulando esses dados e não faz coleta automatizada.

2.4. Dados que você declara

Resumo profissional, tipo de contrato, abrangência geográfica e experiências. São autodeclarados, aparecem sempre distinguidos do que foi verificado, e você edita ou apaga quando quiser.

2.5. Registros de uso

Endereço IP, identificação do navegador, data e hora de cada entrada e de cada operação que altera dado. Finalidade: segurança, auditoria e prova de que a plataforma agiu corretamente. É exigência do edital e é o que permite a você contestar qualquer alteração.

3. Consentimento, e o que você pode revogar

O aceite dos Termos de Uso e desta Política é registrado com data, hora, endereço IP e versão aceita, separadamente um do outro.

Três finalidades dependem de consentimento seu e podem ser revogadas a qualquer momento, no painel de privacidade, sem afetar a sua conta:
3.1. Consultar a API oficial do CREA para validar seu registro e importar suas ARTs.
3.2. Exibir seu perfil para demandantes e na busca pública.
3.3. Receber notificações por e-mail sobre demandas e manifestações.

Revogar a primeira interrompe a atualização da sua situação no conselho. Revogar a segunda fecha o seu perfil por completo: ele sai da busca e das listas de compatíveis.

A revogação é registrada com data e hora, e o registro de que o consentimento existiu é mantido. Sem isso, nem você nem a plataforma conseguiriam provar o que foi autorizado, e quando.

4. Visibilidade: nada é público por padrão

Você decide, campo a campo e documento a documento, entre três níveis: privado, visível apenas para quem tem conta, ou público. O padrão de tudo é privado.

O que você fecha não é usado contra nem a favor de você diante de outra pessoa. Um documento fechado não aparece para quem você não abriu e não entra na compatibilização nem na busca feita por essa pessoa: uma empresa que publica uma demanda só é comparada com as ARTs e CATs que você abriu para quem tem conta, e quem busca sem conta só encontra você pelo que é público. A Certidão de Acervo Técnico segue a ART que ela certifica. Fechar um documento, portanto, pode fazer você aparecer em menos listas de compatíveis; é você quem decide essa troca.

O que a plataforma mostra a você mesmo, no seu painel, usa todo o seu acervo, aberto ou fechado, porque é você olhando para os seus próprios dados.

Não existe superusuário que enxergue perfil fechado. A administração modera denúncia e conteúdo publicado, e não abre o que você fechou.

5. Seus direitos, e onde exercer cada um

5.1. Acesso e portabilidade. O painel de privacidade exporta, em arquivo JSON, tudo o que a plataforma guarda sobre você: conta, consentimentos com datas, sessões, perfil, acervo e experiências.
5.2. Correção. Dado autodeclarado é editável por você a qualquer momento, e cada edição guarda o valor anterior e o novo na trilha de auditoria. Dado verificado vem do conselho e é corrigido lá, não aqui.
5.3. Restrição de uso. Pelos controles de visibilidade e pela revogação de consentimento.
5.4. Eliminação. Descrita no item 6.
5.5. Informação sobre compartilhamento. Não compartilhamos seus dados com nenhum terceiro. A única comunicação externa é a consulta à API oficial do CREA-AM, feita com o seu consentimento.

6. Exclusão da conta: o que acontece, exatamente

Quando você exclui sua conta, imediatamente:
a) todas as suas sessões são encerradas;
b) todos os consentimentos revogáveis são revogados;
c) a conta sai de toda consulta operacional: você deixa de aparecer na busca, nas listas de compatíveis e em qualquer tela da plataforma;
d) o acesso é revogado de forma efetiva, e a conta não entra mais.

O registro não é apagado fisicamente do banco no mesmo ato. O motivo está escrito aqui porque você tem o direito de saber: apagar a linha destruiria junto a trilha de auditoria que prova o que aconteceu com os seus dados, inclusive a prova de que você pediu a exclusão e de que ela foi atendida. O edital do desafio também exige, no item 8.6j, que nada seja apagado fisicamente e que o registro excluído permaneça acessível apenas por mecanismo administrativo.

Por decisão nossa, e você deveria poder cobrar isso de qualquer plataforma: conta que o próprio titular mandou excluir não é reativada por ato administrativo. Ela aparece para a administração como excluída, com a data e o autor do pedido, e o sistema recusa restaurá-la. A eliminação física do registro, quando cabível, é procedimento administrativo com registro próprio, nunca efeito colateral de um clique de outra pessoa.

7. Segurança

7.1. Senhas com algoritmo de derivação lenta e resistente a hardware dedicado.
7.2. CPF e CNPJ cifrados em repouso com AES-256-GCM.
7.3. Toda consulta ao banco por instrução preparada, contra injeção de SQL.
7.4. Proteção contra falsificação de requisição em toda operação de escrita.
7.5. Escape automático de tudo que é exibido, contra injeção de conteúdo.
7.6. Controle de acesso conferido por operação, não apenas por tela.
7.7. Trilha de auditoria protegida contra alteração e remoção no próprio banco de dados.
7.8. Sessão encerrada por inatividade e revogável a qualquer momento.

Nenhuma medida elimina risco por completo. Se um incidente de segurança afetar seus dados, a plataforma comunicará o ocorrido e o que foi feito.

8. A compatibilização, e por que ela não é inteligência artificial

O motor que aproxima demanda e perfil é determinístico e explicável. Ele compara os códigos da Tabela de Obras e Serviços exigidos pela demanda com os códigos das ARTs que você abriu para quem publicou a demanda, como descrito no item 4, e soma seis dimensões com pesos declarados, visíveis e auditáveis.

Não há modelo de aprendizado de máquina, não há treinamento sobre seus dados, e nenhum dado seu alimenta sistema de terceiros. Toda execução guarda a semente que ordenou a lista, e a administração pode reproduzi-la depois para conferir que o resultado foi o mesmo.

Perfil com poucos documentos é sinalizado como em construção e nunca é excluído das listas: quem está começando é justamente quem mais precisa aparecer. Essa sinalização e seus limites estão declarados na documentação técnica da entrega.

9. Retenção

Enquanto a conta existir, os dados ficam guardados. O cache das respostas da API é datado e reconstruível. A trilha de auditoria é mantida pelo tempo necessário para comprovar as operações, inclusive depois da exclusão da conta, exatamente porque é ela que prova o atendimento dos seus pedidos.

10. Alterações nesta política

Cada versão é guardada com número e data de vigência, e o seu aceite fica vinculado à versão aceita. Mudança relevante exige novo aceite.

11. Contato

Protótipo acadêmico submetido ao II CENATEC 2026. O contato é a própria equipe responsável pela submissão, pelos canais informados na plataforma do desafio.

O que mudou em relação à versão 1.0: os itens 4 e 8 passaram a dizer que documento fechado não entra na compatibilização nem na busca de quem não pode vê-lo. Antes, a plataforma usava na compatibilização uma ART fechada sem mostrar o número dela. A mudança só reduz o uso dos seus dados, e nenhum tratamento novo foi criado.

Versão 1.1, em vigor desde 26/09/2026.', '2026-09-26');
