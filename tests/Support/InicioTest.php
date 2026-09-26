<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Inicio;

final class InicioTest extends TestCase
{
    private const HOJE = '2026-09-25';

    private static function painel(array $sobre = []): array
    {
        return $sobre + [
            'a_analisar' => 0, 'candidaturas' => 0, 'candidaturas_novas' => 0, 'convites' => 0,
            'nao_lidas' => 0, 'contatos' => 0, 'dispensados' => 0, 'pendentes' => 0,
        ];
    }

    private static function candidato(array $sobre = []): array
    {
        return $sobre + [
            'convites_novos' => 0, 'nao_lidas' => 0, 'perfil_aberto' => true,
            'preferencias_vazias' => false, 'acervo_em' => '2026-09-20 10:00:00', 'pode_atualizar' => true,
        ];
    }

    public function testContaEmDiaNaoTemNadaParaFazer(): void
    {
        $demandas = [[
            'dem_id' => 1, 'dem_titulo' => 'Galpão', 'dem_dt_publicacao' => '2026-09-01 10:00:00',
            'dem_situacao' => 'ABERTA', 'dem_inicio_ate' => null, 'painel' => self::painel(),
        ]];

        self::assertSame([], Inicio::paraFazer(self::candidato(), $demandas, self::HOJE));
    }

    public function testQuemEsperaRespostaVemAntesDoTrabalhoEDoPerfil(): void
    {
        $demandas = [
            ['dem_id' => 7, 'dem_titulo' => 'Rascunho', 'dem_dt_publicacao' => null],
            [
                'dem_id' => 9, 'dem_titulo' => 'Galpão', 'dem_dt_publicacao' => '2026-09-01 10:00:00',
                'dem_situacao' => 'ABERTA', 'dem_inicio_ate' => null,
                'painel' => self::painel(['candidaturas_novas' => 2, 'a_analisar' => 5]),
            ],
        ];
        $candidato = self::candidato(['convites_novos' => 1, 'perfil_aberto' => false]);

        $chaves = array_column(Inicio::paraFazer($candidato, $demandas, self::HOJE), 'chave');

        self::assertSame(['convites', 'candidaturas-9', 'rascunho-7', 'avaliar-9', 'perfil-fechado'], $chaves);
    }

    public function testDemandaEncerradaNaoPedeNada(): void
    {
        $demandas = [[
            'dem_id' => 3, 'dem_titulo' => 'Antiga', 'dem_dt_publicacao' => '2026-08-01 10:00:00',
            'dem_situacao' => 'ENCERRADA', 'dem_inicio_ate' => '2026-08-10',
            'painel' => self::painel(['nao_lidas' => 4]),
        ]];

        self::assertSame([], Inicio::paraFazer(null, $demandas, self::HOJE));
    }

    public function testPrazoDeInicio(): void
    {
        self::assertSame('Prazo de início vencido', Inicio::prazo('2026-09-20', self::HOJE));
        self::assertSame('Prazo de início vence hoje', Inicio::prazo('2026-09-25', self::HOJE));
        self::assertSame('Prazo de início vence amanhã', Inicio::prazo('2026-09-26', self::HOJE));
        self::assertSame('Prazo de início vence em 7 dias', Inicio::prazo('2026-10-02', self::HOJE));
        self::assertNull(Inicio::prazo('2026-10-03', self::HOJE));
        self::assertNull(Inicio::prazo(null, self::HOJE));
    }

    public function testAcervoAntigoSoQuandoPodeAtualizar(): void
    {
        self::assertTrue(Inicio::acervoAntigo(null, self::HOJE));
        self::assertTrue(Inicio::acervoAntigo('2026-08-01 09:00:00', self::HOJE));
        self::assertFalse(Inicio::acervoAntigo('2026-09-01 09:00:00', self::HOJE));

        $antigo = self::candidato(['acervo_em' => '2026-01-01 09:00:00']);
        self::assertSame(['acervo'], array_column(Inicio::paraFazer($antigo, [], self::HOJE), 'chave'));

        $naJanela = self::candidato(['acervo_em' => '2026-01-01 09:00:00', 'pode_atualizar' => false]);
        self::assertSame([], Inicio::paraFazer($naJanela, [], self::HOJE));
    }

    public function testPluralNoTitulo(): void
    {
        $um    = Inicio::paraFazer(self::candidato(['convites_novos' => 1]), [], self::HOJE)[0];
        $dois  = Inicio::paraFazer(self::candidato(['convites_novos' => 2]), [], self::HOJE)[0];

        self::assertSame('1 convite novo de empresa', $um['titulo']);
        self::assertSame('Ver convite', $um['acao']);
        self::assertSame('2 convites novos de empresas', $dois['titulo']);
    }
}
