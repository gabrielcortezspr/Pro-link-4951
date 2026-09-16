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

use ProLink\Repository\AuditoriaRepository;
use ProLink\Repository\CompatibilizacaoRepository;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\TermoRepository;
use ProLink\Service\BuscaService;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\DemandaService;
use ProLink\Service\DenunciaService;
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
        'admin/index.html.twig' => ['ativo' => 'visao'],

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
