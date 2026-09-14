<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ProLink\Support\Cao;

/**
 * A leitura da Certidão de Acervo Operacional e do quadro técnico, sem banco e sem rede.
 *
 * Dois fatos que a banca pode perguntar moram aqui: por que o CAO precisa ser achatado (o
 * profissional contém as ARTs, e não o contrário) e por que ele **não** basta para montar o
 * quadro técnico (não traz `qut_dt_fim`, que é o campo de que a herança depende — D19).
 *
 * A parte que confere a estrutura usa a captura real de `fixtures/`, e não um literal escrito
 * aqui: se a API mudar de formato e a fixture for recapturada, é este teste que avisa.
 */
final class CaoTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fixture(string $nome): array
    {
        $bruto = file_get_contents(PATH_FIXTURES . '/' . $nome . '.json');

        self::assertIsString($bruto, "Fixture ausente: {$nome}.json");

        return json_decode($bruto, true);
    }

    // ---------------------------------------------------------------- estrutura real

    #[Test]
    public function o_profissional_contem_as_arts_e_nao_existe_chave_cao_arts(): void
    {
        $cao = self::fixture('empresa_cao');

        $this->assertArrayNotHasKey('cao_arts', $cao,
            'A documentação da organização sugere cao_arts; a API não devolve isso.');
        $this->assertArrayHasKey('quadro_tecnico', $cao);
        $this->assertArrayHasKey('arts', $cao['quadro_tecnico'][0]);
    }

    #[Test]
    public function o_cao_nao_traz_a_data_de_fim_do_vinculo(): void
    {
        // É o motivo inteiro de o quadro técnico vir do outro endpoint. Montá-lo pelo CAO
        // gravaria todo vínculo como vigente, inclusive os encerrados, e a herança de acervo
        // passaria a valer para quem saiu da empresa.
        foreach (self::fixture('empresa_cao')['quadro_tecnico'] as $profissional) {
            $this->assertArrayNotHasKey('qut_dt_fim', $profissional);
        }

        $this->assertArrayHasKey('qut_dt_fim', self::fixture('empresa_quadro_tecnico')[0]);
    }

    // ---------------------------------------------------------------- achatamento

    #[Test]
    public function achata_a_arvore_e_cada_art_sai_sabendo_de_quem_e(): void
    {
        $acervo = Cao::acervo(self::fixture('empresa_cao'));

        $this->assertCount(4, $acervo);

        foreach ($acervo as $item) {
            $this->assertSame('0412340011', $item['rnp']);
            $this->assertArrayHasKey('art_numero', $item['art']);
        }

        $this->assertSame('AM20269999001', $acervo[0]['art']['art_numero']);
        $this->assertCount(1, $acervo[0]['atividades']);
        $this->assertCount(2, $acervo[1]['atividades']);
    }

    #[Test]
    public function o_rnp_do_cao_preserva_o_zero_a_esquerda(): void
    {
        $acervo = Cao::acervo(self::fixture('empresa_cao'));

        $this->assertIsString($acervo[0]['rnp']);
        $this->assertStringStartsWith('0', $acervo[0]['rnp']);
    }

    #[Test]
    public function cao_sem_quadro_tecnico_devolve_acervo_vazio(): void
    {
        $this->assertSame([], Cao::acervo([]));
        $this->assertSame([], Cao::acervo(['quadro_tecnico' => []]));
        $this->assertSame([], Cao::acervo(['quadro_tecnico' => null]));
    }

    #[Test]
    public function profissional_sem_rnp_e_art_sem_numero_sao_descartados(): void
    {
        // Sem esses dois campos não há linha em crea_arts para gravar. Derrubar a importação
        // inteira por causa de um item malformado custaria o acervo todo da empresa.
        $acervo = Cao::acervo([
            'quadro_tecnico' => [
                ['pro_nome' => 'SEM RNP', 'arts' => [['art_numero' => 'AM1']]],
                ['pro_rnp' => '0412340011', 'arts' => [
                    ['art_objeto' => 'sem número'],
                    ['art_numero' => 'AM2'],
                ]],
            ],
        ]);

        $this->assertCount(1, $acervo);
        $this->assertSame('AM2', $acervo[0]['art']['art_numero']);
        $this->assertSame([], $acervo[0]['atividades']);
    }

    #[Test]
    public function o_registro_da_certidao_e_conferivel(): void
    {
        $this->assertSame('61859', Cao::registroCrea(self::fixture('empresa_cao')));
        $this->assertNull(Cao::registroCrea([]));
        $this->assertNull(Cao::registroCrea(['emp_registro_crea' => '']));
    }

    // ---------------------------------------------------------------- quadro técnico

    #[Test]
    public function projeta_o_vinculo_com_os_campos_que_so_este_endpoint_da(): void
    {
        $vinculos = Cao::vinculos(self::fixture('empresa_quadro_tecnico'));

        $this->assertSame([[
            'pro_rnp'   => '0412340011',
            'pro_nome'  => 'ANA CLARA COSTA',
            'tipo'      => 'R',
            'funcao'    => 'Responsável Técnico',
            'dt_inicio' => '2020-01-01',
            'dt_fim'    => null,
        ]], $vinculos);
    }

    #[Test]
    public function vinculo_vigente_tem_dt_fim_nula_e_nao_string_vazia(): void
    {
        // `'' === null` é falso em toda comparação que a view crea_evidencias faz, e é a
        // diferença entre herdar o acervo e não herdar.
        $vinculos = Cao::vinculos([
            ['pro_rnp' => '1', 'qut_dt_fim' => null],
            ['pro_rnp' => '2', 'qut_dt_fim' => ''],
            ['pro_rnp' => '3'],
        ]);

        foreach ($vinculos as $vinculo) {
            $this->assertNull($vinculo['dt_fim']);
        }
    }

    #[Test]
    public function data_com_hora_vira_data_e_lixo_vira_nulo(): void
    {
        $vinculos = Cao::vinculos([
            ['pro_rnp' => '1', 'qut_dt_inicio' => '2020-01-01 08:30:00'],
            ['pro_rnp' => '2', 'qut_dt_inicio' => 'ontem'],
        ]);

        $this->assertSame('2020-01-01', $vinculos[0]['dt_inicio']);
        $this->assertNull($vinculos[1]['dt_inicio']);
    }

    #[Test]
    public function linha_sem_rnp_nao_vira_vinculo(): void
    {
        $this->assertSame([], Cao::vinculos([['pro_nome' => 'SEM RNP'], 'texto solto']));
    }

    #[Test]
    public function texto_em_branco_vira_nulo_e_nao_espaco(): void
    {
        $vinculos = Cao::vinculos([['pro_rnp' => '1', 'pro_nome' => '   ', 'qut_funcao' => '']]);

        $this->assertNull($vinculos[0]['pro_nome']);
        $this->assertNull($vinculos[0]['funcao']);
    }
}
