<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProLink\Support\Documento;

/**
 * Validação de CPF e CNPJ: formato e dígito verificador, nas duas.
 *
 * A contagem de quantos documentos da massa fictícia sobrevivem a essa validação está em
 * MassaDeDadosTest, que é o guardião daquele número.
 */
final class DocumentoTest extends TestCase
{
    /** Os quatro primeiros CPFs de data/csv/profissionais.csv. */
    #[DataProvider('cpfsDaMassa')]
    public function testCpfDaMassaEhAceito(string $cpf): void
    {
        self::assertTrue(Documento::ehCpf($cpf));
    }

    public static function cpfsDaMassa(): array
    {
        return [
            ['12312300109'],
            ['12312300290'],
            ['123.123.003-70'],
            ['12312300451'],
        ];
    }

    #[DataProvider('cpfsInvalidos')]
    public function testCpfInvalidoEhRecusado(string $cpf, string $porque): void
    {
        self::assertFalse(Documento::ehCpf($cpf), $porque);
    }

    public static function cpfsInvalidos(): array
    {
        return [
            ['12312300108', 'último dígito trocado'],
            ['11111111111', 'todos os dígitos iguais'],
            ['1231230010',  'dez dígitos'],
            ['123123001099', 'doze dígitos'],
            ['',            'vazio'],
        ];
    }

    /** O primeiro CNPJ da massa fecha o DV; o sétimo não, e por isso é recusado. */
    public function testCnpjEhAceitoSomenteComDvValido(): void
    {
        self::assertTrue(Documento::ehCnpj('00123001000123'), 'AMAZÔNIA: DV confere');
        self::assertFalse(Documento::ehCnpj('00123007000190'), 'DV quebrado não passa, nem vindo da massa');

        self::assertTrue(Documento::dvCnpjConfere('00123001000123'));
        self::assertFalse(Documento::dvCnpjConfere('00123007000190'));
    }

    #[DataProvider('cnpjsInvalidos')]
    public function testCnpjInvalidoEhRecusado(string $cnpj, string $porque): void
    {
        self::assertFalse(Documento::ehCnpj($cnpj), $porque);
    }

    public static function cnpjsInvalidos(): array
    {
        return [
            ['0012300100012',   'treze dígitos'],
            ['001230010001234', 'quinze dígitos'],
            ['00000000000000',  'todos os dígitos iguais'],
            ['00123007000190',  'dígito verificador quebrado'],
            ['',                'vazio'],
        ];
    }

    public function testValidoSegueOTipoDePessoa(): void
    {
        self::assertTrue(Documento::valido('12312300109', Documento::TIPO_FISICA));
        self::assertFalse(Documento::valido('12312300109', Documento::TIPO_JURIDICA), 'CPF não serve como CNPJ');

        self::assertTrue(Documento::valido('00123001000123', Documento::TIPO_JURIDICA));
        self::assertFalse(Documento::valido('00123001000123', Documento::TIPO_FISICA), 'CNPJ não serve como CPF');
    }

    public function testPontuacaoEIgnorada(): void
    {
        self::assertTrue(Documento::ehCpf('123.123.001-09'));
        self::assertTrue(Documento::ehCnpj('00.123.001/0001-23'));
    }

    /**
     * A máscara revela o mínimo que ainda permite o titular reconhecer o próprio documento.
     * O limite está travado como número: cinco dígitos de onze no CPF, quatro de catorze no CNPJ.
     * Se alguém alargar a máscara, este teste reclama.
     */
    public function testMascararEscondeOMeioDoDocumento(): void
    {
        self::assertSame('123.***.***-09', Documento::mascarar('12312300109'));
        self::assertSame('00.***.***/****-23', Documento::mascarar('00123001000123'));
        self::assertSame('***', Documento::mascarar('12'), 'tamanho inesperado não vaza nada');
    }

    /** @dataProvider documentosParaMascarar */
    public function testMascaraNaoRevelaMaisDigitosDoQueOCombinado(string $documento, int $maximo): void
    {
        $visiveis = preg_match_all('/\d/', Documento::mascarar($documento));

        self::assertLessThanOrEqual($maximo, $visiveis,
            'máscara revelando mais dígitos do que o necessário');
    }

    public static function documentosParaMascarar(): array
    {
        return [
            'CPF'  => ['12312300109', 5],
            'CNPJ' => ['00123001000123', 4],
        ];
    }

    public function testFormatarMontaAPontuacaoPadrao(): void
    {
        self::assertSame('123.123.001-09', Documento::formatar('12312300109'));
        self::assertSame('00.123.001/0001-23', Documento::formatar('00123001000123'));
    }
}
