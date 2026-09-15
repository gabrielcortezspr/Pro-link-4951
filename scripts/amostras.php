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
use ProLink\Support\Rotulos;

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
            'execucoes'  => (new CompatibilizacaoService())->execucoesDa((int) $primeiraDemandaId),
        ] + $vocabulario;
    }

    // Feed de compatíveis (E4). Lê pelo mesmo caminho do controller — `sessao()` —, e não com
    // dado montado à mão: a amostra tem de conferir o que a tela recebe de verdade, com o pool na
    // ordem gravada, os pesos da execução e a contagem de ocultos. É a tela com mais valor cru
    // por centímetro do projeto, e a única em que código TOS, chave de contrato e nome de
    // dimensão convivem na mesma página.
    $ultima = $pdo
        ->query("SELECT mts_id, mts_dem_id FROM mat_sessoes WHERE mts_status = 'A' ORDER BY mts_id DESC LIMIT 1")
        ->fetch();

    if ($ultima !== false) {
        $motor    = new CompatibilizacaoService();
        $daBusca  = (new DemandaService())->comTos((int) $ultima['mts_dem_id']);
        $execucao = $daBusca === null
            ? null
            : $motor->sessao((int) $ultima['mts_id'], (int) $daBusca['dem_usu_id']);

        if ($execucao !== null) {
            $telas['demanda/candidatos.html.twig'] = [
                'titulo'    => 'Perfis compatíveis · ' . $daBusca['dem_titulo'],
                'demanda'   => $daBusca,
                'sessao'    => $execucao['sessao'],
                'pesos'     => $execucao['pesos'],
                'pool'      => $execucao['pool'],
                'ocultos'   => $execucao['ocultos'],
                'execucoes' => $motor->execucoesDa((int) $daBusca['dem_id']),
                'dimensoes_rotulos' => Rotulos::DIMENSOES,
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
