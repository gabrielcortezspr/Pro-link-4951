<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Modalidade;

/**
 * O mapa de modalidade para grupo TOS é nosso, não do CREA, e a decisão que ele carrega é a de
 * não afirmar limite de atribuição profissional onde não temos a resolução em mãos. Estes testes
 * travam justamente isso: o que está mapeado, e o que fica de fora de propósito.
 */
final class ModalidadeTest extends TestCase
{
    public function testCorrespondenciaDiretaPeloNome(): void
    {
        self::assertSame([14], Modalidade::gruposDe(['Engenharia de Computação']));
        self::assertSame([16], Modalidade::gruposDe(['Engenharia Mecânica']));
        self::assertSame([41], Modalidade::gruposDe(['Meteorologia']));
    }

    public function testAcentoECaixaNaoSeparam(): void
    {
        self::assertSame(
            Modalidade::gruposDe(['Engenharia Mecânica']),
            Modalidade::gruposDe(['ENGENHARIA MECANICA']),
        );
    }

    public function testModalidadeAmplaCobreVariosGrupos(): void
    {
        $civil = Modalidade::gruposDe(['Engenharia Civil']);

        self::assertContains(1, $civil, 'Construção Civil');
        self::assertContains(2, $civil, 'Estruturas');
        self::assertNotContains(14, $civil, 'Computação não é atribuição de Engenharia Civil');
    }

    public function testVariasModalidadesSomamSemRepetir(): void
    {
        $duas = Modalidade::gruposDe(['Engenharia Elétrica', 'Engenharia Eletrônica']);

        // Elétrica traz 11, 12 e 15; Eletrônica traz 12. O 12 não pode aparecer duas vezes.
        self::assertSame([11, 12, 15], $duas);
    }

    public function testModalidadeForaDoMapaNaoContribuiENaoAtrapalha(): void
    {
        self::assertSame([], Modalidade::gruposDe(['Engenharia de Petróleo']));
        self::assertFalse(Modalidade::conhecida('Engenharia de Petróleo'));

        // Junto de uma conhecida, a desconhecida apenas não acrescenta.
        self::assertSame([16], Modalidade::gruposDe(['Engenharia de Petróleo', 'Engenharia Mecânica']));
    }

    public function testSemModalidadeNenhumaDevolveListaVaziaEADimensaoSaiDaMedia(): void
    {
        self::assertSame([], Modalidade::gruposDe([]));
        self::assertSame([], Modalidade::gruposDe(['Profissão que não existe']));
    }
}
