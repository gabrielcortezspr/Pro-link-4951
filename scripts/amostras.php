<?php

declare(strict_types=1);

/**
 * Catálogo de telas com dado de amostra, para quem precisa renderizar de verdade.
 *
 * Um lugar só: scripts/verificar-padrao.php varre o HTML de saída atrás de violação do padrão
 * visual, e as verificações de etapa provam que a tela renderiza. Antes disso cada script montava
 * o seu array de dados, e tela nova só entrava na verificação de quem lembrasse dos dois.
 *
 * Tela que não estiver aqui ainda é conferida na parte estática (o texto do template), mas não na
 * parte renderizada. Ao criar tela, acrescente a entrada.
 *
 * @return array<string, array<string, mixed>>  template => variáveis
 */

use ProLink\Repository\IntegracaoRepository;
use ProLink\Repository\AuditoriaRepository;
use ProLink\Repository\CompatibilizacaoRepository;
use ProLink\Repository\DashboardRepository;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\IndicadorRepository;
use ProLink\Repository\LixeiraRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\MensagemRepository;
use ProLink\Repository\TermoRepository;
use ProLink\Service\BuscaService;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\ContaService;
use ProLink\Service\DemandaService;
use ProLink\Service\DenunciaService;
use ProLink\Service\InteressadoService;
use ProLink\Service\LixeiraService;
use ProLink\Service\ManifestacaoService;
use ProLink\Service\ParametroService;
use ProLink\Service\PerfilEmpresaService;
use ProLink\Service\PerfilService;
use ProLink\Service\PrivacidadeService;
use ProLink\Support\Database;
use ProLink\Support\Preferencias;

return (static function (): array {
    $auditoria = new AuditoriaRepository();
    $denuncias = new DenunciaService();

    $fila = $denuncias->fila(null);
    $uma  = $fila !== [] ? $denuncias->porId((int) $fila[0]['den_id']) : null;

    // O vocabulário fechado das duas dimensões autodeclaradas, que as três telas de formulário
    // oferecem. Vem de Support\Preferencias pelo mesmo motivo que nos controllers: opção nova
    // entra na verificação sozinha.
    $vocabulario = [
        'contratos_possiveis' => Preferencias::CONTRATOS,
        'ufs_possiveis'       => Preferencias::UFS,
    ];

    $niveisPossiveis = [
        VISIBILIDADE_PRIVADO     => 'Só eu',
        VISIBILIDADE_AUTENTICADO => 'Quem tem conta',
        VISIBILIDADE_PUBLICO     => 'Qualquer pessoa',
    ];

    $telas = [
        // A landing não recebe variável nenhuma do controller: o que ela mostra é texto e figura.
        // Entra aqui mesmo assim porque é a porta da aplicação, e porque o verificador só lê o
        // HTML final das telas que estão nesta lista.
        'home.html.twig' => [],

        // A visão geral só vira tela com os indicadores: até 17/09 ela era um espaço reservado sem
        // variável nenhuma, e por isso a entrada aqui não precisava de dado. Agora precisa, e é
        // justamente esta tela que a parte renderizada do verificador tem de ver, porque ela
        // imprime código de perfil e de situação, que é o que só aparece no HTML final.
        'admin/index.html.twig' => [
            'ativo'       => 'visao',
            'indicadores' => (new IndicadorRepository())->resumo(),
        ],

        'admin/auditoria.html.twig' => [
            'ativo'  => 'auditoria',
            'linhas' => $auditoria->listar(null, null, null, null, 50),
            'total'  => $auditoria->contar(null, null, null, null),
            'acoes'  => $auditoria->acoesDistintas(),
            'filtro' => ['usuario' => null, 'acao' => null, 'dias' => 7],
        ],

        'admin/denuncias.html.twig' => [
            'ativo'     => 'denuncias',
            'fila'      => $fila,
            'situacao'  => null,
            'situacoes' => DenunciaService::SITUACOES,
            'tipos'     => DenunciaService::TIPOS,
        ],

        // O formulário de denúncia. Entra com o alvo já resolvido para nome, que é o estado em que
        // o DenunciaController o renderiza: é a resolução do alvo que impede a tela de imprimir o
        // nome da entidade, e é exatamente isso que a conferência do HTML final procura.
        'denuncia/nova.html.twig' => [
            'entidade'    => 'USUARIO',
            'alvo_id'     => 10,
            'alvo_rotulo' => 'Pedro Henrique Alves',
            'tipos'       => DenunciaService::TIPOS,
            'valores'     => [],
            'erros'       => [],
            'aviso'       => null,
        ],

        // A tela de exceção serve cinco códigos com a mesma moldura, e o catálogo monta uma tela
        // por entrada. O caso escolhido é o 404 com mensagem específica, porque é o único que
        // imprime texto que não está escrito no template: a frase vem do controller, e é o único
        // ponto desta tela por onde conteúdo dinâmico chega ao HTML. O resto da tela é cópia fixa.
        // O 500 é justamente o caso em que a mensagem recebida NÃO é impressa, então não há o que
        // conferir nele.
        'erro.html.twig' => [
            'codigo'   => 404,
            'mensagem' => 'Denúncia não encontrada.',
        ],

        // Os parâmetros do motor. Das telas do painel, é uma das que mais precisam da conferência
        // renderizada: ela imprime a chave de sistema de propósito (quem audita quer o
        // identificador exato) e o valor gravado de onze parâmetros, e valor só existe no HTML
        // final. O estado de erro por campo não entra aqui: ele depende de um POST recusado, e
        // este catálogo monta tela de leitura.
        'admin/parametros.html.twig' => [
            'ativo'      => 'parametros',
            'parametros' => (new ParametroService())->listar(),
            'erros'      => [],
            'valores'    => [],
            'aviso'      => '',
        ],

        // A gestão de contas. Entra aqui porque é a tela que mais imprime valor cru vindo do
        // banco: situação (`A`/`I`/`X`), perfil de acesso e tipo de pessoa, todos de lista
        // fechada, e todos traduzidos no template. Valor fora do mapa só aparece no HTML final.
        'admin/contas.html.twig' => [
            'ativo'   => 'contas',
            'lista'   => (new ContaService())->listar(['termo' => '', 'perfil' => '', 'situacao' => ''], 1),
            'filtros' => ['termo' => '', 'perfil' => '', 'situacao' => ''],
            'perfis'  => PERFIS_AUTENTICADOS,
        ],

        //  Integrações: tudo vem do cache e da trilha, e nada chama a API (item 10.4). O token é
        //  lido do ambiente e **não** entra na amostra com valor: a conferência de padrão
        //  renderiza este HTML, e amostra com credencial dentro seria credencial num arquivo.
        'admin/integracoes.html.twig' => [
            'ativo'   => 'integracoes',
            'conexao' => [
                'base'           => API_BASE,
                'tempo_limite'   => API_TIMEOUT,
                'token_presente' => API_TOKEN !== '',
                'token_tamanho'  => mb_strlen((string) API_TOKEN),
                'ambiente'       => APP_ENV,
            ],
            'colecoes'    => (new IntegracaoRepository())->colecoes(),
            'situacao'    => (new IntegracaoRepository())->situacaoDosPerfis(),
            'importacoes' => (new IntegracaoRepository())->ultimasImportacoes(),
        ],

        // A lixeira, na entidade que sempre tem o que mostrar. As outras quatro abas renderizam o
        // mesmo template: o que muda entre elas é a coluna de classificação, que some quando a
        // entidade não tem uma, e quem decide isso é o template, não o dado.
        'admin/lixeira.html.twig' => [
            'ativo'     => 'lixeira',
            'lixeira'   => (new LixeiraService())->visao('sis_usuarios', 1),
            'entidades' => LixeiraRepository::ENTIDADES,
            'erros'     => [],
        ],
    ];

    // ---------------------------------------------------------------- perfil e demandas
    //
    // As quatro telas abaixo entraram tarde na verificação renderizada, e a ausência delas custou
    // um defeito real: `dem_tipo_contrato` passou a guardar chave de lista fechada (`OBRA_CERTA`)
    // e foi parar cru em duas telas, porque a parte estática do verificador não vê valor — só o
    // HTML final vê. Tela de formulário com vocabulário fechado é justamente onde isso acontece.

    // Primeiro profissional com registro validado, e primeira demanda: as duas telas só existem
    // com dado. Banco recém-carregado simplesmente não as oferece, e o verificador relata isso
    // como cobertura parcial em vez de falha — o mesmo tratamento da tela de denúncia abaixo.
    $pdo = Database::conexao();

    $usuarioProfissional = $pdo
        ->query('SELECT prf_usu_id FROM pro_profissionais ORDER BY prf_id LIMIT 1')
        ->fetchColumn();

    $primeiraDemandaId = $pdo
        ->query('SELECT dem_id FROM pro_demandas WHERE dem_status = \'A\' ORDER BY dem_id LIMIT 1')
        ->fetchColumn();

    $perfil = $usuarioProfissional === false
        ? null
        : (new PerfilService())->montar((int) $usuarioProfissional, (int) $usuarioProfissional);

    if ($perfil !== null) {
        $telas['perfil/index.html.twig'] = [
            'titulo'  => 'Meu perfil',
            'perfil'  => $perfil,
            'erros'   => [],
            'aviso'   => null,
            'valores' => [],
            'niveis_possiveis'     => $niveisPossiveis,
            'contratos_possiveis'  => Preferencias::CONTRATOS,
            'ufs_possiveis'        => Preferencias::UFS,
            'abrangencia_qualquer' => Preferencias::QUALQUER,
            'abrangencia_atual'    => Preferencias::ufsDaAbrangencia(
                $perfil['campos']['DISPONIBILIDADE'] ?? null,
            ),
        ];
    }

    // O painel do demandante e o perfil da empresa: as duas telas mostram dado de lista fechada
    // (regime de contratação) e código da Tabela de Obras e Serviços, que é justamente o que só
    // aparece no HTML final. Ficaram fora daqui até a passagem de largura de 15/09, e enquanto
    // ficaram, o código da Tabela de Obras e Serviços saiu cru no acervo da empresa sem que nada
    // reclamasse.
    $donoDeDemanda = $pdo
        ->query('SELECT dem_usu_id FROM pro_demandas WHERE dem_status = \'A\' ORDER BY dem_id LIMIT 1')
        ->fetchColumn();

    if ($donoDeDemanda !== false) {
        $telas['demanda/index.html.twig'] = [
            'titulo'   => 'Minhas demandas',
            'demandas' => (new DemandaRepository())->doUsuario((int) $donoDeDemanda),
        ];
    }

    $usuarioEmpresa = $pdo
        ->query('SELECT emp_usu_id FROM pro_empresas ORDER BY emp_id LIMIT 1')
        ->fetchColumn();

    $perfilEmpresa = $usuarioEmpresa === false
        ? null
        : (new PerfilEmpresaService())->montar((int) $usuarioEmpresa, (int) $usuarioEmpresa);

    if ($perfilEmpresa !== null) {
        $telas['perfil/empresa.html.twig'] = [
            'titulo'  => 'Perfil da empresa',
            'perfil'  => $perfilEmpresa,
            'erros'   => [],
            'aviso'   => null,
            'valores' => [],
            'niveis_possiveis' => $niveisPossiveis,
        ] + $vocabulario;
    }

    // Privacidade e cadastro: as duas telas de LGPD. A primeira imprime perfil de acesso e
    // finalidade de consentimento, que são constantes; a segunda, a versão do termo vigente.
    $qualquerUsuario = $pdo
        ->query('SELECT usu_id FROM sis_usuarios WHERE usu_status = \'A\' ORDER BY usu_id LIMIT 1')
        ->fetchColumn();

    if ($qualquerUsuario !== false) {
        $telas['privacidade/index.html.twig'] = [
            'painel'      => (new PrivacidadeService())->painel((int) $qualquerUsuario),
            'finalidades' => PrivacidadeService::FINALIDADES_REVOGAVEIS,
        ];
    }

    $telas['auth/cadastro.html.twig'] = [
        'valores' => [],
        'erros'   => [],
        'aviso'   => null,
        'termos'  => (new TermoRepository())->vigentes(),
    ];

    $telas['demanda/nova.html.twig'] = [
        'titulo'  => 'Nova demanda',
        'valores' => [],
        'erros'   => [],
    ] + $vocabulario;

    $telas['demanda/abertas.html.twig'] = [
        'titulo'   => 'Demandas abertas',
        'demandas' => (new DemandaRepository())->abertas(),
    ];

    // As duas telas de Início. Sem sessão HTTP o `usuario()` do Twig devolve nulo e o shell de
    // barra lateral não chega a ser montado: o que esta entrada confere é o corpo da tela, que é
    // onde mora o dado. A barra em si é conferida no navegador, logado.
    //
    // O profissional e o demandante escolhidos são os que têm dado: conta vazia renderiza os
    // mesmos blocos com zero, e o estado vazio já é exercitado pelas outras telas.
    $umProfissional = $pdo->query(
        'SELECT p.prf_usu_id
           FROM pro_profissionais p
           JOIN pro_manifestacoes m ON m.man_usu_id = p.prf_usu_id AND m.man_status = \'A\'
       GROUP BY p.prf_usu_id
       ORDER BY COUNT(m.man_id) DESC
          LIMIT 1'
    )->fetchColumn();

    $umDemandante = $pdo->query(
        'SELECT dem_usu_id FROM pro_demandas
          WHERE dem_status = \'A\'
       GROUP BY dem_usu_id
       ORDER BY COUNT(dem_id) DESC
          LIMIT 1'
    )->fetchColumn();

    $dashboard     = new DashboardRepository();
    $demandaRepo   = new DemandaRepository();
    $manifestaRepo = new ManifestacaoRepository();

    if ($umProfissional !== false) {
        $telas['inicio/profissional.html.twig'] = [
            'titulo'   => 'Início',
            'numeros'  => $dashboard->doProfissional((int) $umProfissional),
            'demandas' => array_slice($demandaRepo->abertas(10), 0, 5),
            'enviadas' => array_slice($manifestaRepo->doUsuario((int) $umProfissional), 0, 5),
        ];
    }

    if ($umDemandante !== false) {
        $telas['inicio/demandante.html.twig'] = [
            'titulo'       => 'Início',
            'numeros'      => $dashboard->daEmpresa((int) $umDemandante),
            'demandas'     => array_slice($demandaRepo->doUsuario((int) $umDemandante), 0, 5),
            'tem_registro' => true,
        ];
    }

    // A busca ativa, do ponto de vista de quem não tem conta: é o alcance mais restrito e o mais
    // exposto, já que esta é a única tela aberta ao perfil Público. Espectador nulo é o anônimo,
    // e é dele que o HTML final precisa ser conferido.
    $telas['busca/profissionais.html.twig'] = [
        'titulo' => 'Buscar profissionais',
        'busca'  => (new BuscaService())->profissionais('', false, null),
    ];

    $umaDemanda = $primeiraDemandaId === false
        ? null
        : (new DemandaService())->comTos((int) $primeiraDemandaId);

    if ($umaDemanda !== null) {
        $telas['demanda/ver.html.twig'] = [
            'titulo'     => $umaDemanda['dem_titulo'],
            'demanda'    => $umaDemanda,
            'eh_dono'    => true,
            'busca'      => '',
            'resultados' => [],
            'erros'      => [],
        ] + $vocabulario;
    }

    // O feed de compatíveis: a sessão gravada é a fonte, não um cálculo novo. `paraDemanda()`
    // relê a última sessão da demanda e só executa o motor quando não existe nenhuma — por isso
    // a demanda escolhida aqui é a que já tem sessão com pool, e a verificação não fica gravando
    // uma linha em `mat_sessoes` a cada rodada.
    $comSessao = $pdo->query(
        'SELECT s.mts_dem_id
           FROM mat_sessoes s
           JOIN mat_sessao_pool p ON p.msp_mts_id = s.mts_id
           JOIN pro_demandas d    ON d.dem_id     = s.mts_dem_id
          WHERE d.dem_status = \'A\'
       GROUP BY s.mts_id
       ORDER BY COUNT(p.msp_id) DESC, s.mts_id DESC
          LIMIT 1'
    )->fetchColumn();

    if ($comSessao !== false) {
        $demandaDoFeed = (new DemandaService())->comTos((int) $comSessao);

        if ($demandaDoFeed !== null) {
            $telas['demanda/compativeis.html.twig'] = [
                'titulo'  => 'Compatíveis',
                'demanda' => $demandaDoFeed,
                'sessao'  => (new CompatibilizacaoService())->paraDemanda(
                    (int) $comSessao,
                    (int) $demandaDoFeed['dem_usu_id'],
                ),
            ];
        }
    }

    // As duas telas de auditoria de sessão. A escolhida para o detalhe é a de MAIOR pool: é ela
    // que exercita a fileira de comparação inteira, a tabela de dimensões com valor e com
    // dimensão não medida, e o candidato sem nome. Uma sessão de pool vazio renderiza metade da
    // tela, e passaria na verificação sem ter conferido nada.
    $sessoes = (new CompatibilizacaoRepository())->recentes();

    $telas['admin/sessoes.html.twig'] = [
        'ativo'   => 'sessoes',
        'titulo'  => 'Sessões do motor',
        'sessoes' => $sessoes,
    ];

    $maior = null;

    foreach ($sessoes as $sessao) {
        if ($maior === null || (int) $sessao['mts_total_pool'] > (int) $maior['mts_total_pool']) {
            $maior = $sessao;
        }
    }

    if ($maior !== null) {
        $reproducao = (new CompatibilizacaoService())->reproduzir((int) $maior['mts_id']);

        if ($reproducao !== null) {
            $telas['admin/sessao.html.twig'] = [
                'ativo'      => 'sessoes',
                'titulo'     => 'Sessão ' . (int) $maior['mts_id'],
                'reproducao' => $reproducao,
            ];
        }
    }

    // ---------------------------------------------------------------- manifestação de interesse
    //
    // As quatro telas do cenário 4 do Anexo I. Todas dependem de uma manifestação real, que um
    // banco recém-carregado não tem: sem ela, as entradas simplesmente não entram, e o verificador
    // relata cobertura parcial em vez de falhar.
    //
    // Nenhuma delas passa por `InteressadoService::abrir()`, que é o caminho da tela de detalhe.
    // `abrir()` **escreve**: marca a manifestação como vista e carimba as mensagens como lidas. Um
    // script de verificação que roda a cada conferência não pode consumir o estado "não lida" da
    // base de demonstração. Por isso o detalhe é montado aqui a partir dos mesmos repositórios,
    // com a mesma conferência de hash que o serviço faz na leitura.
    $umaManifestacao = $pdo
        ->query('SELECT man_id FROM pro_manifestacoes WHERE man_status = \'A\' ORDER BY man_id LIMIT 1')
        ->fetchColumn();

    if ($umaManifestacao !== false) {
        $manifestacoes = new ManifestacaoRepository();
        $registro      = $manifestacoes->porId((int) $umaManifestacao);

        if ($registro !== null) {
            $snapshot = json_decode((string) $registro['man_snapshot'], true);

            $telas['manifestacao/ver.html.twig'] = [
                'titulo'        => 'Manifestação',
                'manifestacao'  => $registro,
                // O lado do demandante: é o do cenário 4 ("a empresa visualiza o perfil"), e o
                // que desenha mais coisa na tela.
                'eh_demandante' => true,
                'perfil'        => is_array($snapshot) ? $snapshot : null,
                'integro'       => hash('sha256', (string) $registro['man_snapshot'])
                                   === $registro['man_snapshot_hash'],
                'mensagens'     => (new MensagemRepository())->daManifestacao((int) $umaManifestacao),
            ];

            $interessados = new InteressadoService();

            $telas['manifestacao/minhas.html.twig'] = [
                'titulo'        => 'Meus interesses',
                'manifestacoes' => $interessados->minhas((int) $registro['man_usu_id']),
            ];

            $daDemanda = $interessados->daDemanda(
                (int) $registro['dem_usu_id'],
                (int) $registro['man_dem_id'],
            );

            $telas['manifestacao/interessados.html.twig'] = [
                'titulo'       => 'Interessados',
                'demanda'      => $daDemanda['demanda'],
                'interessados' => $daDemanda['interessados'],
            ];

            // A confirmação com a prévia do mesmo par candidato/demanda: é o caso em que o
            // snapshot sai praticamente vazio, que é justamente o estado que a tela existe para
            // mostrar antes do envio.
            $servico = new ManifestacaoService();

            $telas['manifestacao/confirmar.html.twig'] = [
                'titulo'  => 'Manifestar interesse',
                'demanda' => $servico->demandaAberta((int) $registro['man_dem_id']),
                'previa'  => $servico->previa(
                    (int) $registro['man_usu_id'],
                    (int) $registro['man_dem_id'],
                ),
            ];
        }
    }

    // A tela de detalhe só existe com registro; num banco recém-carregado não há denúncia.
    if ($uma !== null) {
        $telas['admin/denuncia.html.twig'] = [
            'ativo'        => 'denuncias',
            'denuncia'     => $uma,
            'tipos'        => DenunciaService::TIPOS,
            'situacoes'    => DenunciaService::SITUACOES,
            'providencias' => DenunciaService::PROVIDENCIAS,
        ];
    }

    return $telas;
})();
