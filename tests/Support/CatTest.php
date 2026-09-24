<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Cat;

/**
 * As regras da CAT, sem banco e sem rede: projeção, conferência de titularidade, ARTs agrupadas,
 * selo e vigência (D76).
 *
 * A estrutura é conferida contra as capturas reais de `fixtures/`, e não contra literal escrito
 * aqui: se a API mudar de formato e a fixture for recapturada, é este teste que avisa.
 */
final class CatTest extends TestCase
{
    private const RNP = '0412340011';
    private const CAT = '999001/2026';

    /** @return array<string, mixed>|list<mixed> */
    private static function fixture(string $nome): array
    {
        $bruto = file_get_contents(PATH_FIXTURES . '/' . $nome . '.json');
        self::assertIsString($bruto);

        return json_decode($bruto, true, flags: JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    private static function detalhe(): array
    {
        return self::fixture('cat_validacao')[0];
    }

    public function testProjecaoUsaORnpDeQuemPediuETodosOsCamposDaCertidao(): void
    {
        $daLista = self::fixture('profissional_cats')['data'][0];
        $linha   = Cat::projetar($daLista, self::RNP);

        self::assertSame([
            'cat_numero'      => self::CAT,
            'cat_pro_rnp'     => self::RNP,
            'cat_tipo'        => 'INICIAL',
            'cat_dt_emissao'  => '2026-01-15',
            'cat_dt_validade' => '2026-12-31',
            'cat_finalidade'  => 'Licitação',
        ], $linha);
    }

    public function testListaEDetalheProjetamAMesmaLinha(): void
    {
        $daLista = self::fixture('profissional_cats')['data'][0];

        self::assertSame(Cat::projetar($daLista, self::RNP), Cat::projetar(self::detalhe(), self::RNP));
    }

    public function testDetalheSoPertenceAQuemPediu(): void
    {
        self::assertTrue(Cat::pertence(self::detalhe(), self::RNP, self::CAT));
        self::assertFalse(Cat::pertence(self::detalhe(), '0412340099', self::CAT));
        self::assertFalse(Cat::pertence(self::detalhe(), self::RNP, '999002/2026'));
    }

    public function testArtsAgrupadasVemComAsAtividades(): void
    {
        $arts = Cat::arts(self::detalhe());

        self::assertCount(count(self::detalhe()['arts']), $arts);
        self::assertSame('AM20269999001', $arts[0]['art']['art_numero']);
        self::assertSame('TOS_25.2.1', $arts[0]['atividades'][0]['tos_codigo']);
    }

    public function testArtSemNumeroEDescartadaSemDerrubarAsOutras(): void
    {
        $detalhe = self::detalhe();
        $detalhe['arts'][] = ['art_numero' => '', 'atividades' => []];
        $detalhe['arts'][] = 'lixo';

        self::assertCount(count(self::detalhe()['arts']), Cat::arts($detalhe));
    }

    public function testSeloNaoDependeDaOrdemDasArts(): void
    {
        $cat = Cat::projetar(self::detalhe(), self::RNP);

        self::assertSame(
            Cat::selo($cat, ['AM20269999101', 'AM20269999001']),
            Cat::selo($cat, ['AM20269999001', 'AM20269999101', 'AM20269999001']),
        );
    }

    /** O vínculo é o que o motor usa: acrescentar uma ART à certidão tem de quebrar o selo. */
    public function testSeloQuebraQuandoOVinculoMuda(): void
    {
        $cat = Cat::projetar(self::detalhe(), self::RNP);

        self::assertNotSame(
            Cat::selo($cat, ['AM20269999001']),
            Cat::selo($cat, ['AM20269999001', 'AM20260000999']),
        );
    }

    public function testSeloQuebraQuandoACertidaoMuda(): void
    {
        $cat      = Cat::projetar(self::detalhe(), self::RNP);
        $mexida   = ['cat_dt_validade' => '2030-12-31'] + $cat;

        self::assertNotSame(Cat::selo($cat, ['AM20269999001']), Cat::selo($mexida, ['AM20269999001']));
    }

    public function testVigencia(): void
    {
        self::assertTrue(Cat::vigente('2026-12-31', '2026-09-26'));
        self::assertTrue(Cat::vigente('2026-12-31', '2026-12-31'));
        self::assertFalse(Cat::vigente('2026-12-31', '2027-01-01'));
        self::assertTrue(Cat::vigente(null, '2027-01-01'), 'validade ausente não é prova de vencimento');
    }
}
