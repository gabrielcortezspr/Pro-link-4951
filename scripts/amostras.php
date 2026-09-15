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
use ProLink\Service\DenunciaService;

return (static function (): array {
    $auditoria = new AuditoriaRepository();
    $denuncias = new DenunciaService();

    $fila = $denuncias->fila(null);
    $uma  = $fila !== [] ? $denuncias->porId((int) $fila[0]['den_id']) : null;

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
