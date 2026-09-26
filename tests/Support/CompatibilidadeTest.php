<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Compatibilidade;

/**
 * O cálculo do motor, exercitado sem banco.
 *
 * O que se testa aqui não é "a conta dá o número certo" (esse número é calibrável e muda), e sim
 * as propriedades que não podem quebrar: nada passa de 1, mais evidência nunca pontua menos,
 * volume tem retorno decrescente, dimensão ausente não vira zero, e a mesma semente devolve a
 * mesma ordem. São as garantias que o edital cobra, traduzidas em asserção.
 */
final class CompatibilidadeTest extends TestCase
{
    private const PESOS_AFINIDADE = [0.00, 0.15, 0.40, 0.75, 1.00];

    private const PESOS_DIMENSAO = [
        'competencia'     => 0.40,
        'area'            => 0.15,
        'localizacao'     => 0.15,
        'experiencia'     => 0.10,
        'contrato'        => 0.10,
        'disponibilidade' => 0.10,
    ];

    /** @return list<array<string, mixed>> */
    private function acervo(array ...$linhas): array
    {
        return array_map(static fn (array $l): array => $l + [
            'evi_tos_codigo' => 'TOS_1.1.2.1',
            'evi_art_numero' => 'AM2026000001',
            'evi_cat_numero' => null,
        ], $linhas);
    }

    // ---------------------------------------------------------------- competência

    public function testCodigoExatoComUmaArtValeOPesoCheioDaAfinidade(): void
    {
        $r = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo([]),
            self::PESOS_AFINIDADE,
        );

        self::assertSame(1.0, $r['score']);
        self::assertSame('TOS_1.1.2.1', $r['evidencias'][0]['codigo_acervo']);
    }

    public function testCodigoIrmaoPontuaMenosQueOExato(): void
    {
        $exato = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_1.1.2.1']),
            self::PESOS_AFINIDADE,
        );

        $irmao = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_1.1.2.5']),
            self::PESOS_AFINIDADE,
        );

        self::assertGreaterThan($irmao['score'], $exato['score']);
        self::assertSame(0.75, $irmao['score']);
    }

    public function testGrupoDiferenteNaoPontua(): void
    {
        $r = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_11.10.1.4']),
            self::PESOS_AFINIDADE,
        );

        self::assertSame(0.0, $r['score']);
        self::assertSame([], $r['evidencias']);
    }

    public function testVolumeTemRetornoDecrescenteENuncaChegaA1(): void
    {
        $comN = function (int $n): float {
            $linhas = [];

            for ($i = 1; $i <= $n; $i++) {
                $linhas[] = ['evi_tos_codigo' => 'TOS_1.1.2.5', 'evi_art_numero' => "AM202600000{$i}"];
            }

            return (float) Compatibilidade::competencia(
                ['TOS_1.1.2.1' => 1.0],
                $this->acervo(...$linhas),
                self::PESOS_AFINIDADE,
            )['score'];
        };

        $um     = $comN(1);
        $quatro = $comN(4);
        $nove   = $comN(9);

        // Mais evidência nunca pontua menos.
        self::assertGreaterThan($um, $quatro);
        self::assertGreaterThan($quatro, $nove);

        // E o ganho de 4 para 9 é menor que o de 1 para 4: é o retorno decrescente.
        self::assertLessThan($quatro - $um, $nove - $quatro);

        // Nunca satura em 1, senão volume viraria ranking por antiguidade de carreira.
        self::assertLessThan(1.0, $nove);
    }

    public function testArtRepetidaNaoContaDuasVezes(): void
    {
        $duasLinhasMesmaArt = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(
                ['evi_tos_codigo' => 'TOS_1.1.2.5', 'evi_art_numero' => 'AM2026000001'],
                ['evi_tos_codigo' => 'TOS_1.1.2.5', 'evi_art_numero' => 'AM2026000001'],
            ),
            self::PESOS_AFINIDADE,
        );

        self::assertSame(0.75, $duasLinhasMesmaArt['score']);
    }

    public function testAfinidadeMelhorDescartaAsFracasEmVezDeSomar(): void
    {
        // Cinco correspondências fracas mais uma exata: vale a exata, não a soma.
        $r = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(
                ['evi_tos_codigo' => 'TOS_1.4.3',     'evi_art_numero' => 'AM2026000002'],
                ['evi_tos_codigo' => 'TOS_1.4.4',     'evi_art_numero' => 'AM2026000003'],
                ['evi_tos_codigo' => 'TOS_1.1.2.1',   'evi_art_numero' => 'AM2026000001'],
            ),
            self::PESOS_AFINIDADE,
        );

        self::assertSame(1.0, $r['score']);
        self::assertSame(['AM2026000001'], $r['evidencias'][0]['arts']);
    }

    public function testCatReforcaMasNaoUltrapassa1(): void
    {
        $semCat = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_1.1.2.5']),
            self::PESOS_AFINIDADE,
        );

        $comCat = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_1.1.2.5', 'evi_cat_numero' => '999001/2026']),
            self::PESOS_AFINIDADE,
        );

        self::assertGreaterThan($semCat['score'], $comCat['score']);
        self::assertLessThanOrEqual(1.0, $comCat['score']);
        self::assertSame('999001/2026', $comCat['evidencias'][0]['cat']);
    }

    /** CAT vencida não reforça, e a justificativa não a cita (D76). A ART segue contando. */
    public function testCatVencidaNaoReforcaNemApareceNaJustificativa(): void
    {
        $linha = ['evi_tos_codigo' => 'TOS_1.1.2.5', 'evi_cat_numero' => '999001/2026'];

        $semCat = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_1.1.2.5']),
            self::PESOS_AFINIDADE,
            '2026-09-26',
        );

        $vigente = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo($linha + ['evi_cat_dt_validade' => '2026-12-31']),
            self::PESOS_AFINIDADE,
            '2026-09-26',
        );

        $vencida = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo($linha + ['evi_cat_dt_validade' => '2026-12-31']),
            self::PESOS_AFINIDADE,
            '2027-01-01',
        );

        self::assertGreaterThan($semCat['score'], $vigente['score']);
        self::assertSame($semCat['score'], $vencida['score']);
        self::assertNull($vencida['evidencias'][0]['cat']);
    }

    /**
     * A evidência diz qual CAT cobre qual ART, porque a CAT herda a visibilidade da ART e a tela
     * precisa decidir por ela (D76). Vencida só aparece quando não há vigente na atividade.
     */
    public function testEvidenciaLigaCadaCatAArtQueEleCertifica(): void
    {
        $r = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(
                ['evi_art_numero' => 'AM01', 'evi_cat_numero' => '999001/2026', 'evi_cat_dt_validade' => '2026-12-31'],
                ['evi_art_numero' => 'AM02'],
                ['evi_art_numero' => 'AM03', 'evi_cat_numero' => '998000/2024', 'evi_cat_dt_validade' => '2025-01-01'],
            ),
            self::PESOS_AFINIDADE,
            '2026-09-26',
        );

        $e = $r['evidencias'][0];
        self::assertSame(['AM01' => '999001/2026'], $e['cats']);
        self::assertSame([], $e['cats_vencidas'], 'com CAT vigente na atividade, a vencida não é dita');

        $soVencida = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_art_numero' => 'AM03', 'evi_cat_numero' => '998000/2024', 'evi_cat_dt_validade' => '2025-01-01']),
            self::PESOS_AFINIDADE,
            '2026-09-26',
        )['evidencias'][0];

        self::assertSame([], $soVencida['cats']);
        self::assertSame(['AM03' => '998000/2024'], $soVencida['cats_vencidas']);
        self::assertNull($soVencida['cat']);
    }

    public function testPesoDaDemandaMudaAContribuicaoDeCadaCodigo(): void
    {
        // Atende só o código secundário (peso 0.5) e nada do principal (peso 1.0).
        $r = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0, 'TOS_9.9.9.9' => 0.5],
            $this->acervo(['evi_tos_codigo' => 'TOS_9.9.9.9']),
            self::PESOS_AFINIDADE,
        );

        // 0.5 * 1.0 dividido por (1.0 + 0.5)
        self::assertSame(0.333, $r['score']);
    }

    public function testAcervoVazioOuDemandaSemCodigoDevolveNulo(): void
    {
        self::assertNull(Compatibilidade::competencia(['TOS_1.1.2.1' => 1.0], [], self::PESOS_AFINIDADE)['score']);
        self::assertNull(Compatibilidade::competencia([], $this->acervo([]), self::PESOS_AFINIDADE)['score']);
    }

    // ---------------------------------------------------------------- outras dimensões

    public function testAreaEProporcionalAosGruposPedidos(): void
    {
        self::assertSame(1.0,   Compatibilidade::area([1, 19], [1, 19, 25]));
        self::assertSame(0.5,   Compatibilidade::area([1, 19], [19]));
        self::assertSame(0.0,   Compatibilidade::area([1, 19], [30]));
        self::assertNull(Compatibilidade::area([1], []));
        self::assertNull(Compatibilidade::area([], [1]));
    }

    public function testLocalizacaoDistingueMunicipioDeUf(): void
    {
        $acervo = [['uf' => 'AM', 'municipio' => 'Manaus']];

        self::assertSame(1.0, Compatibilidade::localizacao('AM', 'Manaus', $acervo));
        self::assertSame(1.0, Compatibilidade::localizacao('AM', 'MANAUS', $acervo), 'acento e caixa não podem separar');
        self::assertSame(0.6, Compatibilidade::localizacao('AM', 'Parintins', $acervo));
        self::assertSame(0.0, Compatibilidade::localizacao('SP', 'Santos', $acervo));
        self::assertNull(Compatibilidade::localizacao(null, null, $acervo), 'demanda sem local não penaliza ninguém');
        self::assertNull(Compatibilidade::localizacao('AM', 'Manaus', []));
    }

    public function testExperienciaVinculadaAArtValeMaisQueRelatoSolto(): void
    {
        self::assertSame(1.0, Compatibilidade::experiencia(1, 1));
        self::assertSame(1.0, Compatibilidade::experiencia(3, 1), 'basta um relato amarrado a ART');
        self::assertSame(0.5, Compatibilidade::experiencia(1, 0));
        self::assertNull(Compatibilidade::experiencia(0, 0), 'não declarou nada: sai da média');
    }

    public function testDeclaracaoDeFlexibilidadeCasaComTudo(): void
    {
        self::assertSame(1.0, Compatibilidade::correspondenciaDeclarada('CLT', 'CLT'));
        self::assertSame(0.0, Compatibilidade::correspondenciaDeclarada('CLT', 'PJ'));
        self::assertSame(1.0, Compatibilidade::correspondenciaDeclarada('CLT', 'QUALQUER'));
        self::assertSame(1.0, Compatibilidade::correspondenciaDeclarada('QUALQUER', 'PJ'));
        self::assertNull(Compatibilidade::correspondenciaDeclarada('CLT', null), 'não declarou: sai da média');
        self::assertNull(Compatibilidade::correspondenciaDeclarada(null, 'CLT'), 'demanda não pediu: sai da média');
    }

    // ---------------------------------------------------------------- composição

    public function testDimensaoAusenteSaiDaMediaEmVezDeValerZero(): void
    {
        $soCompetencia = Compatibilidade::compor(
            ['competencia' => 0.8, 'area' => null, 'localizacao' => null,
             'experiencia' => null, 'contrato' => null, 'disponibilidade' => null],
            self::PESOS_DIMENSAO,
        );

        // Se ausente valesse zero, o resultado seria 0.8 * 0.40 = 0.32 e o candidato ficaria
        // abaixo do limiar de 0.35 só por ter perfil incompleto.
        self::assertSame(0.8, $soCompetencia);
    }

    public function testPerfilCompletoPontuaPelaMediaPonderada(): void
    {
        $score = Compatibilidade::compor(
            ['competencia' => 1.0, 'area' => 1.0, 'localizacao' => 0.6,
             'experiencia' => 0.5, 'contrato' => 1.0, 'disponibilidade' => 0.0],
            self::PESOS_DIMENSAO,
        );

        // 0.40 + 0.15 + 0.09 + 0.05 + 0.10 + 0 = 0.79, sobre a soma dos pesos, que é 1.0
        self::assertSame(0.79, $score);
    }

    public function testCandidatoSemNenhumaDimensaoMedivelDevolveNulo(): void
    {
        self::assertNull(Compatibilidade::compor(array_fill_keys(Compatibilidade::DIMENSOES, null), self::PESOS_DIMENSAO));
    }

    public function testScoreNuncaPassaDe1(): void
    {
        $tudoNoMaximo = Compatibilidade::compor(array_fill_keys(Compatibilidade::DIMENSOES, 1.0), self::PESOS_DIMENSAO);

        self::assertSame(1.0, $tudoNoMaximo);
    }

    // ---------------------------------------------------------------- semente

    public function testMesmaSementeDevolveSempreAMesmaOrdem(): void
    {
        $pool = array_map(static fn (int $i): array => ['chave' => "P:{$i}"], range(1, 20));

        $a = Compatibilidade::embaralhar($pool, 'semente-fixa-para-o-teste');
        $b = Compatibilidade::embaralhar($pool, 'semente-fixa-para-o-teste');

        self::assertSame($a, $b, 'a sessão precisa ser reproduzível pelo administrador (12.3)');
    }

    public function testSementesDiferentesDaoOrdensDiferentes(): void
    {
        $pool = array_map(static fn (int $i): array => ['chave' => "P:{$i}"], range(1, 20));

        self::assertNotSame(
            Compatibilidade::embaralhar($pool, 'semente-a'),
            Compatibilidade::embaralhar($pool, 'semente-b'),
        );
    }

    public function testAOrdemNaoSegueOScore(): void
    {
        // Pool já na ordem decrescente de score. Se o embaralhamento respeitasse score, a ordem
        // sairia igual à de entrada, e isso seria ranking (item 10.1).
        $pool = [
            ['chave' => 'P:1', 'score' => 0.95],
            ['chave' => 'P:2', 'score' => 0.85],
            ['chave' => 'P:3', 'score' => 0.75],
            ['chave' => 'P:4', 'score' => 0.65],
            ['chave' => 'P:5', 'score' => 0.55],
            ['chave' => 'P:6', 'score' => 0.45],
        ];

        $embaralhado = Compatibilidade::embaralhar($pool, 'qualquer-semente');

        self::assertNotSame(
            array_column($pool, 'chave'),
            array_column($embaralhado, 'chave'),
        );
        self::assertEqualsCanonicalizing(
            array_column($pool, 'chave'),
            array_column($embaralhado, 'chave'),
            'ninguém pode sumir nem aparecer duas vezes no embaralhamento',
        );
    }

    /**
     * O reforço da CAT acompanha a afinidade (D96): numa atividade que só divide o grupo com a
     * pedida, a certidão empurra pouco; na mesma atividade, empurra os 30% do que falta.
     */
    public function testReforcoDaCatEhProporcionalAAfinidade(): void
    {
        $soGrupo = Compatibilidade::competencia(
            ['TOS_1.6.4' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_1.2.6', 'evi_cat_numero' => '999001/2026']),
            self::PESOS_AFINIDADE,
        );

        // 0,15 + 0,85 × (0,30 × 0,15) = 0,188; antes da D96 eram 0,405.
        self::assertEqualsWithDelta(0.188, $soGrupo['score'], 0.001);

        $mesmoServico = Compatibilidade::competencia(
            ['TOS_1.1.2.1' => 1.0],
            $this->acervo(['evi_tos_codigo' => 'TOS_1.1.2.5', 'evi_cat_numero' => '999001/2026']),
            self::PESOS_AFINIDADE,
        );

        // 0,75 + 0,25 × (0,30 × 0,75) = 0,806.
        self::assertEqualsWithDelta(0.806, $mesmoServico['score'], 0.001);
    }
}
