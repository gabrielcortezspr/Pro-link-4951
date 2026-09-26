<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Vitrine;

/** As regras da vitrine de demandas abertas (D89). */
final class VitrineTest extends TestCase
{
    private const PESOS = [0.00, 0.15, 0.40, 0.75, 1.00];

    public function testFiltrosValidosEPadroes(): void
    {
        $f = Vitrine::lerFiltros(
            ['area' => ['11', 'x', '0'], 'uf' => 'am', 'contrato' => 'pj', 'inicio' => '30', 'ordem' => 'inicio', 'pagina' => '2'],
            '2026-09-26', true, true,
        );

        self::assertSame([11], $f['areas']);
        self::assertSame('AM', $f['uf']);
        self::assertSame('PJ', $f['contrato']);
        self::assertSame('ate', $f['inicio']);
        self::assertSame('2026-10-26', $f['ate']);
        self::assertSame('inicio', $f['ordem']);
        self::assertSame(2, $f['pagina']);
        self::assertTrue($f['minha_area'], 'ligado por padrão para quem tem acervo');
        self::assertTrue($f['para_mim']);
    }

    public function testLixoNaUrlEIgnorado(): void
    {
        $f = Vitrine::lerFiltros(['uf' => 'XX', 'contrato' => 'MAGICO', 'inicio' => '999', 'ordem' => 'score', 'pagina' => '-3'], '2026-09-26', false, false);

        self::assertNull($f['uf']);
        self::assertNull($f['contrato']);
        self::assertNull($f['inicio']);
        self::assertSame('recentes', $f['ordem'], 'não existe ordem por compatibilidade');
        self::assertSame(1, $f['pagina']);
        self::assertFalse($f['minha_area'], 'sem acervo não há "minha área"');
    }

    public function testMinhaAreaDesligaPelaUrl(): void
    {
        self::assertFalse(Vitrine::lerFiltros(['minha_area' => '0', 'para' => 'todas'], '2026-09-26', true, true)['minha_area']);
        self::assertFalse(Vitrine::lerFiltros(['para' => 'todas'], '2026-09-26', true, true)['para_mim']);
    }

    public function testRelacaoComAcervo(): void
    {
        self::assertSame('mesma', Vitrine::relacaoComAcervo(['TOS_10.4.2.3'], ['TOS_1.1.5', 'TOS_10.4.2.3'], self::PESOS));
        self::assertSame('proxima', Vitrine::relacaoComAcervo(['TOS_10.4.2.3'], ['TOS_10.4.1'], self::PESOS), 'mesmo subgrupo');
        self::assertNull(Vitrine::relacaoComAcervo(['TOS_10.4.2.3'], ['TOS_10.1.1'], self::PESOS), 'só o grupo não basta');
        self::assertNull(Vitrine::relacaoComAcervo(['TOS_10.4.2.3'], [], self::PESOS));
    }

    public function testAtividadeSemAcentoNemCaixa(): void
    {
        $tos = [['dts_tos_codigo' => 'TOS_1.6.4', 'tos_grupo' => 'Construção Civil', 'tos_subgrupo' => 'Instalações de Prevenção e Combate a Incêndio', 'tos_obra_servico' => 'de localização de sprinkler', 'tos_complementar' => null]];

        self::assertTrue(Vitrine::temAtividade($tos, 'SPRINKLER'));
        self::assertTrue(Vitrine::temAtividade($tos, 'incendio'));
        self::assertTrue(Vitrine::temAtividade($tos, 'tos_1.6'));
        self::assertFalse(Vitrine::temAtividade($tos, 'subestação'));
    }

    public function testAreasContamUmaVezPorDemanda(): void
    {
        $demandas = [
            ['tos' => [['tos_nivel1' => 16, 'tos_grupo' => 'Mecânica'], ['tos_nivel1' => 16, 'tos_grupo' => 'Mecânica'], ['tos_nivel1' => 11, 'tos_grupo' => 'Eletrotécnica']]],
            ['tos' => [['tos_nivel1' => 16, 'tos_grupo' => 'Mecânica']]],
        ];

        self::assertSame(
            [['nivel1' => 11, 'grupo' => 'Eletrotécnica', 'quantidade' => 1], ['nivel1' => 16, 'grupo' => 'Mecânica', 'quantidade' => 2]],
            Vitrine::contarAreas($demandas),
        );
    }

    public function testOrdemPorInicioPoePrazoAbertoNoFim(): void
    {
        $ordenadas = Vitrine::ordenar([
            ['dem_id' => 1, 'dem_inicio_ate' => null],
            ['dem_id' => 2, 'dem_inicio_ate' => '2026-10-30'],
            ['dem_id' => 3, 'dem_inicio_ate' => '2026-10-05'],
        ], 'inicio');

        self::assertSame([3, 2, 1], array_column($ordenadas, 'dem_id'));
    }
}
