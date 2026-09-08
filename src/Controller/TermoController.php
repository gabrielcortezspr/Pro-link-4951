<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Repository\TermoRepository;
use ProLink\Support\View;

/**
 * Exibe os Termos de Uso e a Política de Privacidade vigentes.
 *
 * Rota pública: o titular precisa poder ler o que vai aceitar antes de ter conta, e precisa
 * poder reler depois sem entrar. O texto vem versionado de sis_termos — ver TermoRepository.
 */
final class TermoController
{
    public function __construct(private readonly TermoRepository $termos = new TermoRepository())
    {
    }

    public function uso(): string
    {
        return $this->exibir('USO', 'Termos de Uso');
    }

    public function privacidade(): string
    {
        return $this->exibir('PRIVACIDADE', 'Política de Privacidade');
    }

    private function exibir(string $tipo, string $titulo): string
    {
        $vigentes = $this->termos->vigentes();

        if (!isset($vigentes[$tipo])) {
            return View::erro(404, 'Este documento ainda não foi publicado.');
        }

        return View::render('termos.html.twig', ['titulo' => $titulo, 'termo' => $vigentes[$tipo]]);
    }
}
