<?php

declare(strict_types=1);

/**
 * Preenche resumo, preferências e experiência declarada dos candidatos semeados.
 *
 * **O problema que resolve.** O motor compara seis dimensões (item 3.2), mas quatro delas saem
 * "Não medida" para quase todo mundo na massa: `prf_resumo`, `prf_tipo_contrato` e
 * `prf_disponibilidade` nascem nulos, e ninguém tem experiência lançada. O feed fica honesto e
 * mostra um motor de seis dimensões rodando com duas — que é a pior leitura possível de uma tela
 * cujo argumento é justamente o critério explicável (12.3 e Anexo VI).
 *
 * Não é maquiagem: a API oficial não devolve nenhum desses campos, porque são **autodeclarados**.
 * Num uso real quem os preenche é o titular, no formulário do próprio perfil. Aqui o script faz o
 * papel dele, pelos mesmos serviços que a tela chama — com validação, auditoria e vocabulário
 * fechado. Inserir direto no banco pularia a validação e a trilha, e produziria perfil que a
 * aplicação nunca teria aceitado.
 *
 * **Nem todo mundo é preenchido, de propósito.** Perfil incompleto é estado legítimo e o motor
 * sabe tirar da média o que não foi declarado (dimensão sem dado não vale zero). Se todos os
 * candidatos tivessem as seis dimensões, a demonstração nunca mostraria o comportamento que
 * protege quem está começando — e é sobre isso a sinalização de perfil em construção.
 *
 * Determinístico pelo id do usuário, e idempotente: `salvar()` compara com o estado anterior e
 * devolve `false` sem gravar quando nada mudou; a experiência só é criada se o profissional ainda
 * não tiver nenhuma.
 *
 * Uso:
 *   docker compose exec php php scripts/preencher-declarados-demo.php
 *   docker compose exec php php scripts/preencher-declarados-demo.php --simular
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Service\ExperienciaService;
use ProLink\Service\PreferenciaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Database;
use ProLink\Support\Preferencias;

$opcoes  = getopt('', ['simular']);
$simular = isset($opcoes['simular']);

$pdo          = Database::conexao();
$preferencias = new PreferenciaService();
$experiencias = new ExperienciaService();

/**
 * Resumos escritos na primeira pessoa e sem superlativo.
 *
 * Texto autodeclarado que se gaba seria exatamente o que a proposta diz combater — "plataformas
 * existentes operam com autodeclaração: o profissional diz o que sabe, e a empresa acredita (ou
 * não)". O resumo aqui diz o que a pessoa faz, e quem confere é o acervo ao lado.
 */
const RESUMOS = [
    'Atuo com projeto e acompanhamento de execução, com atenção a prazo e a conformidade do registro.',
    'Trabalho com laudo técnico e vistoria, e acompanho a execução até a emissão do documento final.',
    'Atendo obra de pequeno e médio porte, com responsabilidade técnica desde o projeto.',
    'Faço consultoria e compatibilização de projeto, presencial na região metropolitana.',
    'Atuo em produção e manejo, com acompanhamento técnico em campo.',
];

/** Títulos e relatos genéricos o bastante para não afirmar o que a massa não sustenta. */
const EXPERIENCIAS = [
    ['Acompanhamento técnico de obra em Manaus',
     'Responsável técnico pelo acompanhamento de execução, com vistoria periódica e registro das '
     . 'ocorrências. O relato é autodeclarado; o que a plataforma confere é a ART vinculada.'],
    ['Elaboração de laudo técnico',
     'Levantamento em campo, análise e emissão de laudo, com recomendação de intervenção. '
     . 'Experiência declarada pelo próprio profissional.'],
    ['Consultoria técnica para adequação de projeto',
     'Revisão de projeto existente e compatibilização com a norma aplicável, em conjunto com a '
     . 'equipe da contratante.'],
];

$profissionais = $pdo->query(
    "SELECT p.prf_id, p.prf_usu_id, u.usu_nome, p.prf_resumo, p.prf_tipo_contrato,
            p.prf_disponibilidade,
            (SELECT COUNT(*) FROM pro_experiencias e
              WHERE e.exp_prf_id = p.prf_id AND e.exp_status = 'A') AS experiencias
       FROM pro_profissionais p
       JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
      WHERE p.prf_status = 'A' AND u.usu_status = 'A'
        AND u.usu_email LIKE '%@prolink.local'
      ORDER BY p.prf_id"
)->fetchAll();

if ($profissionais === []) {
    printf("\e[33mNenhum profissional de demonstração.\e[0m  Rode scripts/semear-candidatos.php antes.\n");

    exit(1);
}

printf(
    "\e[1mPreenchendo dado autodeclarado\e[0m%s\n  %d profissional(is)\n\n",
    $simular ? '  ' . "\e[33mSIMULAÇÃO, nada é gravado\e[0m" : '',
    count($profissionais),
);

$preenchidos = $intocados = 0;

foreach ($profissionais as $p) {
    $usuarioId = (int) $p['prf_usu_id'];
    $nome      = mb_substr((string) $p['usu_nome'], 0, 26);

    mt_srand($usuarioId);

    // Um em cada quatro fica sem declarar nada. É o caso que mantém a tela tendo de dizer
    // "Não medida" com naturalidade, e que a sinalização de início de carreira existe para
    // contextualizar.
    if (mt_rand(1, 4) === 1) {
        $intocados++;
        printf("  \e[2m%-10s\e[0m %-26s nada declarado (perfil incompleto é estado legítimo)\n", 'em branco', $nome);

        continue;
    }

    $contratos = array_keys(Preferencias::CONTRATOS);
    $contrato  = $contratos[mt_rand(0, count($contratos) - 1)];

    // Metade aceita qualquer lugar; a outra metade escolhe de uma a três UFs, com AM sempre
    // presente, porque a massa e as demandas do roteiro são do Amazonas.
    if (mt_rand(1, 2) === 1) {
        $abrangencia = [Preferencias::QUALQUER];
    } else {
        $abrangencia = ['AM'];
        $extras      = ['PA', 'RO', 'RR', 'AC', 'MT'];
        shuffle($extras);
        $abrangencia = array_merge($abrangencia, array_slice($extras, 0, mt_rand(0, 2)));
    }

    $resumo = RESUMOS[mt_rand(0, count(RESUMOS) - 1)];

    $mudou = false;

    if (!$simular) {
        try {
            $mudou = $preferencias->salvar($usuarioId, [
                'resumo'        => $resumo,
                'tipo_contrato' => $contrato,
                'abrangencia'   => $abrangencia,
            ]);
        } catch (ValidacaoException $e) {
            printf("  \e[31mfalhou\e[0m     %-26s %s\n", $nome, $e->getMessage());

            continue;
        }
    }

    // Uma experiência só, e apenas para quem ainda não tem: o objetivo é a dimensão sair de
    // "Não medida", não encher o perfil.
    $criouExperiencia = false;

    if ((int) $p['experiencias'] === 0 && mt_rand(1, 3) !== 1) {
        [$titulo, $descricao] = EXPERIENCIAS[mt_rand(0, count(EXPERIENCIAS) - 1)];

        // Vincula a uma ART própria quando houver: é o caso que mostra relato ligado a evidência,
        // com os dois continuando a aparecer separados na tela.
        $artId = $pdo->query(
            'SELECT a.art_id FROM crea_arts a
               JOIN pro_profissionais pr ON pr.prf_rnp = a.art_pro_rnp
              WHERE pr.prf_id = ' . (int) $p['prf_id'] . ' ORDER BY a.art_id LIMIT 1'
        )->fetchColumn();

        if (!$simular) {
            try {
                $experiencias->criar($usuarioId, [
                    'titulo'    => $titulo,
                    'descricao' => $descricao,
                    'dt_inicio' => sprintf('%d-%02d-01', 2023 + mt_rand(0, 2), mt_rand(1, 12)),
                    'dt_fim'    => '',
                    'art_id'    => mt_rand(1, 2) === 1 && $artId !== false ? (string) $artId : '',
                ]);
            } catch (ValidacaoException $e) {
                printf("  \e[31mfalhou\e[0m     %-26s experiência: %s\n", $nome, $e->getMessage());
            }
        }

        $criouExperiencia = true;
    }

    $preenchidos++;

    printf(
        "  \e[2m%-10s\e[0m %-26s contrato %-12s · %s%s\n",
        'declarado',
        $nome,
        $contrato,
        $abrangencia === [Preferencias::QUALQUER] ? 'qualquer lugar' : implode('/', $abrangencia),
        $criouExperiencia ? ' · 1 experiência' : '',
    );
}

printf(
    "\n%s  %d com declaração · %d deixados em branco de propósito\n",
    $simular ? "\e[33mSIMULAÇÃO\e[0m" : "\e[32mPRONTO\e[0m",
    $preenchidos,
    $intocados,
);
