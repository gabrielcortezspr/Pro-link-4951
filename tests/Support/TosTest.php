<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProLink\Support\Tos;

final class TosTest extends TestCase
{
    private const PESOS = [0 => 0.00, 1 => 0.15, 2 => 0.40, 3 => 0.75, 4 => 1.00];

    public function testNiveisDecompoeOCodigoEmInteiros(): void
    {
        self::assertSame([11, 10, 1, 4], Tos::niveis('TOS_11.10.1.4'));
        self::assertSame([1, 1, 6], Tos::niveis('TOS_1.1.6'));
        self::assertSame([2, 9, 2, 3], Tos::niveis('2.9.2.3'), 'aceita sem o prefixo');
    }

    public function testOrdenarUsaHierarquiaNumericaNaoTexto(): void
    {
        $ordenado = Tos::ordenar(['TOS_10.1.1', 'TOS_2.1.1', 'TOS_1.1.2.1', 'TOS_1.1.2']);

        self::assertSame(['TOS_1.1.2', 'TOS_1.1.2.1', 'TOS_2.1.1', 'TOS_10.1.1'], $ordenado);
    }

    /** A tabela de docs/matching.md, etapa 3. */
    #[DataProvider('casosDeAfinidade')]
    public function testAfinidadeSegueATabelaDoMotor(string $demanda, string $acervo, float $esperado): void
    {
        self::assertSame($esperado, Tos::afinidade($demanda, $acervo, self::PESOS));
    }

    public static function casosDeAfinidade(): iterable
    {
        yield 'mesma atividade, mesmo material' => ['TOS_1.1.2.1', 'TOS_1.1.2.1', 1.00];
        yield 'mesma obra, material diferente'  => ['TOS_1.1.2.1', 'TOS_1.1.2.5', 0.75];
        yield 'mesmo subgrupo'                  => ['TOS_1.1.2.1', 'TOS_1.1.1.1', 0.40];
        yield 'só o grupo'                      => ['TOS_1.1.2.1', 'TOS_1.4.3',   0.15];
        yield 'áreas distintas'                 => ['TOS_1.1.2.1', 'TOS_11.10.1.4', 0.00];
        yield 'três níveis iguais, tamanhos diferentes' => ['TOS_1.1.6', 'TOS_1.1.6.2', 0.75];
        yield 'três níveis, coincidência total' => ['TOS_1.1.6', 'TOS_1.1.6', 1.00];
    }

    public function testAfinidadeEhSimetrica(): void
    {
        self::assertSame(
            Tos::afinidade('TOS_1.1.6', 'TOS_1.1.6.2', self::PESOS),
            Tos::afinidade('TOS_1.1.6.2', 'TOS_1.1.6', self::PESOS),
        );
    }

    public function testNormalizarRemoveAcentoECaixa(): void
    {
        self::assertSame('amazonia construcoes', Tos::normalizar('AMAZÔNIA Construções'));
        self::assertSame('de instalacoes eletricas', Tos::normalizar('  de instalações elétricas '));
        self::assertSame('quimica', Tos::normalizar('Química'));
    }
}
