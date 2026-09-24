<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Service\ValidacaoException;
use ProLink\Support\RespostaInteresse as R;

/** As preferências da demanda como perguntas a quem manifesta interesse (D78). */
final class RespostaInteresseTest extends TestCase
{
    private const HOJE = '2026-09-26';

    private const DEMANDA = [
        'dem_tipo_contrato'   => 'PJ',
        'dem_local_uf'        => 'AM',
        'dem_local_municipio' => 'Manaus',
        'dem_inicio_ate'      => '2026-10-15',
    ];

    public function testDemandaCompletaFazAsTresPerguntas(): void
    {
        $p = R::perguntas(self::DEMANDA);

        self::assertSame('PJ', $p['contrato']);
        self::assertSame(['uf' => 'AM', 'municipio' => 'Manaus'], $p['local']);
        self::assertSame('2026-10-15', $p['inicio_ate']);
    }

    public function testQualquerRegimeESemLocalNaoPerguntam(): void
    {
        $p = R::perguntas(['dem_tipo_contrato' => 'QUALQUER', 'dem_local_uf' => null]);

        self::assertNull($p['contrato']);
        self::assertNull($p['local']);
        self::assertNull($p['inicio_ate'], 'prazo em aberto');
    }

    public function testRespostasValidasSaemNormalizadas(): void
    {
        $r = R::validar(
            ['aceita_contrato' => 's', 'atende_local' => 'N', 'inicio_em' => '2026-10-01'],
            self::DEMANDA,
            self::HOJE,
        );

        self::assertSame(['aceita_contrato' => 'S', 'atende_local' => 'N', 'inicio_em' => '2026-10-01'], $r);
    }

    public function testPerguntaFeitaEObrigatoria(): void
    {
        try {
            R::validar(['inicio_em' => '2026-10-01'], self::DEMANDA, self::HOJE);
            self::fail('deveria recusar');
        } catch (ValidacaoException $e) {
            self::assertArrayHasKey('aceita_contrato', $e->erros());
            self::assertArrayHasKey('atende_local', $e->erros());
        }
    }

    public function testPerguntaNaoFeitaEIgnorada(): void
    {
        $r = R::validar(
            ['aceita_contrato' => 'S', 'atende_local' => 'S', 'inicio_em' => '2026-10-01'],
            ['dem_tipo_contrato' => 'QUALQUER'],
            self::HOJE,
        );

        self::assertNull($r['aceita_contrato']);
        self::assertNull($r['atende_local']);
    }

    public function testInicioNoPassadoOuInvalidoERecusado(): void
    {
        foreach (['2026-09-25', '2026-02-30', 'amanhã', '', '2029-01-01'] as $data) {
            try {
                R::validar(['aceita_contrato' => 'S', 'atende_local' => 'S', 'inicio_em' => $data], self::DEMANDA, self::HOJE);
                self::fail("deveria recusar {$data}");
            } catch (ValidacaoException $e) {
                self::assertArrayHasKey('inicio_em', $e->erros(), $data);
            }
        }
    }

    public function testQuadroComparaPedidoComResposta(): void
    {
        $q = R::quadro(self::DEMANDA, [
            'man_aceita_contrato' => 'S',
            'man_atende_local'    => 'N',
            'man_inicio_em'       => '2026-11-01',
        ]);

        self::assertSame(['contrato', 'local', 'inicio'], array_column($q, 'chave'));
        self::assertSame(['Pessoa jurídica', 'Aceita', true], [$q[0]['pedido'], $q[0]['resposta'], $q[0]['atende']]);
        self::assertSame(['Manaus/AM', 'Não atende a região', false], [$q[1]['pedido'], $q[1]['resposta'], $q[1]['atende']]);
        self::assertSame(['Até 15/10/2026', 'Pode começar em 01/11/2026', false], [$q[2]['pedido'], $q[2]['resposta'], $q[2]['atende']]);
    }

    /** Manifestação do lado do demandante, ou anterior à D78: nada respondido, nada afirmado. */
    public function testSemRespostaNadaEAfirmado(): void
    {
        $q = R::quadro(array_merge(self::DEMANDA, ['dem_inicio_ate' => null]), []);

        self::assertSame([null, null, null], array_column($q, 'atende'));
        self::assertSame('Prazo em aberto', $q[2]['pedido']);
        self::assertSame('Não informou', $q[2]['resposta']);
    }
}
