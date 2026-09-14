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

    public function testDataVaziaPassaPorqueCampoDeDataEhOpcional(): void
    {
        $v = new Validacao();
        $v->data('dt_inicio', '', 'Data inválida.')
          ->data('dt_fim', null, 'Data inválida.')
          ->data('dt_outra', '   ', 'Data inválida.');

        self::assertTrue($v->valido());
    }

    public function testDataAceitaOFormatoDoBanco(): void
    {
        $v = new Validacao();
        $v->data('dt_inicio', '2026-09-14', 'Data inválida.');

        self::assertTrue($v->valido());
    }

    public function testDataRecusaFormatoDiferente(): void
    {
        $v = new Validacao();
        $v->data('a', '14/09/2026', 'Data inválida.')
          ->data('b', '2026-9-14', 'Data inválida.')
          ->data('c', 'ontem', 'Data inválida.');

        self::assertSame(['a' => 'Data inválida.', 'b' => 'Data inválida.', 'c' => 'Data inválida.'],
            $v->erros());
    }

    public function testDataRecusaDiaQueNaoExisteNoCalendario(): void
    {
        // 2026-02-30 casa com a expressão regular e não existe. DateTimeImmutable aceitaria e
        // rolaria para 2 de março, gravando em silêncio uma data que ninguém digitou.
        $v = new Validacao();
        $v->data('fevereiro', '2026-02-30', 'Data inválida.')
          ->data('mes', '2026-13-01', 'Data inválida.');

        self::assertSame(['fevereiro' => 'Data inválida.', 'mes' => 'Data inválida.'], $v->erros());
    }

    public function testDataAceitaBissextoDeVerdade(): void
    {
        $v = new Validacao();
        $v->data('bissexto', '2024-02-29', 'Data inválida.');
        self::assertTrue($v->valido());

        $naoBissexto = new Validacao();
        $naoBissexto->data('comum', '2026-02-29', 'Data inválida.');
        self::assertFalse($naoBissexto->valido());
    }
}
