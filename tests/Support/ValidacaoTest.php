<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Validacao;

final class ValidacaoTest extends TestCase
{
    public function testSemErrosEhValido(): void
    {
        $v = new Validacao();

        self::assertTrue($v->valido());
        self::assertSame([], $v->erros());
        $v->lancarSeInvalido();
    }

    /**
     * A primeira regra a falhar é a mais específica, então é a mensagem que fica. Sem isso o
     * usuário vê "informe o e-mail" sobrescrito por "e-mail inválido" no mesmo campo vazio.
     */
    public function testPrimeiroErroDoCampoEhOQueSobrevive(): void
    {
        $v = new Validacao();
        $v->obrigatorio('email', '', 'Informe o e-mail.')
          ->email('email', '', 'E-mail inválido.');

        self::assertSame(['email' => 'Informe o e-mail.'], $v->erros());
    }

    public function testErrosDeCamposDiferentesConvivem(): void
    {
        $v = new Validacao();
        $v->obrigatorio('nome', '', 'Informe o nome.')
          ->obrigatorio('email', '', 'Informe o e-mail.');

        self::assertSame(['nome' => 'Informe o nome.', 'email' => 'Informe o e-mail.'], $v->erros());
    }

    public function testObrigatorioRecusaEspacoEmBranco(): void
    {
        $v = new Validacao();
        $v->obrigatorio('nome', "   \t\n", 'Informe o nome.');

        self::assertFalse($v->valido());
    }

    public function testTamanhoContaCaractereNaoByte(): void
    {
        // "João" tem 4 caracteres e 5 bytes em UTF-8. Contar bytes recusaria nome legítimo.
        $v = new Validacao();
        $v->tamanhoMaximo('nome', 'João', 4, 'Longo demais.');

        self::assertTrue($v->valido());
    }

    public function testEntreAceitaSomenteValorDaLista(): void
    {
        $v = new Validacao();
        $v->entre('perfil', 'ADMIN', ['PROFISSIONAL', 'EMPRESA'], 'Perfil inválido.');

        self::assertSame(['perfil' => 'Perfil inválido.'], $v->erros());
    }

    public function testLancarSeInvalidoCarregaOsErrosParaOControlador(): void
    {
        $v = new Validacao();
        $v->obrigatorio('documento', '', 'Informe o CPF.');

        try {
            $v->lancarSeInvalido('Confira os campos.');
            self::fail('deveria ter lançado ValidacaoException');
        } catch (ValidacaoException $e) {
            self::assertSame('Confira os campos.', $e->getMessage());
            self::assertSame(['documento' => 'Informe o CPF.'], $e->erros());
        }
    }
}
