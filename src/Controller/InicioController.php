<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\DashboardRepository;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * O "Início" de quem está logado, como os mockups do profissional e da empresa desenharam.
 *
 * ## Por que não é a landing
 *
 * `/` é a página pública: hero, busca sem conta, "como funciona". Quem já entrou não precisa do
 * discurso de venda, precisa do estado da própria operação. Nos mockups isso é a primeira entrada
 * da barra lateral, e é o que esta rota serve.
 *
 * ## Dois painéis, porque são dois papéis
 *
 * O profissional pergunta "onde eu apareço e o que enviei"; a empresa pergunta "o que publiquei e
 * quem chegou". Terceiro é demandante sem registro no CREA, e vê o painel da empresa sem a parte
 * de acervo, que ele não tem.
 */
final class InicioController
{
    private const RECENTES = 5;

    public function __construct(
        private readonly DashboardRepository $dashboard = new DashboardRepository(),
        private readonly DemandaRepository $demandas = new DemandaRepository(),
        private readonly ManifestacaoRepository $manifestacoes = new ManifestacaoRepository(),
    ) {
    }

    public function index(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $perfil    = Sessao::usuarioAtual()['perfil'] ?? '';

        if ($perfil === PERFIL_ADMIN) {
            View::redirecionar('/admin');
        }

        return $perfil === PERFIL_PROFISSIONAL
            ? $this->doProfissional($usuarioId)
            : $this->doDemandante($usuarioId, $perfil);
    }

    /**
     * O início do profissional: onde ele aparece, o que enviou, e o que o acervo dele sustenta.
     */
    private function doProfissional(int $usuarioId): string
    {
        return View::render('inicio/profissional.html.twig', [
            'titulo'    => 'Início',
            'numeros'   => $this->dashboard->doProfissional($usuarioId),
            // As demandas abertas mais recentes, que é o que o mockup mostra na tabela do painel.
            'demandas'  => array_slice($this->demandas->abertas(self::RECENTES * 2), 0, self::RECENTES),
            'enviadas'  => array_slice($this->manifestacoes->doUsuario($usuarioId), 0, self::RECENTES),
        ]);
    }

    /** O início de quem publica demanda: empresa e Terceiro. */
    private function doDemandante(int $usuarioId, string $perfil): string
    {
        return View::render('inicio/demandante.html.twig', [
            'titulo'      => 'Início',
            'numeros'     => $this->dashboard->daEmpresa($usuarioId),
            'demandas'    => array_slice($this->demandas->doUsuario($usuarioId), 0, self::RECENTES),
            // Terceiro não tem registro no CREA, e portanto não tem acervo nem quadro técnico: a
            // tela precisa saber disso para não mostrar dois tiles que sempre valeriam zero.
            'tem_registro' => $perfil === PERFIL_EMPRESA,
        ]);
    }
}
