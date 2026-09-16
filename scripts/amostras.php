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
use ProLink\Repository\DemandaRepository;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\DemandaService;
use ProLink\Service\DenunciaService;
use ProLink\Service\PerfilService;
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

    $telas['demanda/nova.html.twig'] = [
        'titulo'  => 'Nova demanda',
        'valores' => [],
        'erros'   => [],
    ] + $vocabulario;

    $telas['demanda/abertas.html.twig'] = [
        'titulo'   => 'Demandas abertas',
        'demandas' => (new DemandaRepository())->abertas(),
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
