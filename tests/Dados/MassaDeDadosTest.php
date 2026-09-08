<?php

declare(strict_types=1);

namespace ProLink\Tests\Dados;

use PHPUnit\Framework\TestCase;
use ProLink\Support\Documento;

/**
 * Guardião de um fato sobre a massa fictícia do desafio: quantos documentos dela sobrevivem à
 * validação da plataforma.
 *
 * A plataforma valida CPF e CNPJ por formato e dígito verificador, como qualquer cadastro
 * brasileiro deve. A massa respeita isso nos CPFs e não respeita em 85 dos 100 CNPJs, então 85
 * das empresas do desafio não se cadastram. Não é algo a consertar na validação: é defeito do
 * dado que nos deram, e a consequência prática é que os cenários de demonstração só podem usar
 * as 15 empresas listadas em docs/massa-de-dados.md.
 *
 * Este teste existe porque esse número entra em decisão de roteiro. Se a organização corrigir a
 * massa, ou se alguém mexer em Documento, `composer test` avisa antes da demonstração.
 */
final class MassaDeDadosTest extends TestCase
{
    private const CNPJS_CADASTRAVEIS = 15;

    /** @return list<array<string, string>> */
    private static function lerCsv(string $nome): array
    {
        $arquivo = fopen(PATH_DATA . '/csv/' . $nome, 'rb');
        self::assertNotFalse($arquivo, "massa ausente: data/csv/{$nome}");

        $cabecalho = fgetcsv($arquivo, null, ',', '"', '');
        $linhas    = [];

        while (($campos = fgetcsv($arquivo, null, ',', '"', '')) !== false) {
            if ($campos !== [null]) {
                $linhas[] = array_combine($cabecalho, $campos);
            }
        }

        fclose($arquivo);

        return $linhas;
    }

    public function testTodoCpfDaMassaEhCadastravel(): void
    {
        $profissionais = self::lerCsv('profissionais.csv');
        self::assertCount(100, $profissionais);

        $recusados = array_values(array_filter(
            $profissionais,
            static fn (array $p): bool => !Documento::ehCpf($p['cpf'])
        ));

        self::assertSame([], array_column($recusados, 'cpf'),
            'os 100 CPFs da massa fecham o dígito verificador; nenhum deveria ser recusado');
    }

    public function testApenasQuinzeCnpjsDaMassaSaoCadastraveis(): void
    {
        $empresas = self::lerCsv('empresas.csv');
        self::assertCount(100, $empresas);

        $cadastraveis = array_values(array_filter(
            $empresas,
            static fn (array $e): bool => Documento::ehCnpj($e['cnpj'])
        ));

        self::assertCount(self::CNPJS_CADASTRAVEIS, $cadastraveis,
            'mudou a massa ou mudou Documento: atualize docs/massa-de-dados.md e o roteiro da demo');
    }

    /**
     * Amarra a lista, não só a contagem. AMAZÔNIA é a empresa das fixtures de CAO e de CNPJ, e
     * precisa continuar cadastrável; ELETRONORTE e GÊNESIS têm fixture de CAO mas DV quebrado,
     * então servem para testar o parser e nunca como sujeito de demonstração.
     */
    public function testEmpresasDasFixturesEstaoDoLadoEsperado(): void
    {
        self::assertTrue(Documento::ehCnpj('00123001000123'), 'AMAZÔNIA, fixtures/empresa_cao.json');
        self::assertFalse(Documento::ehCnpj('00123021000104'), 'ELETRONORTE, fixtures/cao_66897.json');
        self::assertFalse(Documento::ehCnpj('00123092000100'), 'GÊNESIS, fixtures/cao_82940.json');
    }

    /** O RNP é string com zero à esquerda — convertê-lo a número faz a API devolver 404. */
    public function testRnpDaMassaPreservaOZeroAEsquerda(): void
    {
        foreach (self::lerCsv('profissionais.csv') as $profissional) {
            self::assertMatchesRegularExpression('/^0\d{9}$/', $profissional['rnp']);
        }
    }
}
