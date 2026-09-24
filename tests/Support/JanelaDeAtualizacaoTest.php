<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use ProLink\Support\JanelaDeAtualizacao as Janela;

/** A janela do botão "Atualizar meu acervo no CREA" (D77). */
final class JanelaDeAtualizacaoTest extends TestCase
{
    private static function em(string $hora): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-26 ' . $hora);
    }

    public function testSemTentativaAnteriorEstaLiberado(): void
    {
        self::assertNull(Janela::liberadaEm(null, null, 60, 5, self::em('10:00')));
    }

    public function testDepoisDeConcluidaEsperaAJanelaLonga(): void
    {
        $libera = Janela::liberadaEm(self::em('10:00'), Janela::CONCLUIDA, 60, 5, self::em('10:20'));

        self::assertEquals(self::em('11:00'), $libera);
        self::assertNull(Janela::liberadaEm(self::em('10:00'), Janela::CONCLUIDA, 60, 5, self::em('11:00')));
    }

    public function testDepoisDeFalhaEsperaSoAJanelaCurta(): void
    {
        self::assertEquals(
            self::em('10:05'),
            Janela::liberadaEm(self::em('10:00'), Janela::FALHOU, 60, 5, self::em('10:01')),
        );
        self::assertNull(Janela::liberadaEm(self::em('10:00'), Janela::FALHOU, 60, 5, self::em('10:06')));
    }

    /** Na dúvida, protege a API: resultado desconhecido vale como concluído. */
    public function testResultadoDesconhecidoUsaAJanelaLonga(): void
    {
        self::assertEquals(
            self::em('11:00'),
            Janela::liberadaEm(self::em('10:00'), 'algo', 60, 5, self::em('10:10')),
        );
    }
}
