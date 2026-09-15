<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Crypto;
use RuntimeException;

final class CryptoTest extends TestCase
{
    public function testCifrarEDecifrarSaoInversos(): void
    {
        $cpf = '12312300109';
        $pacote = Crypto::cifrar($cpf);

        self::assertNotSame($cpf, $pacote);
        self::assertSame($cpf, Crypto::decifrar($pacote));
    }

    public function testCadaCifragemGeraPacoteDiferente(): void
    {
        self::assertNotSame(Crypto::cifrar('x'), Crypto::cifrar('x'), 'IV aleatório por chamada');
    }

    public function testAdulteracaoDeUmBitEhDetectada(): void
    {
        $pacote = Crypto::cifrar('12312300109');
        $ultimo = strlen($pacote) - 1;
        $pacote[$ultimo] = chr(ord($pacote[$ultimo]) ^ 1);

        $this->expectException(RuntimeException::class);
        Crypto::decifrar($pacote);
    }

    public function testPacoteCurtoEhRejeitado(): void
    {
        $this->expectException(RuntimeException::class);
        Crypto::decifrar('curto');
    }

    public function testHashDeBuscaIgnoraPontuacaoEEhEstavel(): void
    {
        self::assertSame(Crypto::hashBusca('123.123.001-09'), Crypto::hashBusca('12312300109'));
        self::assertNotSame(Crypto::hashBusca('12312300109'), Crypto::hashBusca('12312300290'));
        self::assertSame(64, strlen(Crypto::hashBusca('12312300109')));
    }

    public function testSeloIndependeDaOrdemDasChaves(): void
    {
        $a = ['b' => 1, 'a' => ['y' => 2, 'x' => 1]];
        $b = ['a' => ['x' => 1, 'y' => 2], 'b' => 1];

        self::assertSame(Crypto::selo($a), Crypto::selo($b));
    }

    public function testSeloMudaSeODadoMuda(): void
    {
        self::assertNotSame(
            Crypto::selo(['art_numero' => 'AM20269999001', 'art_situacao' => 'REGISTRADA']),
            Crypto::selo(['art_numero' => 'AM20269999001', 'art_situacao' => 'CANCELADA']),
        );
    }

    public function testApenasDigitos(): void
    {
        self::assertSame('00123001000123', Crypto::apenasDigitos('00.123.001/0001-23'));
    }

    /**
     * O caso que separava os três serviços que leem `usu_documento_cif`.
     *
     * A escrita usa `PDO::PARAM_LOB`, e o preço é que a leitura pode voltar recurso em vez de
     * string. Dois dos três serviços recusavam o recurso com `!is_string()` e diziam "esta conta
     * não tem CPF guardado" — falso, e justamente no caminho de revalidação da D20.
     */
    public function testDecifraColunaQueVeioComoRecurso(): void
    {
        $pacote = Crypto::cifrar('12312300109');
        $fluxo  = fopen('php://memory', 'r+');

        self::assertIsResource($fluxo);
        fwrite($fluxo, $pacote);
        rewind($fluxo);

        self::assertSame('12312300109', Crypto::decifrarColuna($fluxo));
        fclose($fluxo);
    }

    public function testDecifraColunaQueVeioComoString(): void
    {
        self::assertSame('12312300109', Crypto::decifrarColuna(Crypto::cifrar('12312300109')));
    }

    /** Ausência de documento é null, não exceção: quem precisa de erro decide lá em cima. */
    public function testColunaVaziaNaoEErro(): void
    {
        self::assertNull(Crypto::decifrarColuna(null));
        self::assertNull(Crypto::decifrarColuna(''));
    }
}
