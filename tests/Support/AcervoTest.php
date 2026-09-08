<?php

declare(strict_types=1);

namespace ProLink\Tests\Support;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ProLink\Support\Acervo;
use RuntimeException;

/**
 * As regras do acervo, sem banco e sem rede: projeção, mescla e selo.
 *
 * É aqui que mora o que a banca pode perguntar — por que reassociar uma ART não apaga o
 * município, e o que exatamente o Selo ART cobre.
 */
final class AcervoTest extends TestCase
{
    /** Resposta de `?p=arts` — valida titularidade, mas não traz local nem atividades. */
    private const VALIDACAO = [
        'pro_nome'             => 'ANA CLARA COSTA',
        'pro_rnp'              => '0412340011',
        'art_numero'           => 'AM20269999001',
        'art_tipo'             => 'OBRA',
        'art_forma_registro'   => 'INICIAL',
        'art_contratante_nome' => 'João Silva LTDA',
        'art_objeto'           => 'Reforma Estrutural',
        'art_situacao'         => 'REGISTRADA',
    ];

    /** Resposta da lista do profissional — a única que traz o local. */
    private const DA_LISTA = [
        'art_numero'           => 'AM20269999001',
        'art_tipo'             => 'OBRA',
        'art_forma_registro'   => 'INICIAL',
        'art_contratante_nome' => 'João Silva LTDA',
        'art_objeto'           => 'Reforma Estrutural',
        'art_local_uf'         => 'AM',
        'art_local_municipio'  => 'Manaus',
        'art_situacao'         => 'REGISTRADA',
    ];

    // ---------------------------------------------------------------- projeção

    #[Test]
    public function campo_que_a_resposta_nao_traz_vira_null(): void
    {
        $linha = Acervo::projetarArt(self::VALIDACAO, '0412340011');

        self::assertNull($linha['art_local_uf']);
        self::assertNull($linha['art_local_municipio']);
        self::assertSame('REGISTRADA', $linha['art_situacao']);
    }

    #[Test]
    public function o_rnp_vem_do_parametro_quando_a_resposta_nao_repete(): void
    {
        // A lista de ARTs não repete o RNP em cada item; a validação repete.
        self::assertSame('0412340011', Acervo::projetarArt(self::DA_LISTA, '0412340011')['art_pro_rnp']);
        self::assertSame('0412340011', Acervo::projetarArt(self::VALIDACAO, 'ignorado')['art_pro_rnp']);
    }

    #[Test]
    public function o_rnp_continua_string_com_o_zero_a_esquerda(): void
    {
        $linha = Acervo::projetarArt(self::DA_LISTA, '0412340011');

        self::assertSame('0412340011', $linha['art_pro_rnp']);
        self::assertIsString($linha['art_pro_rnp']);
    }

    #[Test]
    public function atividades_saem_na_ordem_hierarquica_nao_na_alfabetica(): void
    {
        $atividades = Acervo::projetarAtividades([
            ['tos_codigo' => 'TOS_10.1.1', 'aat_descricao' => 'Execução de obra/serviço'],
            ['tos_codigo' => 'TOS_2.9.2.3', 'aat_descricao' => 'Execução de obra/serviço'],
            ['tos_codigo' => 'TOS_1.1.6',   'aat_descricao' => null],
        ]);

        // Alfabeticamente TOS_10 viria antes de TOS_2. Aqui não.
        self::assertSame(
            ['TOS_1.1.6', 'TOS_2.9.2.3', 'TOS_10.1.1'],
            array_column($atividades, 'tos_codigo'),
        );
    }

    #[Test]
    public function atividade_repetida_no_mesmo_lote_entra_uma_vez(): void
    {
        // uq_ata_art_tos recusaria a segunda; melhor não chegar lá.
        $atividades = Acervo::projetarAtividades([
            ['tos_codigo' => 'TOS_25.2.1', 'aat_descricao' => 'Execução de obra/serviço'],
            ['tos_codigo' => 'TOS_25.2.1', 'aat_descricao' => 'Execução de obra/serviço'],
        ]);

        self::assertCount(1, $atividades);
    }

    // ---------------------------------------------------------------- mescla

    #[Test]
    public function art_nova_e_a_propria_projecao(): void
    {
        $novo = Acervo::projetarArt(self::DA_LISTA, '0412340011');

        self::assertSame($novo, Acervo::mesclar(null, $novo));
    }

    #[Test]
    public function revalidar_uma_art_nao_apaga_o_municipio_que_a_importacao_trouxe(): void
    {
        // A cena real: o profissional importou o acervo (veio local), depois digitou a mesma ART
        // na tela de validação (não vem local). Sem a regra, Manaus sumiria.
        $importada = Acervo::projetarArt(self::DA_LISTA, '0412340011');
        $revalidada = Acervo::projetarArt(self::VALIDACAO, '0412340011');

        $mesclada = Acervo::mesclar($importada, $revalidada);

        self::assertSame('AM', $mesclada['art_local_uf']);
        self::assertSame('Manaus', $mesclada['art_local_municipio']);
    }

    #[Test]
    public function valor_novo_e_preenchido_sobrescreve_o_antigo(): void
    {
        // O contrário também vale: a API mudou a situação da ART, e o acervo tem que acompanhar.
        $antiga = Acervo::projetarArt(self::DA_LISTA, '0412340011');
        $nova   = Acervo::projetarArt(['art_numero' => 'AM20269999001', 'art_situacao' => 'BAIXADA'], '0412340011');

        self::assertSame('BAIXADA', Acervo::mesclar($antiga, $nova)['art_situacao']);
    }

    #[Test]
    public function a_mesma_art_com_outro_rnp_e_conflito_e_nao_mescla(): void
    {
        // uq_art_numero é global: a ART pertence a um profissional só. Sobrescrever em silêncio
        // moveria evidência de uma pessoa para outra.
        $daAna = Acervo::projetarArt(self::DA_LISTA, '0412340011');
        $doJoao = Acervo::projetarArt(self::DA_LISTA, '0412340020');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('já está no acervo do RNP 0412340011');

        Acervo::mesclar($daAna, $doJoao);
    }

    #[Test]
    public function validar_sem_atividades_nao_zera_a_evidencia_tos(): void
    {
        // `?p=arts` não devolve atividade nenhuma. Se isso apagasse a lista, uma revalidação
        // esvaziaria o único campo desta massa que discrimina candidato.
        $existentes = Acervo::projetarAtividades([['tos_codigo' => 'TOS_25.2.1', 'aat_descricao' => 'x']]);

        self::assertSame($existentes, Acervo::mesclarAtividades($existentes, []));
    }

    #[Test]
    public function lista_nova_de_atividades_substitui_a_anterior(): void
    {
        $antes  = Acervo::projetarAtividades([['tos_codigo' => 'TOS_25.2.1']]);
        $depois = Acervo::projetarAtividades([['tos_codigo' => 'TOS_1.1.6']]);

        self::assertSame(['TOS_1.1.6'], array_column(Acervo::mesclarAtividades($antes, $depois), 'tos_codigo'));
    }

    // ---------------------------------------------------------------- selo

    #[Test]
    public function o_mesmo_conteudo_produz_o_mesmo_selo(): void
    {
        $art = Acervo::projetarArt(self::DA_LISTA, '0412340011');
        $ats = Acervo::projetarAtividades([['tos_codigo' => 'TOS_25.2.1', 'aat_descricao' => 'x']]);

        self::assertSame(Acervo::selo($art, $ats), Acervo::selo($art, $ats));
    }

    #[Test]
    public function mexer_num_campo_da_art_quebra_o_selo(): void
    {
        $art = Acervo::projetarArt(self::DA_LISTA, '0412340011');
        $ats = Acervo::projetarAtividades([['tos_codigo' => 'TOS_25.2.1']]);

        $adulterada = $art;
        $adulterada['art_objeto'] = 'Projeto Elétrico e Execução';

        self::assertNotSame(Acervo::selo($art, $ats), Acervo::selo($adulterada, $ats));
    }

    #[Test]
    public function acrescentar_um_codigo_tos_quebra_o_selo(): void
    {
        // O teste que justifica as atividades entrarem no selo. `tos_codigo` é a única coisa que
        // discrimina candidato nesta massa: seria exatamente onde uma adulteração compensaria.
        $art = Acervo::projetarArt(self::DA_LISTA, '0412340011');

        $original  = Acervo::projetarAtividades([['tos_codigo' => 'TOS_25.2.1']]);
        $inflada   = Acervo::projetarAtividades([
            ['tos_codigo' => 'TOS_25.2.1'],
            ['tos_codigo' => 'TOS_11.10.1.4'],   // eletrotécnica que ela nunca executou
        ]);

        self::assertNotSame(Acervo::selo($art, $original), Acervo::selo($art, $inflada));
    }

    #[Test]
    public function a_ordem_das_atividades_nao_muda_o_selo(): void
    {
        $art = Acervo::projetarArt(self::DA_LISTA, '0412340011');

        $umaOrdem  = Acervo::projetarAtividades([['tos_codigo' => 'TOS_1.1.6'], ['tos_codigo' => 'TOS_25.2.1']]);
        $outraOrdem = Acervo::projetarAtividades([['tos_codigo' => 'TOS_25.2.1'], ['tos_codigo' => 'TOS_1.1.6']]);

        self::assertSame(Acervo::selo($art, $umaOrdem), Acervo::selo($art, $outraOrdem));
    }

    #[Test]
    public function o_selo_ignora_o_que_nao_veio_da_api(): void
    {
        // art_id, art_dt_registro e art_status são nossos, não do CREA. Se entrassem no selo,
        // uma exclusão lógica legítima quebraria a conferência.
        $art = Acervo::projetarArt(self::DA_LISTA, '0412340011');
        $ats = Acervo::projetarAtividades([['tos_codigo' => 'TOS_25.2.1']]);

        $comRuido = $art + ['art_id' => 42, 'art_status' => 'X', 'art_dt_registro' => '2026-09-08 20:00:00'];

        self::assertSame(Acervo::selo($art, $ats), Acervo::selo($comRuido, $ats));
    }
}
