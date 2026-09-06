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
}
