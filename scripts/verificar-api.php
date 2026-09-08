<?php

declare(strict_types=1);

/**
 * Verificação do `CreaApiClient` contra a API oficial, de verdade (RF02, edital 8.4).
 *
 * O `composer test` prova que o cliente lê certo as respostas gravadas em `fixtures/`. Este
 * script prova a outra metade: que a API de hoje ainda responde como as fixtures dizem, e que o
 * cliente fala com ela sem intermediário. É o par do `verificar-e1.php` — mesma ideia da D12,
 * critério de pronto que se executa em vez de se ler.
 *
 *     docker compose exec php php scripts/verificar-api.php
 *
 * ## Quantas chamadas isto custa
 *
 * Uma por endpoint documentado, mais os casos-limite: **quinze**, com pausa entre elas. Os
 * sujeitos são fixos e vêm da massa oficial — a mesma profissional e a mesma empresa das
 * fixtures. Não há laço sobre a massa, e não existe motivo para rodar isto em série: o item 10.4
 * do edital proíbe coleta automatizada, e a organização registra toda chamada. Rode quando
 * quiser confirmar que nada mudou do lado deles, não em CI.
 *
 * A empresa usada é a AMAZÔNIA (`00123001000123`), uma das quinze da massa cujo CNPJ tem dígito
 * verificador válido (D07). As outras 85 nem chegariam ao cadastro da plataforma.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Service\ApiIndisponivelException;
use ProLink\Service\CreaApiClient;
use ProLink\Service\NaoEncontradoException;
use ProLink\Support\TransporteCurl;

const CPF        = '12312300109';        // ANA CLARA COSTA
const RNP        = '0412340011';
const CNPJ       = '00123001000123';     // AMAZÔNIA CONSTRUÇÕES — DV válido
const REGISTRO   = '61859';
const ART        = 'AM20269999001';
const ART_ALHEIA = 'AM20269999290';      // existe na massa, é de outra pessoa
const CAT        = '999001/2026';

$aprovado = 0;
$falhou   = 0;
$pausa    = 400_000;   // 0,4 s entre chamadas: educação com uma API que registra tudo

function conferir(string $descricao, bool $condicao, string $detalhe = ''): void
{
    global $aprovado, $falhou;

    if ($condicao) {
        $aprovado++;
        printf("  \e[32mok\e[0m    %s\n", $descricao);

        return;
    }

    $falhou++;
    printf("  \e[31mFALHOU\e[0m %s%s\n", $descricao, $detalhe !== '' ? "  ({$detalhe})" : '');
}

function secao(string $titulo): void
{
    printf("\n\e[1m%s\e[0m\n", $titulo);
}

/** Compara a resposta de hoje com a fixture gravada, e diz o que mudou. */
function contraFixture(string $rotulo, string $fixture, mixed $hoje): void
{
    $caminho = dirname(__DIR__) . '/fixtures/' . $fixture . '.json';
    $gravado = json_decode((string) file_get_contents($caminho), true);

    if ($gravado == $hoje) {
        conferir("{$rotulo}: idêntica à fixture {$fixture}.json", true);

        return;
    }

    conferir("{$rotulo}: idêntica à fixture {$fixture}.json", false, 'a API mudou — recapture');
    printf("        gravado: %s\n", substr(json_encode($gravado, JSON_UNESCAPED_UNICODE), 0, 160));
    printf("        hoje:    %s\n", substr(json_encode($hoje, JSON_UNESCAPED_UNICODE), 0, 160));
}

try {
    $api = new CreaApiClient(new TransporteCurl());
} catch (RuntimeException $e) {
    exit("PROLINK_API_TOKEN não está no .env: " . $e->getMessage() . "\n");
}

printf("\e[1mVerificação da API oficial\e[0m — %s\n", API_BASE);
printf("Sujeitos: CPF %s · RNP %s · CNPJ %s · registro %s\n", CPF, RNP, CNPJ, REGISTRO);

try {
    // ---------------------------------------------------------------- profissionais
    secao('Profissionais');

    $profissional = $api->profissionalPorCpf(CPF);
    usleep($pausa);

    conferir('busca por CPF responde', $profissional !== null);
    conferir('pro_rnp preserva o zero à esquerda', ($profissional['pro_rnp'] ?? '') === RNP);
    conferir('pro_status vem só nesta busca, e vem "A"', ($profissional['pro_status'] ?? '') === 'A');
    conferir('o CPF não volta na resposta', !array_key_exists('pro_cpf', $profissional));
    contraFixture('profissional', 'profissional_cpf', [$profissional]);

    $arts = iterator_to_array($api->todasArtsDoProfissional(RNP, limite: 20));
    usleep($pausa);

    conferir('o laço de paginação traz as 4 ARTs da profissional', count($arts) === 4,
        count($arts) . ' vieram');
    conferir('a ART traz local, que só este endpoint dá',
        ($arts[0]['art_local_uf'] ?? '') === 'AM' && ($arts[0]['art_local_municipio'] ?? '') !== '');
    conferir('a ART traz as atividades TOS embutidas',
        isset($arts[0]['atividades'][0]['tos_codigo']));

    $cats = iterator_to_array($api->todasCatsDoProfissional(RNP));
    usleep($pausa);

    conferir('CATs do profissional respondem', count($cats) >= 1);
    conferir('a lista de CATs não traz as ARTs agrupadas', !isset($cats[0]['arts']));

    // ---------------------------------------------------------------- empresas
    secao('Empresas e CAO');

    $empresa = $api->empresaPorCnpj(CNPJ);
    usleep($pausa);

    conferir('busca por CNPJ responde', $empresa !== null);
    conferir('emp_cnpj preserva os zeros à esquerda', ($empresa['emp_cnpj'] ?? '') === CNPJ);
    conferir('emp_registro_crea é o que abre o quadro técnico e o CAO',
        ($empresa['emp_registro_crea'] ?? '') === REGISTRO);
    contraFixture('empresa', 'empresa_cnpj', [$empresa]);

    $quadro = $api->quadroTecnico(REGISTRO);
    usleep($pausa);

    conferir('quadro técnico responde', count($quadro) >= 1);
    conferir('qut_dt_fim existe e vem null quando o vínculo está vigente',
        array_key_exists('qut_dt_fim', $quadro[0]) && $quadro[0]['qut_dt_fim'] === null);
    conferir('qut_dt_inicio é date, não datetime',
        (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $quadro[0]['qut_dt_inicio']));
    contraFixture('quadro técnico', 'empresa_quadro_tecnico', $quadro);

    $cao = $api->cao(REGISTRO);
    usleep($pausa);

    conferir('CAO é objeto plano, com os campos da empresa na raiz',
        isset($cao['emp_cnpj'], $cao['emp_razao_social']));
    conferir('CAO aninha quadro_tecnico → profissional → arts → atividades',
        isset($cao['quadro_tecnico'][0]['arts'][0]['atividades'][0]['tos_codigo']));
    conferir('não existe a chave cao_arts que a documentação sugeria',
        !array_key_exists('cao_arts', $cao));
    conferir('o CAO não traz o local da ART (o endpoint do profissional traz)',
        !array_key_exists('art_local_uf', $cao['quadro_tecnico'][0]['arts'][0]));
    conferir('o CAO não traz qut_dt_fim (o quadro-tecnico traz)',
        !array_key_exists('qut_dt_fim', $cao['quadro_tecnico'][0]));
    contraFixture('CAO', 'empresa_cao', $cao);

    // ---------------------------------------------------------------- validação de documentos
    secao('Validação de documentos (a distinção de que a RF03 depende)');

    $validacao = $api->validarArt(RNP, ART);
    usleep($pausa);

    conferir('ART do titular confere', ($validacao['art_numero'] ?? '') === ART);
    conferir('a validação não traz atividades nem local',
        !isset($validacao['atividades']) && !isset($validacao['art_local_uf']));
    contraFixture('validação de ART', 'art_validacao', [$validacao]);

    $alheia = $api->validarArt(RNP, ART_ALHEIA);
    usleep($pausa);

    conferir('ART de outra pessoa devolve 200 [] e vira null, não exceção', $alheia === null);

    $atividades = $api->atividadesDaArt(ART);
    usleep($pausa);

    conferir('atividades da ART respondem', isset($atividades[0]['tos_codigo']));
    conferir('aat_descricao não discrimina ninguém nesta massa',
        ($atividades[0]['aat_descricao'] ?? '') === 'Execução de obra/serviço');
    contraFixture('atividades da ART', 'art_atividades', $atividades);

    $cat = $api->validarCat(RNP, CAT);
    usleep($pausa);

    conferir('CAT com barra no número é encontrada (%2F na URL)',
        ($cat['cat_numero'] ?? '') === CAT);
    conferir('esta busca traz as ARTs agrupadas pela CAT, com atividades',
        isset($cat['arts'][0]['atividades'][0]['tos_codigo']));

    // ---------------------------------------------------------------- TOS
    secao('Tabela de Obras e Serviços');

    $tos = $api->tos('edificação', 1, 5);
    usleep($pausa);

    conferir('busca textual na TOS responde envelope paginado',
        isset($tos['data'], $tos['total_registros'], $tos['total_paginas']));
    conferir('tos_codigo tem o formato hierárquico',
        (bool) preg_match('/^TOS_[\d.]+$/', (string) ($tos['data'][0]['tos_codigo'] ?? '')));

    // ---------------------------------------------------------------- casos-limite
    secao('Casos-limite: 404 é diferente de 200 []');

    try {
        $api->artsDoProfissional('9999999999');
        conferir('RNP inexistente levanta NaoEncontrado', false, 'não levantou');
    } catch (NaoEncontradoException) {
        conferir('RNP inexistente levanta NaoEncontrado (404)', true);
    }
    usleep($pausa);

    conferir('CPF fora da base devolve null, não exceção (200 [])',
        $api->profissionalPorCpf('98765432100') === null);
    usleep($pausa);

    // Era a pergunta em aberto da D14 — 404 ou 200 []? Respondida na primeira execução deste
    // script, em 08/09: 404. Fica aqui como regressão, porque é a distinção da qual a RF03
    // depende e uma mudança silenciosa do lado deles passaria despercebida.
    secao('ART inexistente em /atividades (confirmado 404 em 08/09)');

    try {
        $resposta = $api->atividadesDaArt('AM20260000000');
        conferir('responde 200 com lista vazia', $resposta === [],
            'veio: ' . substr(json_encode($resposta, JSON_UNESCAPED_UNICODE), 0, 80));
        printf("        \e[33m→ comportamento observado: 200 %s\e[0m\n",
            json_encode($resposta, JSON_UNESCAPED_UNICODE));
    } catch (NaoEncontradoException $e) {
        conferir('responde 404 (identificador inexistente)', true);
        printf("        \e[33m→ comportamento observado: 404 \"%s\"\e[0m\n", $e->getMessage());
    }
} catch (ApiIndisponivelException $e) {
    printf("\n\e[31mAPI indisponível\e[0m: %s\n", $e->getMessage());
    exit(2);
}

printf(
    "\n%s  %d aprovadas, %d falharam\n",
    $falhou === 0 ? "\e[32mAPI VERIFICADA\e[0m" : "\e[31mAPI COM DIVERGÊNCIA\e[0m",
    $aprovado,
    $falhou,
);

exit($falhou === 0 ? 0 : 1);
