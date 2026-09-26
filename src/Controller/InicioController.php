<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\InicioService;
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
 * ## Três perguntas, e não indicadores (D90)
 *
 * O que eu preciso fazer agora, o que está acontecendo no meu trabalho e o que aconteceu. Quem
 * monta as três é o `InicioService`; aqui só se escolhe o template do papel.
 */
final class InicioController
{
    public function __construct(
        private readonly InicioService $inicio = new InicioService(),
    ) {
    }

    public function index(): string
    {
        $usuarioId = (int) Sessao::usuarioId();
        $perfil    = Sessao::usuarioAtual()['perfil'] ?? '';

        if ($perfil === PERFIL_ADMIN) {
            View::redirecionar('/admin');
        }

        // Dois templates porque são dois papéis (D90): o profissional só se candidata; a empresa
        // e o Terceiro publicam, e a empresa também se candidata.
        return View::render(
            $perfil === PERFIL_PROFISSIONAL ? 'inicio/profissional.html.twig' : 'inicio/demandante.html.twig',
            ['titulo' => 'Início', 'inicio' => $this->inicio->montar($usuarioId, $perfil)],
        );
    }
}
