<?php

declare(strict_types=1);

/**
 * Povoa a plataforma com demandas completas e interesses manifestados, pelo fluxo real.
 *
 * **Pelos mesmos serviços que as telas usam**, e não por SQL: `DemandaService` cria, escolhe as
 * atividades da Tabela de Obras e Serviços e publica; `CompatibilizacaoService` monta o conjunto
 * de compatíveis como a empresa vê ao abrir a demanda; `ManifestacaoService` registra o interesse
 * com as respostas às perguntas da demanda (D78). Tudo sai com auditoria, e-mail enfileirado e
 * snapshot do perfil, como sairia de um clique.
 *
 * **Não consulta a API.** Demanda e manifestação são dados da plataforma, não do CREA. Os
 * candidatos já precisam estar cadastrados (`semear-candidatos.php`, que é quem usa a API).
 *
 * Três passos:
 *
 * 1. **Encerra as demandas de verificação** ("Demanda de verificação…") que o `verificar-e4.php`
 *    deixa na vitrine a cada execução. Pelo mesmo `encerrar()` do botão: nada é apagado, a
 *    demanda só sai da vitrine, e a trilha registra.
 * 2. **Publica demandas completas**: atividades escolhidas olhando o acervo que existe (os
 *    vínculos ART → TOS da massa são aleatórios, então a demanda vem depois do índice, nunca
 *    antes), local, regime de contrato, prazo de início e a quem se dirige.
 * 3. **Faz candidatos do conjunto manifestarem interesse**, com respostas variadas (aceita, não
 *    aceita, prefere conversar; datas dentro e fora do prazo) e mensagem escrita; e registra
 *    alguns interesses do lado da empresa, o caminho inverso (D70).
 *
 * Uso:
 *   docker compose exec php php scripts/semear-demandas.php --simular
 *   docker compose exec php php scripts/semear-demandas.php
 *
 * Idempotente: demanda com o mesmo título na mesma conta é reaproveitada, e candidato que já
 * manifestou não manifesta de novo.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\DemandaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\DemandaService;
use ProLink\Service\ManifestacaoService;
use ProLink\Service\ValidacaoException;

$opcoes  = getopt('', ['simular']);
$simular = isset($opcoes['simular']);

$usuarios  = new UsuarioRepository();
$demandas  = new DemandaRepository();
$servico   = new DemandaService();
$motor     = new CompatibilizacaoService();
$interesse = new ManifestacaoService();

/** Uma data a N dias de hoje, no formato do banco. */
function daquiA(int $dias): string
{
    return (new DateTimeImmutable('today'))->modify("+{$dias} days")->format('Y-m-d');
}

/** @return array<string, mixed>|null */
function conta(UsuarioRepository $usuarios, string $email): ?array
{
    return $usuarios->porEmail($email);
}

// Cada demanda: dona, texto, local, regime, prazo (null = em aberto), público e atividades.
// `principal` pesa mais na competência que `secundaria` (DemandaService::PESO_*).
$catalogo = [
    [
        'dona'   => 'construtora.manauara.ltda.0167@prolink.local',
        'titulo' => 'Galpão logístico com projeto de prevenção e combate a incêndio',
        'escopo' => 'Projeto e execução do sistema de prevenção e combate a incêndio e pânico de um galpão logístico de 4.000 m², com rede de sprinklers, e acompanhamento da dosagem do concreto do piso industrial. Entrega do projeto aprovado no Corpo de Bombeiros e do as built.',
        'uf' => 'AM', 'municipio' => 'Manaus', 'contrato' => 'OBRA_CERTA', 'inicio' => 30, 'alvo' => 'A',
        'tos' => ['TOS_1.6.6' => 'principal', 'TOS_1.6.4' => 'principal', 'TOS_1.2.2' => 'secundaria'],
    ],
    [
        'dona'   => 'rio.negro.engenharia.civil.s.a.0101@prolink.local',
        'titulo' => 'Subestação elevadora e chaves seccionadoras para ampliação de planta industrial',
        'escopo' => 'Projeto e comissionamento de subestação elevadora de tensão para a ampliação de uma planta no Polo Industrial de Manaus, com especificação das chaves seccionadoras e estudo de integração com a geração termoelétrica de reserva.',
        'uf' => 'AM', 'municipio' => 'Manaus', 'contrato' => 'PJ', 'inicio' => 45, 'alvo' => 'A',
        'tos' => ['TOS_11.9.17.5' => 'principal', 'TOS_11.4.16' => 'principal', 'TOS_11.9.1.4' => 'secundaria'],
    ],
    [
        'dona'   => 'corrente.continua.engenharia.ltda.0171@prolink.local',
        'titulo' => 'Geração de energia a partir de biodigestor em agroindústria',
        'escopo' => 'Dimensionamento de biodigestor para resíduos de uma agroindústria e do sistema de geração de energia por biogás, com ligação à rede interna. Inclui memorial de cálculo e acompanhamento da partida.',
        'uf' => 'AM', 'municipio' => 'Itacoatiara', 'contrato' => 'PJ', 'inicio' => null, 'alvo' => 'P',
        'tos' => ['TOS_11.9.1.7' => 'principal', 'TOS_16.2.1.11' => 'principal'],
    ],
    [
        'dona'   => 'industria.e.comercio.de.maquinas.amazonas.ltda.0182@prolink.local',
        'titulo' => 'Vasos de pressão e tubulação para linha de gases industriais',
        'escopo' => 'Projeto e inspeção de vasos de pressão para gases e da tubulação e acessórios de uma nova linha de envase de gases industriais, com responsabilidade técnica pela montagem.',
        'uf' => 'AM', 'municipio' => 'Manaus', 'contrato' => 'CLT', 'inicio' => 20, 'alvo' => 'A',
        'tos' => ['TOS_16.3.2' => 'principal', 'TOS_16.3.1' => 'principal', 'TOS_16.3.12' => 'secundaria'],
    ],
    [
        'dona'   => 'encontro.das.aguas.engenharia.fluvial.ltda.0174@prolink.local',
        'titulo' => 'Turbinas, válvulas e medição de vazão em embarcações de carga',
        'escopo' => 'Manutenção e adequação dos sistemas fluidodinâmicos de três embarcações de carga que operam no Rio Negro: turbinas hidráulicas, válvulas e medidores de vazão de líquidos. Trabalho por temporada de estiagem.',
        'uf' => 'AM', 'municipio' => 'Manaus', 'contrato' => 'TEMPORARIO', 'inicio' => 60, 'alvo' => 'A',
        'tos' => ['TOS_18.5.14.1' => 'principal', 'TOS_18.5.5' => 'principal', 'TOS_18.5.1' => 'secundaria'],
    ],
    [
        'dona'   => 'flora.servicos.agronomicos.ltda.0150@prolink.local',
        'titulo' => 'Manejo de fauna e flora aquática em área de piscicultura',
        'escopo' => 'Plano de produção e manejo de fauna e flora aquática em área de piscicultura de tambaqui, com acompanhamento mensal durante o primeiro ciclo.',
        'uf' => 'AM', 'municipio' => 'Manacapuru', 'contrato' => 'TEMPORARIO', 'inicio' => null, 'alvo' => 'P',
        'tos' => ['TOS_39.15.13' => 'principal', 'TOS_39.15.10' => 'secundaria'],
    ],
    [
        'dona'   => 'mecanica.solucoes.industriais.ltda.0193@prolink.local',
        'titulo' => 'Esteiras rolantes e monotrilho para centro de distribuição',
        'escopo' => 'Projeto e montagem de esteiras rolantes e de um monotrilho de carga para um centro de distribuição, com laudo de segurança dos transportadores.',
        'uf' => 'AM', 'municipio' => 'Manaus', 'contrato' => 'OBRA_CERTA', 'inicio' => 25, 'alvo' => 'A',
        'tos' => ['TOS_16.6.1.2' => 'principal', 'TOS_16.6.1.11' => 'principal'],
    ],
    [
        'dona'   => 'omega.consultoria.pericias.e.avaliacoes.ltda.0105@prolink.local',
        'titulo' => 'Agrimensura legal para demarcação e inventário de propriedades rurais',
        'escopo' => 'Levantamentos de agrimensura legal para ação demarcatória e para inventário de três propriedades rurais, com peças técnicas para os processos judiciais.',
        'uf' => 'AM', 'municipio' => 'Parintins', 'contrato' => 'CONSULTORIA', 'inicio' => 40, 'alvo' => 'P',
        'tos' => ['TOS_36.7.1.1' => 'principal', 'TOS_36.7.1.6' => 'principal'],
    ],
    [
        'dona'   => 'solimoes.edificacoes.eireli.0112@prolink.local',
        'titulo' => 'Armazenamento de óleos e produtos petroquímicos no Polo Industrial',
        'escopo' => 'Adequação da área de armazenamento e conservação de óleos minerais e produtos petroquímicos básicos, incluindo a parte de fluidos gasosos. Procuramos empresa com quadro técnico que já tenha feito esse tipo de serviço.',
        'uf' => 'AM', 'municipio' => 'Manaus', 'contrato' => 'QUALQUER', 'inicio' => null, 'alvo' => 'E',
        'tos' => ['TOS_21.4.13.9' => 'principal', 'TOS_21.4.13.1' => 'principal', 'TOS_21.4.5.3' => 'secundaria'],
    ],
    [
        'dona'   => 'alfa.engenharia.e.consultoria.ltda.0145@prolink.local',
        'titulo' => 'Gerenciamento de riscos em trabalho aquaviário e rural (NR30 e NR31)',
        'escopo' => 'Elaboração do programa de gerenciamento de riscos para operações aquaviárias e para as atividades rurais de um cliente do setor agroflorestal, com treinamento das equipes.',
        'uf' => 'AM', 'municipio' => 'Manaus', 'contrato' => 'CONSULTORIA', 'inicio' => 15, 'alvo' => 'A',
        'tos' => ['TOS_42.1.7' => 'principal', 'TOS_42.1.10' => 'principal'],
    ],
];

// As respostas de cada interessado variam pela ordem de chegada, para a lista de interessados ter
// os casos que a empresa encontra de verdade: quem aceita tudo, quem prefere conversar o contrato,
// quem não atende a região, e quem não aceita o regime e só pode começar depois do prazo. São
// quatro, e por isso cada demanda recebe quatro interessados: com três, o último caso nunca saía.
const INTERESSADOS_POR_DEMANDA = 4;
$perfisDeResposta = [
    ['contrato' => 'S', 'local' => 'S', 'dias' => 7],
    ['contrato' => 'C', 'local' => 'S', 'dias' => 15],
    ['contrato' => 'S', 'local' => 'N', 'dias' => 10],
    ['contrato' => 'N', 'local' => 'S', 'dias' => 90],
];

$mensagens = [
    'Tenho obras registradas nesta área e posso apresentar o acervo. Consigo mobilizar a equipe na data que informei.',
    'Já fiz serviço parecido e tenho a documentação no CREA. Gostaria de entender melhor o cronograma antes de fechar o contrato.',
    '',
    'Atuo com esse tipo de serviço há alguns anos. Tenho disponibilidade a partir da data informada e posso visitar o local.',
];

printf(
    "\e[1mSemeando demandas e interesses\e[0m  %s\n\n",
    $simular ? "\e[33mSIMULAÇÃO, nada é gravado\e[0m" : 'gravando pelos serviços da plataforma',
);

// ------------------------------------------------------------------ 1. vitrine limpa

$encerradas = 0;
$verificacao = conta($usuarios, 'camila@prolink.local');

if ($verificacao !== null) {
    foreach ($demandas->doUsuario((int) $verificacao['usu_id']) as $d) {
        if (!str_starts_with((string) $d['dem_titulo'], 'Demanda de verificação')
            || !in_array($d['dem_situacao'], ['ABERTA', 'COM_INTERESSADOS'], true)) {
            continue;
        }

        if (!$simular) {
            $servico->encerrar((int) $verificacao['usu_id'], (int) $d['dem_id']);
        }

        $encerradas++;
    }
}

printf("  vitrine: %d demanda(s) de verificação %s\n\n", $encerradas, $simular ? 'seriam encerradas' : 'encerradas');

// ------------------------------------------------------------------ 2. demandas completas

$publicadas = [];

foreach ($catalogo as $item) {
    $dona = conta($usuarios, $item['dona']);

    if ($dona === null) {
        printf("  \e[33mpulada\e[0m  %s · a conta %s não existe (rode semear-candidatos.php)\n", $item['titulo'], $item['dona']);
        continue;
    }

    $donaId = (int) $dona['usu_id'];
    $existe = null;

    foreach ($demandas->doUsuario($donaId) as $d) {
        if ($d['dem_titulo'] === $item['titulo']) {
            $existe = (int) $d['dem_id'];
        }
    }

    if ($existe !== null) {
        printf("  já existe  #%d %s\n", $existe, $item['titulo']);
        $publicadas[] = ['id' => $existe, 'dona' => $donaId, 'alvo' => $item['alvo']];
        continue;
    }

    if ($simular) {
        printf("  simular    %s · %s/%s · %s · %s\n", $item['titulo'], $item['municipio'], $item['uf'],
            $item['contrato'], $item['inicio'] === null ? 'prazo em aberto' : 'início em até ' . $item['inicio'] . ' dias');
        continue;
    }

    try {
        $id = $servico->criar($donaId, [
            'titulo'          => $item['titulo'],
            'escopo'          => $item['escopo'],
            'local_uf'        => $item['uf'],
            'local_municipio' => $item['municipio'],
            'tipo_contrato'   => $item['contrato'],
            'inicio_ate'      => $item['inicio'] === null ? '' : daquiA($item['inicio']),
            'alvo'            => $item['alvo'],
        ]);

        foreach ($item['tos'] as $codigo => $papel) {
            $servico->alterarTos($donaId, $id, $codigo, $papel);
        }

        $servico->publicar($donaId, $id);
    } catch (ValidacaoException $e) {
        printf("  \e[31mfalhou\e[0m     %s · %s\n", $item['titulo'], $e->getMessage());
        continue;
    }

    printf("  \e[32mpublicada\e[0m  #%d %s\n", $id, $item['titulo']);
    $publicadas[] = ['id' => $id, 'dona' => $donaId, 'alvo' => $item['alvo']];
}

// A demanda do roteiro (plano setorial regional) ganha regime e prazo, pela edição da própria
// dona, para perguntar as três coisas a quem manifestar.
$manauara = conta($usuarios, 'construtora.manauara.ltda.0167@prolink.local');

if ($manauara !== null) {
    foreach ($demandas->doUsuario((int) $manauara['usu_id']) as $d) {
        if (!str_starts_with((string) $d['dem_titulo'], 'Plano setorial regional')) {
            continue;
        }

        if (!$simular && ($d['dem_tipo_contrato'] === null || $d['dem_inicio_ate'] === null)) {
            $servico->editar((int) $manauara['usu_id'], (int) $d['dem_id'], [
                'titulo'          => $d['dem_titulo'],
                'escopo'          => $d['dem_escopo'],
                'local_uf'        => $d['dem_local_uf'],
                'local_municipio' => $d['dem_local_municipio'],
                'tipo_contrato'   => 'PJ',
                'inicio_ate'      => daquiA(30),
                'alvo'            => $d['dem_alvo'],
            ]);
            printf("  \e[32meditada\e[0m    #%d %s · contrato PJ, início em até 30 dias\n", $d['dem_id'], $d['dem_titulo']);
        }

        $publicadas[] = ['id' => (int) $d['dem_id'], 'dona' => (int) $manauara['usu_id'], 'alvo' => $d['dem_alvo']];
    }
}

// ------------------------------------------------------------------ 3. interesses

if ($simular) {
    printf("\n  interesses: calculados depois de publicar, fora da simulação\n");
    exit(0);
}

echo "\n";

$porCandidato = [];   // o teto por hora de manifestações conta por pessoa (A04)
$manifestacoes = new ManifestacaoRepository();
$manifestadas = 0;
$registradas  = 0;

foreach ($publicadas as $pub) {
    $sessao = $motor->paraDemanda($pub['id'], $pub['dona'], null, true);
    $demanda = $demandas->porId($pub['id']);

    // Conta quem já manifestou pelo formulário, para rodar de novo sem passar de quatro.
    $feitas = count(array_filter(
        $manifestacoes->daDemanda($pub['id']),
        static fn (array $m): bool => ($m['man_origem'] ?? 'C') === 'C',
    ));
    $antes = $feitas;
    $jaTemDaEmpresa = count(array_filter(
        $manifestacoes->daDemanda($pub['id']),
        static fn (array $m): bool => ($m['man_origem'] ?? 'C') === 'D',
    )) > 0;

    foreach ($sessao['candidatos'] as $candidato) {
        $usuarioId = (int) $candidato['usuario_id'];

        if ($feitas >= INTERESSADOS_POR_DEMANDA || ($porCandidato[$usuarioId] ?? 0) >= 3) {
            continue;
        }

        $perfil = $perfisDeResposta[$feitas % count($perfisDeResposta)];

        try {
            $interesse->manifestar(
                $usuarioId,
                $pub['id'],
                $mensagens[$feitas % count($mensagens)],
                [
                    'aceita_contrato' => $perfil['contrato'],
                    'atende_local'    => $perfil['local'],
                    'inicio_em'       => daquiA($perfil['dias']),
                ],
            );
        } catch (ValidacaoException $e) {
            // Já manifestou, perfil fechado, público restrito: o próprio serviço decide, e o
            // script só segue para o próximo do conjunto.
            continue;
        }

        $feitas++;
        $manifestadas++;
        $porCandidato[$usuarioId] = ($porCandidato[$usuarioId] ?? 0) + 1;
    }

    // Um interesse do lado da empresa, no primeiro do conjunto que ainda não se manifestou.
    foreach ($jaTemDaEmpresa ? [] : $sessao['candidatos'] as $candidato) {
        try {
            $interesse->registrarInteresse(
                $pub['dona'],
                $pub['id'],
                (int) $candidato['usuario_id'],
                'Vimos o seu acervo nesta atividade e gostaríamos de conversar sobre a demanda.',
            );
            $registradas++;
            break;
        } catch (ValidacaoException) {
            continue;
        }
    }

    printf("  #%-4d %-70s %d no conjunto · %d manifestaram (%d agora)\n",
        $pub['id'], mb_strimwidth((string) $demanda['dem_titulo'], 0, 70, '…'),
        count($sessao['candidatos']), $feitas, $feitas - $antes);
}

printf("\n\e[32mPRONTO\e[0m  %d demanda(s) · %d interesse(s) manifestado(s) · %d registrado(s) pela empresa\n",
    count($publicadas), $manifestadas, $registradas);
