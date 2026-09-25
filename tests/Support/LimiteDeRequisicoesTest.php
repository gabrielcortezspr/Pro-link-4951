<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\LimiteDeRequisicoes as Limite;

/** O teto por IP da busca e do perfil público (D83). */
final class LimiteDeRequisicoesTest extends TestCase
{
    private string $pasta;

    protected function setUp(): void
    {
        $this->pasta = sys_get_temp_dir() . '/prolink-limite-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // Pasta temporária do próprio teste: é descartável, não é dado de negócio.
        foreach (glob($this->pasta . '/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }

        if (is_dir($this->pasta)) {
            rmdir($this->pasta);
        }
    }

    public function testAsTrintaPrimeirasPassamEATrigesimaPrimeiraEspera(): void
    {
        $limite = new Limite($this->pasta, 30, 60);
        $agora  = 1_000_000;

        for ($i = 0; $i < 30; $i++) {
            self::assertSame(0, $limite->consumir('busca|10.0.0.1', $agora + $i), "busca {$i}");
        }

        // A primeira marca foi em $agora; a vaga abre em $agora + 60, e estamos em $agora + 30.
        self::assertSame(30, $limite->consumir('busca|10.0.0.1', $agora + 30));
    }

    public function testUsoDaDemonstracaoNuncaBateNoLimite(): void
    {
        // Uma busca a cada dois segundos, por dez minutos: bem acima do ritmo de uma pessoa.
        $limite = new Limite($this->pasta, Limite::BUSCA_MAXIMO, Limite::JANELA_SEGUNDOS);

        for ($t = 0; $t < 600; $t += 2) {
            self::assertSame(0, $limite->consumir('busca|10.0.0.1', 5_000_000 + $t));
        }
    }

    public function testAJanelaDeslizaEAVagaVoltaQuandoAMarcaMaisAntigaSai(): void
    {
        $limite = new Limite($this->pasta, 3, 60);

        self::assertSame(0, $limite->consumir('k', 100));
        self::assertSame(0, $limite->consumir('k', 110));
        self::assertSame(0, $limite->consumir('k', 120));
        self::assertSame(40, $limite->consumir('k', 120));

        // Em 160 a marca de 100 sai da janela: abre exatamente uma vaga, a de 110 ainda conta.
        self::assertSame(0, $limite->consumir('k', 160));
        self::assertSame(10, $limite->consumir('k', 160));
    }

    public function testRecusaNaoEmpurraAEsperaParaAFrente(): void
    {
        $limite = new Limite($this->pasta, 2, 60);
        $limite->consumir('k', 100);
        $limite->consumir('k', 100);

        // Insistir durante a espera não reinicia o relógio: em 160 passa, como o Retry-After disse.
        for ($t = 101; $t < 160; $t++) {
            self::assertGreaterThan(0, $limite->consumir('k', $t));
        }

        self::assertSame(0, $limite->consumir('k', 160));
    }

    public function testCadaIpECadaRotaTemContagemPropria(): void
    {
        $limite = new Limite($this->pasta, 1, 60);

        self::assertSame(0, $limite->consumir('busca|10.0.0.1', 100));
        self::assertGreaterThan(0, $limite->consumir('busca|10.0.0.1', 100));
        self::assertSame(0, $limite->consumir('busca|10.0.0.2', 100));
        self::assertSame(0, $limite->consumir('perfil|10.0.0.1', 100));
    }

    public function testOIpNaoFicaEmClaroNoDisco(): void
    {
        (new Limite($this->pasta, 5, 60))->consumir('busca|203.0.113.9', 100);

        foreach (glob($this->pasta . '/*') ?: [] as $arquivo) {
            self::assertStringNotContainsString('203.0.113.9', basename($arquivo));
            self::assertStringNotContainsString('203.0.113.9', (string) file_get_contents($arquivo));
        }
    }

    public function testArquivoCorrompidoRecomecaAContagemEmVezDeTravar(): void
    {
        $limite = new Limite($this->pasta, 2, 60);
        $limite->consumir('k', 100);

        foreach (glob($this->pasta . '/*') ?: [] as $arquivo) {
            file_put_contents($arquivo, 'isto não é json');
        }

        self::assertSame(0, $limite->consumir('k', 101));
    }

    public function testPastaIndisponivelLiberaEmVezDeDerrubarABusca(): void
    {
        // Um arquivo comum no lugar da pasta: mkdir falha, e a busca precisa continuar de pé.
        touch($this->pasta);

        try {
            $limite = new Limite($this->pasta . '/limite', 1, 60);
            $log    = ini_set('error_log', '/dev/null');

            self::assertSame(0, $limite->consumir('k', 100));
            self::assertSame(0, $limite->consumir('k', 100));

            ini_set('error_log', (string) $log);
        } finally {
            unlink($this->pasta);
        }
    }

    public function testRelogioQueVoltouNaoPrendeAChave(): void
    {
        [$marcas, $espera] = Limite::decidir([500, 500], 100, 2, 60);

        self::assertSame(0, $espera);
        self::assertSame([100], $marcas);
    }
}
