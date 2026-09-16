<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\AuditoriaRepository;
use ProLink\Repository\CompatibilizacaoRepository;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\DenunciaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Auditoria;
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
        private readonly CompatibilizacaoRepository $sessoes = new CompatibilizacaoRepository(),
        private readonly CompatibilizacaoService $motor = new CompatibilizacaoService(),
    ) {
    }

    /**
     * As sessões do motor, mais recentes primeiro (RF04 e item 12.3).
     *
     * Cada linha é uma execução da operação atômica 2, com a semente que a ordenou. É por aqui
     * que a supervisão humana que o 12.3 exige deixa de ser uma frase na documentação.
     */
    public function sessoes(): string
    {
        return View::render('admin/sessoes.html.twig', [
            // 'ativo' é estado de navegação da sidebar, não dado: sem ele o item do menu não
            // acende e as duas telas de sessão ficam sem lugar no painel.
            'ativo'   => 'sessoes',
            'titulo'  => 'Sessões do motor',
            'sessoes' => $this->sessoes->recentes(),
        ]);
    }

    /**
     * Uma sessão refeita a partir da semente gravada.
     *
     * O serviço recalcula o sorteio e compara com a ordem que ficou no banco; a tela mostra o
     * resultado dessa comparação. Não é a aplicação afirmando que é reproduzível, é a
     * reprodução acontecendo na frente de quem audita.
     */
    public function sessao(string $id): string
    {
        $reproducao = $this->motor->reproduzir((int) $id);

        if ($reproducao === null) {
            return View::erro(404, 'Sessão de compatibilização não encontrada.');
        }

        return View::render('admin/sessao.html.twig', [
            'ativo'      => 'sessoes',
            'titulo'     => 'Sessão ' . (int) $id,
            'reproducao' => $reproducao,
        ]);
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

    /** Quantos eventos a trilha mostra por página. */
    private const AUDITORIA_POR_PAGINA = 50;

    /**
     * Eventos negativos de segurança: tentativa recusada e divergência de selo. A tela os destaca
     * e conta à parte, porque são o que alguém procura numa trilha e o que se perde no volume.
     */
    private const AUDITORIA_SEGURANCA = [
        Auditoria::ACESSO_NEGADO,
        Auditoria::LOGIN_FALHOU,
        Auditoria::BLOQUEIO_LOGIN,
        Auditoria::SELO_DIVERGENTE,
    ];

    public function auditoria(): string
    {
        $usuarioId = ($_GET['usuario'] ?? '') !== '' ? (int) $_GET['usuario'] : null;

        // Id não positivo é filtro impossível: vira "todas", como qualquer valor fora da faixa.
        if ($usuarioId !== null && $usuarioId < 1) {
            $usuarioId = null;
        }

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

        // Mesmo relógio dos dois lados desde que Database::conexao() alinha o fuso da sessão do
        // MariaDB ao do PHP: este corte compara com aud_dt_registro sem deslocamento.
        $de = date('Y-m-d H:i:s', strtotime("-{$dias} days"));

        $total   = $this->auditoria->contar($usuarioId, $acao, $de, null);
        $paginas = max(1, (int) ceil($total / self::AUDITORIA_POR_PAGINA));

        // Página fora da faixa vira a primeira, em silêncio: o deslocamento é parâmetro de SQL,
        // e a regra da casa é que valor inválido não chega lá.
        $pagina = (int) ($_GET['pagina'] ?? 1);

        if ($pagina < 1 || $pagina > $paginas) {
            $pagina = 1;
        }

        // A contagem respeita o filtro corrente, inclusive o de ação: assim o número do topo é
        // sempre "destes que estão sendo mostrados, tantos são de segurança", sem escopo oculto.
        $criticas = $acao === null
            ? self::AUDITORIA_SEGURANCA
            : array_values(array_intersect(self::AUDITORIA_SEGURANCA, [$acao]));

        $seguranca = 0;

        foreach ($criticas as $critica) {
            $seguranca += $this->auditoria->contar($usuarioId, $critica, $de, null);
        }

        return View::render('admin/auditoria.html.twig', [
            'ativo'  => 'auditoria',
            'linhas' => $this->auditoria->listar(
                $usuarioId,
                $acao,
                $de,
                null,
                self::AUDITORIA_POR_PAGINA,
                ($pagina - 1) * self::AUDITORIA_POR_PAGINA,
            ),
            'total'       => $total,
            'acoes'       => $acoes,
            'filtro'      => ['usuario' => $usuarioId, 'acao' => $acao, 'dias' => $dias],
            'pagina'      => $pagina,
            'paginas'     => $paginas,
            'por_pagina'  => self::AUDITORIA_POR_PAGINA,
            'seguranca'   => $seguranca,
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
