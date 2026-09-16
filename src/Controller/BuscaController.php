<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Service\BuscaService;
use ProLink\Support\Sessao;
use ProLink\Support\View;

/**
 * A busca ativa de profissionais (RF04; Anexo I item 3, capacidade do perfil Público).
 *
 * **É a única tela da aplicação aberta a quem não tem conta**, e isso é exigência: o edital dá
 * "pesquisar profissionais (especialidade, experiência, nome); ver perfil" ao perfil Público. O
 * que muda para o anônimo não é o acesso, é o alcance — `Visibilidade::alcanceDe()` entrega a ele
 * só o que cada titular marcou como público, e o serviço filtra campo a campo.
 *
 * Tudo em GET, sem escrita: a busca não cria sessão de compatibilização, não grava nada e é
 * repetível. Quem quiser o pool explicado de uma demanda usa o feed, que é o outro caminho.
 */
final class BuscaController
{
    public function __construct(
        private readonly BuscaService $busca = new BuscaService(),
    ) {
    }

    public function profissionais(): string
    {
        // Sessao::usuarioId() é null para anônimo, que é exatamente o que o serviço espera: o
        // espectador nulo já significa "não autenticado" em toda a camada de visibilidade.
        $espectador = Sessao::usuarioId();

        $termo = trim((string) ($_GET['q'] ?? ''));

        // Caixa de seleção: ausente é desmarcado. O filtro nasce desligado porque é o único que
        // esconde alguém por atributo do perfil, e quem está começando é quem ele esconde.
        $emConstrucao = ($_GET['em_construcao'] ?? '') === '1';

        return View::render('busca/profissionais.html.twig', [
            'titulo' => 'Buscar profissionais',
            'busca'  => $this->busca->profissionais($termo, $emConstrucao, $espectador),
        ]);
    }
}
