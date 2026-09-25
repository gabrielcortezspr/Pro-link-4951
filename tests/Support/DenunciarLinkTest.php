<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\View;

/**
 * O link "Denunciar" (D84): quem o vê e para onde ele aponta.
 *
 * Renderiza a macro de `layout/_ui.html.twig` pelo mesmo motor da aplicação, com a sessão montada
 * em $_SESSION como o SessaoFlashTest faz. Não toca banco: a macro só lê a sessão. Que o link
 * aparece na tela certa e abre o formulário já com o alvo é a suíte de ponta a ponta que prova.
 */
final class DenunciarLinkTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    private function renderizar(string $entidade, int $alvo, bool $dono = false): string
    {
        return View::motor()->createTemplate(
            "{% import 'layout/_ui.html.twig' as ui %}"
            . '{{ ui.denunciar(entidade, alvo, o_que, dono) }}'
        )->render(['entidade' => $entidade, 'alvo' => $alvo, 'o_que' => 'este perfil', 'dono' => $dono]);
    }

    public function testAnonimoNaoVeOLink(): void
    {
        // A rota é de PERFIS_AUTENTICADOS: oferecer o link a quem não entrou é mandá-lo a um 401.
        self::assertSame('', trim($this->renderizar('USUARIO', 42)));
    }

    public function testQuemTemContaVeOLinkApontandoParaOAlvo(): void
    {
        $_SESSION['usuario'] = ['id' => 7, 'nome' => 'Ana', 'perfil' => PERFIL_PROFISSIONAL];

        $html = $this->renderizar('USUARIO', 42);

        self::assertStringContainsString(
            'href="' . APP_URL . '/denuncias/nova?entidade=USUARIO&amp;alvo=42"',
            $html,
        );
        self::assertStringContainsString('Denunciar', $html);
        self::assertStringContainsString('visually-hidden"> este perfil', $html);
    }

    public function testDemandaApontaParaAEntidadeDemanda(): void
    {
        $_SESSION['usuario'] = ['id' => 7, 'nome' => 'Ana', 'perfil' => PERFIL_PROFISSIONAL];

        self::assertStringContainsString(
            '/denuncias/nova?entidade=DEMANDA&amp;alvo=315"',
            $this->renderizar('DEMANDA', 315),
        );
    }

    public function testDonoDoAlvoNaoVeOLink(): void
    {
        $_SESSION['usuario'] = ['id' => 42, 'nome' => 'Ana', 'perfil' => PERFIL_PROFISSIONAL];

        self::assertSame('', trim($this->renderizar('USUARIO', 42, true)));
    }

    public function testAsTresTelasUsamAMacro(): void
    {
        // Sem esta conferência, uma tela nova de perfil (ou uma reescrita desta) perde o ponto de
        // entrada em silêncio, e o cenário 5 volta a depender do endereço digitado.
        $telas = [
            'perfil/index.html.twig'   => "ui.denunciar('USUARIO', p.usuario_id",
            'perfil/empresa.html.twig' => "ui.denunciar('USUARIO', p.usuario_id",
            'demanda/ver.html.twig'    => "ui.denunciar('DEMANDA', d.dem_id",
        ];

        foreach ($telas as $tela => $chamada) {
            self::assertStringContainsString(
                $chamada,
                (string) file_get_contents(PATH_TEMPLATES . '/' . $tela),
                $tela . ' perdeu o link Denunciar',
            );
        }
    }
}
