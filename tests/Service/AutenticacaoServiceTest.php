<?php

declare(strict_types=1);

namespace ProLink\Tests\Service;

use PHPUnit\Framework\TestCase;
use ProLink\Service\AutenticacaoService;
use ProLink\Support\Documento;

/**
 * Os mapeamentos do formulário de cadastro, que são estáticos e não tocam o banco.
 *
 * O fluxo completo — cadastro, login, bloqueio, recuperação, exclusão e auditoria — é verificado
 * por HTTP em `scripts/verificar-e1.php`, porque depende de sessão, cookie e CSRF reais.
 */
final class AutenticacaoServiceTest extends TestCase
{
    /** Quatro tipos de cadastro, três perfis: os dois Terceiros compartilham o mesmo perfil. */
    public function testTipoDeCadastroViraPerfilDeAcesso(): void
    {
        self::assertSame(PERFIL_PROFISSIONAL, AutenticacaoService::perfilDe(CADASTRO_PROFISSIONAL));
        self::assertSame(PERFIL_EMPRESA,      AutenticacaoService::perfilDe(CADASTRO_EMPRESA));
        self::assertSame(PERFIL_TERCEIRO,     AutenticacaoService::perfilDe(CADASTRO_TERCEIRO_PF));
        self::assertSame(PERFIL_TERCEIRO,     AutenticacaoService::perfilDe(CADASTRO_TERCEIRO_PJ));
    }

    /** Nunca ADMIN: o administrador é criado por script, jamais por formulário público. */
    public function testNenhumTipoDeCadastroProduzAdministrador(): void
    {
        $tipos = [CADASTRO_PROFISSIONAL, CADASTRO_EMPRESA, CADASTRO_TERCEIRO_PF,
                  CADASTRO_TERCEIRO_PJ, 'QUALQUER_COISA'];

        foreach ($tipos as $tipo) {
            self::assertNotSame(PERFIL_ADMIN, AutenticacaoService::perfilDe($tipo));
        }
    }

    /** O tipo de pessoa é o que decide se o documento exigido é CPF ou CNPJ. */
    public function testTipoDePessoaDecideODocumentoExigido(): void
    {
        self::assertSame(Documento::TIPO_FISICA,   AutenticacaoService::tipoPessoaDe(CADASTRO_PROFISSIONAL));
        self::assertSame(Documento::TIPO_FISICA,   AutenticacaoService::tipoPessoaDe(CADASTRO_TERCEIRO_PF));
        self::assertSame(Documento::TIPO_JURIDICA, AutenticacaoService::tipoPessoaDe(CADASTRO_EMPRESA));
        self::assertSame(Documento::TIPO_JURIDICA, AutenticacaoService::tipoPessoaDe(CADASTRO_TERCEIRO_PJ));
    }

    /** Política de senha: o edital 8.5b pede autenticação segura, e 12 é o nosso piso. */
    public function testPoliticaDeSenhaEstaAcimaDoUsual(): void
    {
        self::assertGreaterThanOrEqual(12, SENHA_TAMANHO_MINIMO);
        self::assertSame(PASSWORD_ARGON2ID, PASSWORD_ALGO);
    }
}
