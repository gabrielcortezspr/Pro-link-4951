<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Rotulos;

/**
 * A caixa de título de exibição.
 *
 * A conversão é só de tela: o banco continua com o que a API oficial devolveu. O que estes testes
 * fixam é **onde ela não deve agir**, que é a parte fácil de errar.
 */
final class RotulosNomeProprioTest extends TestCase
{
    public function testConverteNomeQueVemTodoEmCaixaAlta(): void
    {
        self::assertSame('João Miguel Santos', Rotulos::nomeProprio('JOÃO MIGUEL SANTOS'));
    }

    public function testMantemAFormaJuridicaComoORegistroEscreve(): void
    {
        self::assertSame(
            'Construtora Manauara LTDA',
            Rotulos::nomeProprio('CONSTRUTORA MANAUARA LTDA'),
        );
    }

    public function testAbaixaALigacaoSoQuandoElaNaoAbreONome(): void
    {
        self::assertSame(
            'Alfa Engenharia e Consultoria LTDA',
            Rotulos::nomeProprio('ALFA ENGENHARIA E CONSULTORIA LTDA'),
        );
    }

    /**
     * Quem escreveu em caixa mista escreveu de propósito, e reescrever seria corrigir quem estava
     * certo: "d'Ávila" viraria "D'ávila".
     */
    public function testNaoMexeEmNomeQueNaoEstaTodoEmCaixaAlta(): void
    {
        self::assertSame("Marina d'Ávila", Rotulos::nomeProprio("Marina d'Ávila"));
        self::assertSame('Pedro McKenna', Rotulos::nomeProprio('Pedro McKenna'));
    }

    public function testAceitaVazioENulo(): void
    {
        self::assertSame('', Rotulos::nomeProprio(''));
        self::assertSame('', Rotulos::nomeProprio(null));
        self::assertSame('', Rotulos::nomeProprio('   '));
    }
}
