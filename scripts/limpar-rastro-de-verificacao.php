<?php

declare(strict_types=1);

/**
 * Tira da vista o rastro que os verificadores deixam, sem apagar nada.
 *
 * `verificar-e4.php` publica "Demandas de verificação" na vitrine, `verificar-e6.php` abre
 * denúncias de verificação na fila de moderação, e a suíte do navegador (`e2e/`) publica demandas
 * pela Alfa Engenharia, lança experiências nos perfis de demonstração e abre denúncias, a cada
 * execução. É assim que eles provam o fluxo
 * de verdade (D66), e o custo é esse lixo aparecer na demonstração: a vitrine abria com 75 demandas
 * de teste, e a fila do administrador com 39 denúncias pendentes.
 *
 * O script resolve pelo mesmo caminho da tela, e nada é apagado (item 8.6j):
 *
 * - **demanda de verificação** é encerrada pelo `DemandaService::encerrar`, o botão da dona;
 * - **denúncia de verificação** é tratada como improcedente pelo `DenunciaService::tratar`, a
 *   providência que não atinge ninguém, com o administrador como moderador e a trilha registrando;
 * - **experiência de teste** é excluída pelo `ExperienciaService::excluir`, em nome do titular,
 *   que é o botão "Excluir" do perfil (exclusão lógica).
 *
 * Para não alcançar dado real, toda regra exige as duas coisas ao mesmo tempo: a conta que criou
 * é uma das que os verificadores e a suíte usam, e o texto tem exatamente o formato que eles
 * escrevem (a suíte acrescenta um carimbo numérico, que as expressões abaixo exigem).
 *
 * Rodar depois de qualquer bateria (`verificar-tudo.sh`) e antes de uma demonstração.
 *
 * Uso:
 *   docker compose exec php php scripts/limpar-rastro-de-verificacao.php --simular
 *   docker compose exec php php scripts/limpar-rastro-de-verificacao.php
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\DemandaRepository;
use ProLink\Repository\DenunciaRepository;
use ProLink\Repository\ExperienciaRepository;
use ProLink\Repository\ProfissionalRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\DemandaService;
use ProLink\Service\DenunciaService;
use ProLink\Service\ExperienciaService;

$simular = isset(getopt('', ['simular'])['simular']);

// As contas que a suíte do navegador usa (`e2e/apoio/contas.js`).
const CONTAS_DA_SUITE = [
    'alfa.engenharia.e.consultoria.ltda.0145@prolink.local',
    'pedro.alves@prolink.local',
    'sophia.martins.0702@prolink.local',
    'arthur.gomes.0613@prolink.local',
];

/** As contas que os verificadores e a suíte usam para criar dado. */
function eDeVerificacao(string $email): bool
{
    return in_array($email, ['camila@prolink.local', 'cobaia@prolink.local', ...CONTAS_DA_SUITE], true)
        || str_ends_with($email, '@verificacao.local');
}

// Os textos exatos das denúncias: `verificar-e6.php` e os cenários 5 e de demonstração da suíte.
const DENUNCIAS_DE_VERIFICACAO = [
    'Denúncia de verificação automática.',
    'Fila de verificação.',
    'Bloqueio de verificação.',
    'Tentativa de bloquear administração.',
    'Denúncia registrada pela suíte de ponta a ponta para provar o cenário 5 do Anexo I. Alvo é a demanda criada pela própria suíte.',
    'Registro de demonstração: a demanda descreve escopo que não corresponde ao objeto anunciado.',
];

// Os títulos que a suíte gera, sempre com o carimbo numérico de cada execução.
const DEMANDAS_DA_SUITE = [
    '/^Plano de intervenção urbana, verificação \\d+$/u',
    '/^Plano de intervenção urbana, área central de Manaus \\(\\d+\\)$/u',
];

const EXPERIENCIAS_DA_SUITE = [
    '/^Experiência de verificação automática \\d+$/u',
    '/^Plano de intervenção urbana em área central \\(\\d+\\)$/u',
];

/** @param list<string> $padroes */
function casa(string $texto, array $padroes): bool
{
    foreach ($padroes as $padrao) {
        if (preg_match($padrao, $texto) === 1) {
            return true;
        }
    }

    return false;
}

$usuarios = new UsuarioRepository();
$admin    = $usuarios->porEmail('camila@prolink.local');

if ($admin === null || $admin['per_codigo'] !== PERFIL_ADMIN) {
    fwrite(STDERR, "A conta de administração camila@prolink.local não existe: rode criar-admin.php.\n");
    exit(1);
}

printf("\e[1mLimpando o rastro de verificação\e[0m  %s\n\n",
    $simular ? "\e[33mSIMULAÇÃO, nada é gravado\e[0m" : 'pelos serviços da plataforma');

// ------------------------------------------------------------------ demandas

$demandas = 0;

$alfa   = $usuarios->porEmail(CONTAS_DA_SUITE[0]);
$donas  = [(int) $admin['usu_id'] => 'verificador'];

if ($alfa !== null) {
    $donas[(int) $alfa['usu_id']] = 'suite';
}

foreach ($donas as $donaId => $origem) {
    foreach ((new DemandaRepository())->doUsuario($donaId) as $d) {
        $titulo = (string) $d['dem_titulo'];
        $deTeste = $origem === 'verificador'
            ? str_starts_with($titulo, 'Demanda de verificação')
            : casa($titulo, DEMANDAS_DA_SUITE);

        if (!$deTeste || !in_array($d['dem_situacao'], ['ABERTA', 'COM_INTERESSADOS'], true)) {
            continue;
        }

        if (!$simular) {
            (new DemandaService())->encerrar($donaId, (int) $d['dem_id']);
        }

        $demandas++;
    }
}

// ------------------------------------------------------------------ denúncias

$denuncias = 0;
$moderacao = new DenunciaService();

foreach ((new DenunciaRepository())->abertasComDescricao(DENUNCIAS_DE_VERIFICACAO) as $den) {
    if (!eDeVerificacao($den['usu_email'])) {
        continue;
    }

    if (!$simular) {
        $moderacao->tratar($den['den_id'], (int) $admin['usu_id'], 'RESOLVIDA', 'IMPROCEDENTE');
    }

    $denuncias++;
}

// ------------------------------------------------------------------ experiências

$experiencias = 0;

foreach (array_slice(CONTAS_DA_SUITE, 1) as $email) {
    $titular = $usuarios->porEmail($email);
    $perfil  = $titular === null ? null : (new ProfissionalRepository())->porUsuario((int) $titular['usu_id']);

    if ($perfil === null) {
        continue;
    }

    foreach ((new ExperienciaRepository())->porProfissional((int) $perfil['prf_id']) as $x) {
        if (!casa((string) $x['exp_titulo'], EXPERIENCIAS_DA_SUITE)) {
            continue;
        }

        if (!$simular) {
            (new ExperienciaService())->excluir((int) $titular['usu_id'], (int) $x['exp_id']);
        }

        $experiencias++;
    }
}

printf("  %d demanda(s) de verificação %s\n", $demandas, $simular ? 'seriam encerradas' : 'encerradas');
printf("  %d experiência(s) de teste %s\n", $experiencias, $simular ? 'seriam excluídas' : 'excluídas');
printf("  %d denúncia(s) de verificação %s\n", $denuncias, $simular ? 'seriam tratadas como improcedentes' : 'tratadas como improcedentes');
printf("\n\e[32mPRONTO\e[0m\n");
