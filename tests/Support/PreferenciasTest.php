<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Compatibilidade;
use ProLink\Support\Preferencias;

/**
 * O vocabulário das duas dimensões autodeclaradas.
 *
 * O caso que dá nome a este arquivo é o último: **texto que o motor não sabe ler devolve null, não
 * zero**. Era esse o defeito — a abrangência era texto livre, a comparação com a UF da demanda
 * nunca casava, e a dimensão afirmava "não atende" sobre todo mundo que tinha preenchido o campo.
 */
final class PreferenciasTest extends TestCase
{
    public function testNormalizaListaDeUfEmOrdemCanonica(): void
    {
        // Mesma escolha, três grafias: a coluna tem de guardar a mesma string nas três, senão
        // duas sessões de compatibilização gravadas ficam incomparáveis.
        self::assertSame('AM,RR', Preferencias::normalizarAbrangencia('RR,AM'));
        self::assertSame('AM,RR', Preferencias::normalizarAbrangencia(' rr , am '));
        self::assertSame('AM,RR', Preferencias::normalizarAbrangencia(['AM', 'RR', 'AM']));
    }

    public function testQualquerAbsorveAListaInteira(): void
    {
        self::assertSame(
            Preferencias::QUALQUER,
            Preferencias::normalizarAbrangencia(['AM', 'QUALQUER']),
        );
    }

    public function testDescartaOQueNaoEUf(): void
    {
        self::assertNull(Preferencias::normalizarAbrangencia('Manaus e região metropolitana'));
        self::assertNull(Preferencias::normalizarAbrangencia(''));
        self::assertNull(Preferencias::normalizarAbrangencia(null));
        self::assertSame('AM', Preferencias::normalizarAbrangencia('AM, ZZ, 99'));
    }

    public function testCoberturaPorUf(): void
    {
        self::assertTrue(Preferencias::abrangenciaCobre('AM,RR', 'AM'));
        self::assertTrue(Preferencias::abrangenciaCobre('AM,RR', 'rr'));
        self::assertFalse(Preferencias::abrangenciaCobre('AM,RR', 'SP'));
        self::assertTrue(Preferencias::abrangenciaCobre(Preferencias::QUALQUER, 'SP'));
    }

    public function testSemDadoDeQualquerLadoNaoDaOpiniao(): void
    {
        self::assertNull(Preferencias::abrangenciaCobre(null, 'AM'));
        self::assertNull(Preferencias::abrangenciaCobre('', 'AM'));
        self::assertNull(Preferencias::abrangenciaCobre('AM', null));
        self::assertNull(Preferencias::abrangenciaCobre('AM', ''));
    }

    /**
     * O defeito, em forma de asserção: campo com texto que o vocabulário não reconhece sai da
     * média. Se um dia isto voltar a devolver 0.0, a dimensão volta a punir quem preencheu.
     */
    public function testTextoLivreAntigoSaiDaMediaEmVezDePunir(): void
    {
        self::assertNull(Preferencias::abrangenciaCobre('Manaus e região metropolitana', 'AM'));
        self::assertNull(Compatibilidade::abrangencia('AM', 'Manaus e região metropolitana'));
    }

    public function testAbrangenciaViraNotaDeDimensao(): void
    {
        self::assertSame(1.0, Compatibilidade::abrangencia('AM', 'AM,RR'));
        self::assertSame(1.0, Compatibilidade::abrangencia('SP', Preferencias::QUALQUER));
        self::assertSame(0.0, Compatibilidade::abrangencia('SP', 'AM,RR'));
        self::assertNull(Compatibilidade::abrangencia(null, 'AM,RR'));
        self::assertNull(Compatibilidade::abrangencia('AM', null));
    }

    public function testContratoSoAceitaChaveDoVocabulario(): void
    {
        self::assertTrue(Preferencias::contratoValido('CLT'));
        self::assertTrue(Preferencias::contratoValido(Preferencias::QUALQUER));
        self::assertFalse(Preferencias::contratoValido('clt'));
        self::assertFalse(Preferencias::contratoValido('Carteira assinada'));
        self::assertFalse(Preferencias::contratoValido(null));
        self::assertFalse(Preferencias::contratoValido(''));
    }

    public function testAbrangenciaCabeNaColuna(): void
    {
        // Todas as 27 UFs é o pior caso que o formulário permite gravar.
        $tudo = Preferencias::normalizarAbrangencia(Preferencias::UFS);

        self::assertNotNull($tudo);
        self::assertLessThanOrEqual(Preferencias::ABRANGENCIA_TAMANHO_MAXIMO, strlen($tudo));
    }
}
