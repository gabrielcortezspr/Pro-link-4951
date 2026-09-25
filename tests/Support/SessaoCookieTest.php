<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Sessao;

/**
 * A regra do cookie de sessão (D86), conferida sem subir servidor: é função pura.
 *
 * O que se prova aqui é a diferença entre "tem Secure" e "o navegador recusa versão insegura":
 * em HTTPS o nome ganha o prefixo `__Host-`, que só é aceito com Secure, Path=/ e sem Domain.
 */
final class SessaoCookieTest extends TestCase
{
    /** Em HTTPS o cookie usa o prefixo __Host- e vem com Secure; sem Domain, e Path na raiz. */
    public function testEmHttpsUsaPrefixoHostComSecure(): void
    {
        $cfg = Sessao::parametrosCookie(true, 7200);

        self::assertSame('__Host-PHPSESSID', $cfg['nome']);
        self::assertTrue($cfg['params']['secure']);
        self::assertSame('/', $cfg['params']['path']);
        self::assertArrayNotHasKey('domain', $cfg['params']);
    }

    /** Fora de HTTPS não dá para exigir Secure, senão o cookie não seria gravado; nome simples. */
    public function testForaDeHttpsNaoExigeSecure(): void
    {
        $cfg = Sessao::parametrosCookie(false, 7200);

        self::assertSame('PHPSESSID', $cfg['nome']);
        self::assertFalse($cfg['params']['secure']);
    }

    /** As travas contra roubo de sessão não dependem do esquema: sempre HttpOnly e SameSite=Lax. */
    public function testHttpOnlyESameSiteEmQualquerEsquema(): void
    {
        foreach ([true, false] as $https) {
            $params = Sessao::parametrosCookie($https, 7200)['params'];

            self::assertTrue($params['httponly'], 'HttpOnly protege o cookie do script na página.');
            self::assertSame('Lax', $params['samesite'], 'SameSite=Lax corta o POST cross-site.');
            self::assertSame(7200, $params['lifetime']);
        }
    }
}
