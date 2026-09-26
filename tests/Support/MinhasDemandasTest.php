<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\MinhasDemandas;

final class MinhasDemandasTest extends TestCase
{
    private static function demanda(int $id, ?string $publicacao, string $situacao = 'ABERTA', array $sobre = []): array
    {
        return $sobre + [
            'dem_id' => $id, 'dem_titulo' => 'Demanda ' . $id, 'dem_escopo' => '', 'dem_local_municipio' => 'Manaus',
            'dem_dt_publicacao' => $publicacao, 'dem_situacao' => $situacao, 'dem_inicio_ate' => null,
            'tos' => [], 'painel' => ['pendentes' => 0],
        ];
    }

    public function testSituacaoDeCadaDemanda(): void
    {
        self::assertSame('rascunhos', MinhasDemandas::situacao(self::demanda(1, null)));
        self::assertSame('abertas', MinhasDemandas::situacao(self::demanda(2, '2026-09-01', 'COM_INTERESSADOS')));
        self::assertSame('encerradas', MinhasDemandas::situacao(self::demanda(3, '2026-09-01', 'ENCERRADA')));
    }

    public function testContagemDasAbasEhSobreAListaInteira(): void
    {
        $lista = [
            self::demanda(1, null), self::demanda(2, '2026-09-01'), self::demanda(3, '2026-09-01'),
            self::demanda(4, '2026-09-01', 'ENCERRADA'),
        ];

        self::assertSame(
            ['abertas' => 2, 'rascunhos' => 1, 'encerradas' => 1, 'todas' => 4],
            MinhasDemandas::contarSituacoes($lista),
        );
    }

    public function testFiltrosInvalidosCaemNoPadrao(): void
    {
        $f = MinhasDemandas::lerFiltros(['situacao' => 'apagadas', 'ordem' => 'melhor', 'area' => ['16', 'x', '-2'], 'q' => '  ']);

        self::assertSame('abertas', $f['situacao']);
        self::assertSame('recentes', $f['ordem']);
        self::assertSame([16], $f['areas']);
        self::assertNull($f['texto']);
        self::assertFalse($f['pendentes']);
        self::assertTrue(MinhasDemandas::lerFiltros(['pendentes' => '1'])['pendentes']);
    }

    public function testBuscaSemAcentoEPelaAtividade(): void
    {
        $d = self::demanda(1, '2026-09-01', 'ABERTA', [
            'dem_titulo' => 'Galpão logístico',
            'tos' => [['dts_tos_codigo' => 'TOS_1.6.6', 'tos_grupo' => 'Instalações', 'tos_subgrupo' => '',
                       'tos_obra_servico' => 'de prevenção e combate a incêndio', 'tos_complementar' => null]],
        ]);

        self::assertTrue(MinhasDemandas::bate($d, 'galpao'));
        self::assertTrue(MinhasDemandas::bate($d, 'INCENDIO'));
        self::assertTrue(MinhasDemandas::bate($d, 'TOS_1.6.6'));
        self::assertFalse(MinhasDemandas::bate($d, 'piscicultura'));
    }

    public function testOrdemPorPendenciaDesempataPelaMaisRecente(): void
    {
        $lista = [
            self::demanda(1, '2026-09-01', 'ABERTA', ['painel' => ['pendentes' => 2]]),
            self::demanda(2, '2026-09-01', 'ABERTA', ['painel' => ['pendentes' => 0]]),
            self::demanda(3, '2026-09-01', 'ABERTA', ['painel' => ['pendentes' => 2]]),
        ];

        self::assertSame([3, 1, 2], array_column(MinhasDemandas::ordenar($lista, 'pendencias'), 'dem_id'));
        self::assertSame([3, 2, 1], array_column(MinhasDemandas::ordenar($lista, 'recentes'), 'dem_id'));
    }

    public function testDemandaEncerradaNaoEsperaNada(): void
    {
        $encerrada = self::demanda(1, '2026-09-01', 'ENCERRADA', ['painel' => ['pendentes' => 3]]);
        $aberta    = self::demanda(2, '2026-09-01', 'ABERTA', ['painel' => ['pendentes' => 1]]);

        self::assertFalse(MinhasDemandas::temPendencia($encerrada));
        self::assertTrue(MinhasDemandas::temPendencia($aberta));
        self::assertSame([2, 1], array_column(MinhasDemandas::ordenar([$encerrada, $aberta], 'pendencias'), 'dem_id'));
    }
}
