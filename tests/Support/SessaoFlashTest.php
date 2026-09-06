<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Flash;
use ProLink\Support\Sessao;

/** Testa só a leitura sobre $_SESSION; abrir sessão real é teste de integração. */
final class SessaoFlashTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testSemUsuarioNaSessao(): void
    {
        self::assertNull(Sessao::usuarioAtual());
        self::assertNull(Sessao::usuarioId());
        self::assertFalse(Sessao::autenticado());
        self::assertFalse(Sessao::temPerfil(PERFIL_ADMIN));
    }

    public function testPerfilExigeCorrespondenciaExata(): void
    {
        $_SESSION['usuario'] = ['id' => 7, 'nome' => 'Ana', 'perfil' => PERFIL_PROFISSIONAL];

        self::assertTrue(Sessao::autenticado());
        self::assertSame(7, Sessao::usuarioId());
        self::assertTrue(Sessao::temPerfil(PERFIL_PROFISSIONAL));
        self::assertTrue(Sessao::temPerfil(PERFIL_EMPRESA, PERFIL_PROFISSIONAL));
        self::assertFalse(Sessao::temPerfil(PERFIL_ADMIN), 'não há superusuário implícito');
    }

    public function testFlashEhConsumidaUmaVez(): void
    {
        Flash::sucesso('ok');
        Flash::erro('falhou');

        $mensagens = Flash::consumir();

        self::assertSame([
            ['tipo' => 'success', 'texto' => 'ok'],
            ['tipo' => 'danger',  'texto' => 'falhou'],
        ], $mensagens);
        self::assertSame([], Flash::consumir());
    }
}
