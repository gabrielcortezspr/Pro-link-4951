<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\DenunciaService;
use ProLink\Support\View;

/**
 * Painel administrativo (RF06): moderação e auditoria.
 *
 * Todas as rotas daqui já entram sob PERFIL_ADMIN no front controller, então nenhum método
 * reconfere perfil: a autorização é da rota, não do controller, e duplicá-la criaria dois
 * lugares para manter em sincronia.
 */
final class AdminController
{
    public function __construct(
        private readonly DenunciaService $denuncias = new DenunciaService(),
    ) {
    }

    public function index(): string
    {
        return View::render('admin/index.html.twig', ['ativo' => 'visao']);
    }

    public function denuncias(): string
    {
        $situacao = ($_GET['situacao'] ?? '') !== ''
            ? strtoupper((string) $_GET['situacao'])
            : null;

        // Filtro adulterado na query string vira "todas", em silêncio: só valor da lista
        // fechada chega ao SQL.
        if ($situacao !== null && !in_array($situacao, DenunciaService::SITUACOES, true)) {
            $situacao = null;
        }

        return View::render('admin/denuncias.html.twig', [
            'ativo'     => 'denuncias',
            'fila'      => $this->denuncias->fila($situacao),
            'situacao'  => $situacao,
            'situacoes' => DenunciaService::SITUACOES,
            'tipos'     => DenunciaService::TIPOS,
        ]);
    }
}
