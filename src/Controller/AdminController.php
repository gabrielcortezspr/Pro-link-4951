<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\AuditoriaRepository;
use ProLink\Service\DenunciaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Flash;
use ProLink\Support\Sessao;
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
        private readonly AuditoriaRepository $auditoria = new AuditoriaRepository(),
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

    public function denuncia(string $id): string
    {
        $denuncia = $this->denuncias->porId((int) $id);

        if ($denuncia === null) {
            return View::erro(404, 'Denúncia não encontrada.');
        }

        return View::render('admin/denuncia.html.twig', [
            'ativo'        => 'denuncias',
            'denuncia'     => $denuncia,
            'tipos'        => DenunciaService::TIPOS,
            'situacoes'    => DenunciaService::SITUACOES,
            'providencias' => DenunciaService::PROVIDENCIAS,
        ]);
    }

    public function auditoria(): string
    {
        $usuarioId = ($_GET['usuario'] ?? '') !== '' ? (int) $_GET['usuario'] : null;

        $acao   = ($_GET['acao'] ?? '') !== '' ? (string) $_GET['acao'] : null;
        $acoes  = $this->auditoria->acoesDistintas();

        // Mesma regra do filtro da fila: valor fora da lista vira "todas", nunca chega ao SQL.
        if ($acao !== null && !in_array($acao, $acoes, true)) {
            $acao = null;
        }

        $dias = (int) ($_GET['dias'] ?? 7);

        if (!in_array($dias, [7, 30, 90], true)) {
            $dias = 7;
        }

        // aud_dt_registro vem do NOW() do MariaDB (UTC) e este date() é America/Manaus: o corte
        // sai 4h atrasado. Não muda resultado num filtro de dias, mas o acerto é item de 15/09.
        $de = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

        return View::render('admin/auditoria.html.twig', [
            'ativo'  => 'auditoria',
            'linhas' => $this->auditoria->listar($usuarioId, $acao, $de, null),
            'total'  => $this->auditoria->contar($usuarioId, $acao, $de, null),
            'acoes'  => $acoes,
            'filtro' => ['usuario' => $usuarioId, 'acao' => $acao, 'dias' => $dias],
        ]);
    }

    public function tratar(string $id): string
    {
        try {
            $r = $this->denuncias->tratar(
                (int) $id,
                (int) Sessao::usuarioId(),
                (string) ($_POST['situacao'] ?? ''),
                ($_POST['providencia'] ?? '') ?: null,
            );
        } catch (ValidacaoException $e) {
            Flash::erro($e->getMessage());
            View::redirecionar('/admin/denuncias/' . (int) $id);
        }

        Flash::sucesso($r['bloqueado']
            ? 'Denúncia tratada e conta bloqueada. Sessões encerradas: ' . $r['sessoes_derrubadas'] . '.'
            : 'Denúncia tratada.');

        View::redirecionar('/admin/denuncias');
    }
}
