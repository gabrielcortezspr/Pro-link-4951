<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ProLink\Support\Documento;

/**
 * Trava a assimetria da massa fictícia: CPF tem dígito verificador válido, CNPJ não.
 *
 * O teste de CNPJ existe para impedir que alguém "conserte" Documento::ehCnpj() adicionando a
 * checagem de DV. Se isso acontecer, 85 das 100 empresas do desafio deixam de ser cadastráveis
 * e a demonstração para de rodar. O motivo está no cabeçalho de Documento.
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

    /**
     * O primeiro CNPJ da massa tem DV válido; o sétimo não. Os dois precisam ser aceitos —
     * é exatamente esta linha que a demonstração depende.
     */
    public function testCnpjDaMassaEhAceitoComOuSemDvValido(): void
    {
        self::assertTrue(Documento::ehCnpj('00123001000123'), 'DV confere');
        self::assertTrue(Documento::ehCnpj('00123007000190'), 'DV não confere, e ainda assim vale');

        self::assertTrue(Documento::cnpjDvConfere('00123001000123'));
        self::assertFalse(Documento::cnpjDvConfere('00123007000190'), 'o sinal informativo sabe a diferença');
    }

    #[DataProvider('cnpjsInvalidos')]
    public function testCnpjForaDeFormatoEhRecusado(string $cnpj, string $porque): void
    {
        self::assertFalse(Documento::ehCnpj($cnpj), $porque);
    }

    public static function cnpjsInvalidos(): array
    {
        return [
            ['0012300100012',   'treze dígitos'],
            ['001230010001234', 'quinze dígitos'],
            ['00000000000000',  'todos os dígitos iguais'],
            ['',                'vazio'],
        ];
    }

    public function testValidoSegueOTipoDePessoa(): void
    {
        self::assertTrue(Documento::valido('12312300109', Documento::TIPO_FISICA));
        self::assertFalse(Documento::valido('12312300109', Documento::TIPO_JURIDICA), 'CPF não serve como CNPJ');

        self::assertTrue(Documento::valido('00123007000190', Documento::TIPO_JURIDICA));
        self::assertFalse(Documento::valido('00123007000190', Documento::TIPO_FISICA), 'CNPJ não serve como CPF');
    }

    public function testPontuacaoEIgnorada(): void
    {
        self::assertTrue(Documento::ehCpf('123.123.001-09'));
        self::assertTrue(Documento::ehCnpj('00.123.001/0001-23'));
    }

    public function testMascararEscondeOMeioDoDocumento(): void
    {
        self::assertSame('123.***.**1-09', Documento::mascarar('12312300109'));
        self::assertStringNotContainsString('12312', Documento::mascarar('00123001000123'));
        self::assertSame('***', Documento::mascarar('12'), 'tamanho inesperado não vaza nada');
    }

    public function testFormatarMontaAPontuacaoPadrao(): void
    {
        self::assertSame('123.123.001-09', Documento::formatar('12312300109'));
        self::assertSame('00.123.001/0001-23', Documento::formatar('00123001000123'));
    }
}
