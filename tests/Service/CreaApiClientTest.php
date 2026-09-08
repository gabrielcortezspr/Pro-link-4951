<?php

declare(strict_types=1);

namespace ProLink\Tests\Service;

use Generator;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ProLink\Service\ApiIndisponivelException;
use ProLink\Service\CreaApiClient;
use ProLink\Service\NaoEncontradoException;
use ProLink\Support\RespostaHttp;
use ProLink\Support\TransporteCurl;
use ProLink\Support\TransporteFixture;
use ProLink\Tests\Duplo\TransporteStub;
use RuntimeException;

/**
 * O cliente da API, verificado sem rede e sem token (decisão D08).
 *
 * A suíte se divide em duas metades com propósitos distintos:
 *
 *   · Contra `TransporteFixture`, o caminho feliz sobre respostas que a API realmente deu.
 *   · Contra `TransporteStub`, o que a API não foi observada fazendo mas o cliente precisa
 *     tratar: 401, 429, corpo cortado, envelope fora do formato.
 *
 * Dois testes desta suíte guardam a honestidade do próprio fake: eles conferem que o que o
 * `TransporteFixture` deriva para a ART AM20269999001 é idêntico à captura real. Se a derivação
 * mentir, a E2 inteira estaria sendo testada contra ficção.
 */
final class CreaApiClientTest extends TestCase
{
    private function comFixtures(): array
    {
        $transporte = new TransporteFixture();

        return [new CreaApiClient($transporte), $transporte];
    }

    // ---------------------------------------------------------------- busca por documento

    #[Test]
    public function profissional_por_cpf_devolve_o_cadastro_e_as_modalidades(): void
    {
        [$api] = $this->comFixtures();

        $profissional = $api->profissionalPorCpf('12312300109');

        self::assertSame('ANA CLARA COSTA', $profissional['pro_nome']);
        self::assertSame('0412340011', $profissional['pro_rnp']);
        self::assertSame('A', $profissional['pro_status']);
        self::assertSame('Engenharia Florestal', $profissional['modalidades'][0]['mod_nome']);
    }

    #[Test]
    public function cpf_fora_da_base_devolve_null_e_nao_excecao(): void
    {
        [$api] = $this->comFixtures();

        // 200 [] — a chave é válida, nada casou. É reprovação de validação, não erro de sistema:
        // o cadastro segue como Terceiro PF, conforme a E2.
        self::assertNull($api->profissionalPorCpf('98765432100'));
    }

    #[Test]
    public function cpf_chega_ao_transporte_so_com_digitos(): void
    {
        [$api, $transporte] = $this->comFixtures();

        $api->profissionalPorCpf('123.123.001-09');

        self::assertSame('12312300109', $transporte->chamadas()[0]['cpf']);
    }

    #[Test]
    public function empresa_por_cnpj_preserva_os_zeros_a_esquerda(): void
    {
        [$api, $transporte] = $this->comFixtures();

        $empresa = $api->empresaPorCnpj('00123001000123');

        self::assertSame('00123001000123', $transporte->chamadas()[0]['cnpj']);
        self::assertSame('61859', $empresa['emp_registro_crea']);
    }

    // ---------------------------------------------------------------- 404 x 200 []

    #[Test]
    public function rnp_inexistente_levanta_nao_encontrado(): void
    {
        [$api] = $this->comFixtures();

        $this->expectException(NaoEncontradoException::class);
        $this->expectExceptionMessage('Profissional não encontrado');

        $api->artsDoProfissional('9999999999');
    }

    #[Test]
    public function art_que_nao_e_do_profissional_devolve_null(): void
    {
        [$api] = $this->comFixtures();

        // A ART existe na massa, mas é de outra pessoa: 200 []. A RF03 precisa distinguir isto
        // de "o RNP não existe", que é o teste acima.
        self::assertNull($api->validarArt('0412340011', 'AM20269999290'));
    }

    // ---------------------------------------------------------------- honestidade do fake

    #[Test]
    public function a_validacao_derivada_bate_com_a_captura_real_da_api(): void
    {
        [$api] = $this->comFixtures();
        $capturado = json_decode((string) file_get_contents(self::fixture('art_validacao')), true);

        $derivado = $api->validarArt('0412340011', 'AM20269999001');

        self::assertEquals($capturado[0], $derivado);
    }

    #[Test]
    public function as_atividades_derivadas_batem_com_a_captura_real_da_api(): void
    {
        [$api] = $this->comFixtures();
        $capturado = json_decode((string) file_get_contents(self::fixture('art_atividades')), true);

        self::assertEquals($capturado, $api->atividadesDaArt('AM20269999001'));
    }

    #[Test]
    public function recurso_sem_captura_falha_alto_em_vez_de_inventar(): void
    {
        [$api] = $this->comFixtures();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('não sabe responder');

        $api->atividadesDaArt('AM20260000000');
    }

    // ---------------------------------------------------------------- paginação

    #[Test]
    public function o_laco_percorre_todas_as_paginas_e_nao_perde_art(): void
    {
        [$api, $transporte] = $this->comFixtures();

        $arts = iterator_to_array($api->todasArtsDoProfissional('0412340011', limite: 2));

        self::assertCount(4, $arts, 'as 4 ARTs da captura, em 2 páginas de 2');
        self::assertSame(
            ['AM20269999001', 'AM20269999101', 'AM20269999102', 'AM20269999103'],
            array_column($arts, 'art_numero'),
        );
        self::assertCount(2, $transporte->chamadas(), 'uma requisição por página, nem uma a mais');
    }

    #[Test]
    public function uma_pagina_so_custa_uma_requisicao(): void
    {
        [$api, $transporte] = $this->comFixtures();

        iterator_to_array($api->todasArtsDoProfissional('0412340011', limite: 20));

        self::assertCount(1, $transporte->chamadas());
    }

    #[Test]
    public function a_paginacao_das_cats_usa_o_mesmo_laco(): void
    {
        [$api] = $this->comFixtures();

        $cats = iterator_to_array($api->todasCatsDoProfissional('0412340011'));

        self::assertCount(1, $cats);
        self::assertSame('999001/2026', $cats[0]['cat_numero']);
    }

    #[Test]
    public function a_paginacao_derivada_bate_com_a_captura_real_de_duas_paginas(): void
    {
        // A D08 anotou como pendência que o laço de paginação não tinha contra o que ser
        // verificado, porque a captura de 06/09 era página 1 de 1. Em 08/09 capturamos
        // `&limit=2` nas duas páginas: o envelope que o `TransporteFixture` refatia é igual,
        // campo a campo, ao que o servidor devolveu. A aritmética do fake deixa de ser
        // suposição nossa.
        $transporte = new TransporteFixture();

        foreach ([1, 2] as $pagina) {
            $real = json_decode(
                (string) file_get_contents(self::fixture("profissional_arts_limite2_p{$pagina}")),
                true,
            );

            $derivado = json_decode($transporte->get([
                'p'     => 'profissionais/0412340011/arts',
                'page'  => $pagina,
                'limit' => 2,
            ])->corpo, true);

            self::assertEquals($real, $derivado, "página {$pagina} divergiu da captura real");
        }
    }

    #[Test]
    public function envelope_sem_data_e_recusado_em_vez_de_virar_lista_vazia(): void
    {
        // Gravar "este profissional não tem ART nenhuma" por causa de uma mudança de formato da
        // API é pior do que falhar: entra no índice de evidência e some sem ninguém notar.
        $api = new CreaApiClient(TransporteStub::sempre(200, '{"pagina_atual":1,"resultados":[]}'));

        $this->expectException(ApiIndisponivelException::class);
        $this->expectExceptionMessage("sem a chave 'data'");

        iterator_to_array($api->todasArtsDoProfissional('0412340011'));
    }

    #[Test]
    public function paginacao_sem_fim_para_no_teto_em_vez_de_martelar_a_api(): void
    {
        // total_paginas alto e data sempre cheia: sem teto, isto seria um laço infinito contra
        // uma API que registra toda chamada.
        $api = new CreaApiClient(TransporteStub::sempre(
            200,
            '{"pagina_atual":1,"total_paginas":9999,"data":[{"art_numero":"AM1"}]}'
        ));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('passou de 100 páginas');

        iterator_to_array($api->todasArtsDoProfissional('0412340011'));
    }

    // ---------------------------------------------------------------- empresas

    #[Test]
    public function o_cao_vem_com_o_aninhamento_real_e_nao_com_cao_arts(): void
    {
        [$api] = $this->comFixtures();

        $cao = $api->cao('61859');

        self::assertSame('AMAZÔNIA CONSTRUÇÕES E ENGENHARIA LTDA', $cao['emp_razao_social']);
        self::assertArrayNotHasKey('cao_arts', $cao, 'a armadilha documentada no CLAUDE.md');
        self::assertArrayHasKey('arts', $cao['quadro_tecnico'][0]);
        self::assertArrayHasKey('atividades', $cao['quadro_tecnico'][0]['arts'][0]);
        self::assertNotEmpty($cao['quadro_tecnico'][0]['arts'][0]['atividades'][0]['tos_codigo']);
    }

    #[Test]
    public function o_quadro_tecnico_expoe_a_data_de_fim_do_vinculo(): void
    {
        [$api] = $this->comFixtures();

        $quadro = $api->quadroTecnico('61859');

        // Vínculo vigente vem null, não string vazia. É o que permite não herdar acervo de
        // vínculo encerrado, regra que o CAO sozinho não deixa aplicar.
        self::assertArrayHasKey('qut_dt_fim', $quadro[0]);
        self::assertNull($quadro[0]['qut_dt_fim']);
        self::assertSame('2020-01-01', $quadro[0]['qut_dt_inicio']);
    }

    // ---------------------------------------------------------------- montagem da URL

    #[Test]
    public function a_barra_do_numero_da_cat_vira_percent_2f(): void
    {
        $url = TransporteCurl::url('https://exemplo/api/v1/', [
            'p'          => 'cats',
            'rnp'        => '0412340011',
            'cat_numero' => '999001/2026',
        ]);

        self::assertStringContainsString('cat_numero=999001%2F2026', $url);
        self::assertStringContainsString('rnp=0412340011', $url, 'o zero à esquerda sobrevive');
    }

    #[Test]
    public function a_cat_com_barra_e_encontrada_pelo_cliente(): void
    {
        [$api] = $this->comFixtures();

        $cat = $api->validarCat('0412340011', '999001/2026');

        self::assertSame('999001/2026', $cat['cat_numero']);
        self::assertCount(4, $cat['arts'], 'a CAT agrupa as 4 ARTs da profissional');
    }

    // ---------------------------------------------------------------- falhas de transporte

    #[Test]
    public function token_recusado_vira_api_indisponivel(): void
    {
        $api = new CreaApiClient(TransporteStub::sempre(401, '{"error":"Unauthorized"}'));

        $this->expectException(ApiIndisponivelException::class);
        $this->expectExceptionMessage('Token da API recusado');

        $api->profissionalPorCpf('12312300109');
    }

    #[Test]
    public function limite_de_requisicoes_vira_api_indisponivel(): void
    {
        $api = new CreaApiClient(TransporteStub::sempre(429, '{"error":"Too Many Requests"}'));

        $this->expectException(ApiIndisponivelException::class);
        $this->expectExceptionMessage('Limite de requisições');

        $api->profissionalPorCpf('12312300109');
    }

    #[Test]
    public function corpo_nao_json_vira_api_indisponivel(): void
    {
        // Página de manutenção, proxy no meio, resposta cortada: nada disso pode virar exceção
        // de PHP no meio de um cadastro.
        $api = new CreaApiClient(TransporteStub::sempre(200, '<html>Manutenção</html>'));

        $this->expectException(ApiIndisponivelException::class);
        $this->expectExceptionMessage('não-JSON');

        $api->profissionalPorCpf('12312300109');
    }

    #[Test]
    public function o_cliente_com_fixtures_dispensa_credencial(): void
    {
        // A prova de que a suíte roda em máquina sem token são estes dois testes juntos: o
        // transporte de rede se recusa a existir sem credencial (abaixo), e este aqui monta o
        // cliente inteiro sem passar por ele. Nada aqui lê API_TOKEN — asserção sobre o valor
        // da constante passaria ou falharia conforme o .env da máquina, que não é o assunto.
        [$api] = $this->comFixtures();

        self::assertNotNull($api->profissionalPorCpf('12312300109'));
    }

    #[Test]
    public function o_transporte_de_rede_recusa_subir_sem_token(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PROLINK_API_TOKEN');

        new TransporteCurl(token: '');
    }

    private static function fixture(string $nome): string
    {
        return dirname(__DIR__, 2) . '/fixtures/' . $nome . '.json';
    }
}
