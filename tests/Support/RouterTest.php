<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Router;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
        $this->router->get('/', 'Home', 'index');
        $this->router->get('/perfil/{id}', 'Perfil', 'ver');
        $this->router->post('/demandas/{id}/encerrar', 'Demanda', 'encerrar', [PERFIL_EMPRESA, PERFIL_TERCEIRO]);
        $this->router->get('/admin', 'Admin', 'index', PERFIL_ADMIN);
    }

    public function testRotaEstatica(): void
    {
        $r = $this->router->resolver('GET', '/');

        self::assertSame('Home', $r['controller']);
        self::assertSame('index', $r['metodo']);
        self::assertSame([PERFIL_PUBLICO], $r['perfis']);
        self::assertSame([], $r['params']);
    }

    public function testParametroDeCaminhoChegaNomeado(): void
    {
        $r = $this->router->resolver('GET', '/perfil/42');

        self::assertSame(['id' => '42'], $r['params']);
    }

    public function testQueryStringEBarraFinalSaoIgnoradas(): void
    {
        self::assertNotNull($this->router->resolver('GET', '/perfil/42/?x=1'));
        self::assertNotNull($this->router->resolver('get', '/'));
    }

    public function testMetodoErradoNaoCasa(): void
    {
        self::assertNull($this->router->resolver('POST', '/admin'));
        self::assertNull($this->router->resolver('GET', '/demandas/7/encerrar'));
    }

    public function testCaminhoInexistenteDevolveNull(): void
    {
        self::assertNull($this->router->resolver('GET', '/nao-existe'));
    }

    public function testPerfisMultiplos(): void
    {
        $r = $this->router->resolver('POST', '/demandas/7/encerrar');

        self::assertSame([PERFIL_EMPRESA, PERFIL_TERCEIRO], $r['perfis']);
        self::assertFalse(Router::ehPublica($r['perfis']));
        self::assertTrue(Router::ehPublica([PERFIL_PUBLICO]));
    }
}
