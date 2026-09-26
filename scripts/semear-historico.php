<?php

declare(strict_types=1);

/**
 * Povoa o passado da plataforma: demandas que ficaram abertas semanas atrás, receberam
 * candidaturas e convites, tiveram conversa e foram encerradas pela dona.
 *
 * Sem isto, a base de demonstração só tem o presente: "Minhas demandas" nunca mostra a aba
 * Encerradas com conteúdo (D93), a linha do tempo do Início começa hoje (D90), e a conversa de
 * uma demanda que já terminou não existe para ninguém abrir.
 *
 * **Pelos serviços da plataforma, como `semear-demandas.php`.** Criar, escolher as atividades,
 * publicar, montar o conjunto de compatíveis, candidatar, convidar, abrir, responder e encerrar
 * passam pelos mesmos serviços das telas, com auditoria, snapshot do perfil e e-mail na fila.
 * **Não consulta a API**: os candidatos já estão cadastrados (`semear-candidatos.php`).
 *
 * **Depois, as datas vão para o passado.** Nenhum serviço grava data retroativa, e não deve: o
 * relógio é um só (D40). Por isso, e só aqui, o script reescreve as datas de registro, publicação,
 * encerramento, candidatura, leitura e mensagem de cada demanda que ele mesmo criou, para a
 * história ter semanas em vez de segundos. **A trilha de auditoria não é tocada**: ela é
 * insert-only por trigger e por privilégio, e continua dizendo a hora real em que cada ação
 * aconteceu, que é o registro honesto de que isto é povoamento de demonstração.
 *
 * As atividades de cada demanda saem do índice de evidência e não de uma lista escrita à mão: os
 * vínculos ART → TOS da massa são aleatórios, então a demanda vem depois do acervo, nunca antes.
 *
 * Uso:
 *   docker compose exec php php scripts/semear-historico.php
 *
 * Idempotente: demanda com o mesmo título na mesma conta é pulada.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\DemandaRepository;
use ProLink\Repository\ManifestacaoRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\DemandaService;
use ProLink\Service\InteressadoService;
use ProLink\Service\ManifestacaoService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Database;

$pdo          = Database::conexao();
$usuarios     = new UsuarioRepository();
$demandas     = new DemandaRepository();
$servico      = new DemandaService();
$motor        = new CompatibilizacaoService();
$interesse    = new ManifestacaoService();
$interessados = new InteressadoService();
$manifestacoes = new ManifestacaoRepository();

/**
 * Cada demanda do passado: dona, há quantas semanas foi publicada, por quantos dias ficou aberta,
 * local, regime e público. As atividades são escolhidas no índice, pela ordem em `posicao`.
 */
$historico = [
    ['dona' => 'construtora.manauara.ltda.0167@prolink.local', 'semanas' => 11, 'dias' => 18, 'municipio' => 'Manaus',        'contrato' => 'OBRA_CERTA', 'alvo' => 'A', 'posicao' => 0],
    ['dona' => 'construtora.manauara.ltda.0167@prolink.local', 'semanas' => 7,  'dias' => 12, 'municipio' => 'Iranduba',      'contrato' => 'PJ',         'alvo' => 'P', 'posicao' => 1],
    ['dona' => 'construtora.manauara.ltda.0167@prolink.local', 'semanas' => 4,  'dias' => 9,  'municipio' => 'Manaus',        'contrato' => 'TEMPORARIO', 'alvo' => 'A', 'posicao' => 2],
    ['dona' => 'alfa.engenharia.e.consultoria.ltda.0145@prolink.local', 'semanas' => 10, 'dias' => 21, 'municipio' => 'Manacapuru', 'contrato' => 'PJ', 'alvo' => 'A', 'posicao' => 3],
    ['dona' => 'alfa.engenharia.e.consultoria.ltda.0145@prolink.local', 'semanas' => 5,  'dias' => 10, 'municipio' => 'Manaus',     'contrato' => 'CONSULTORIA', 'alvo' => 'P', 'posicao' => 4],
    ['dona' => 'rio.negro.engenharia.civil.s.a.0101@prolink.local', 'semanas' => 9, 'dias' => 14, 'municipio' => 'Itacoatiara', 'contrato' => 'OBRA_CERTA', 'alvo' => 'A', 'posicao' => 5],
    ['dona' => 'industria.e.comercio.de.maquinas.amazonas.ltda.0182@prolink.local', 'semanas' => 8, 'dias' => 16, 'municipio' => 'Manaus', 'contrato' => 'CLT', 'alvo' => 'P', 'posicao' => 6],
    ['dona' => 'base.solida.construcoes.ltda.0156@prolink.local', 'semanas' => 6, 'dias' => 11, 'municipio' => 'Parintins', 'contrato' => 'TEMPORARIO', 'alvo' => 'A', 'posicao' => 7],
    ['dona' => 'base.solida.construcoes.ltda.0156@prolink.local', 'semanas' => 3, 'dias' => 8,  'municipio' => 'Manaus',    'contrato' => 'PJ',         'alvo' => 'A', 'posicao' => 8],
];

$candidaturas = [
    ['texto' => 'Tenho ART registrada nesta atividade e posso apresentar o acervo. Consigo começar na data indicada.', 'contrato' => 'S', 'local' => 'S', 'dias' => 7],
    ['texto' => 'Já executei serviço parecido na região. Gostaria de alinhar o cronograma antes de fechar o contrato.', 'contrato' => 'C', 'local' => 'S', 'dias' => 14],
    ['texto' => 'Tenho interesse e disponibilidade. Não atendo o município, mas posso me deslocar por conta própria.', 'contrato' => 'S', 'local' => 'N', 'dias' => 10],
];

// A conversa típica de uma candidatura que avançou, e a de um convite aceito.
$conversaCandidatura = [
    ['quem' => 'dona',  'texto' => 'Obrigado pela candidatura. Pode nos enviar a proposta de prazo e de valor até sexta?'],
    ['quem' => 'outro', 'texto' => 'Envio até quinta. Consigo mobilizar a equipe em dez dias a partir da assinatura.'],
    ['quem' => 'dona',  'texto' => 'Recebemos a proposta e seguimos com você. Vamos combinar a visita técnica.'],
];
$conversaConvite = [
    ['quem' => 'outro', 'texto' => 'Obrigada pelo convite. Tenho agenda livre no período e posso conversar esta semana.'],
    ['quem' => 'dona',  'texto' => 'Ótimo. Fechamos com outra proposta desta vez, mas vamos lembrar de você nas próximas.'],
];

/**
 * Uma atividade principal por subgrupo, dos subgrupos com mais candidatos no índice, cada uma com
 * uma secundária do mesmo subgrupo quando existe. A escolha é por subgrupo, e não por código,
 * porque na massa quase nenhum código se repete entre candidatos (três têm quatro, o resto um ou
 * dois); o subgrupo junta quem o motor aceita pela afinidade de dois níveis.
 *
 * @return list<array{codigo: string, secundaria: ?string, grupo: string, subgrupo: string, obra: string}>
 */
function atividadesComAcervo(PDO $pdo): array
{
    $subgrupos = $pdo->query(
        'SELECT e.evi_nivel1 AS n1, e.evi_nivel2 AS n2
           FROM crea_evidencias e
          GROUP BY e.evi_nivel1, e.evi_nivel2
         HAVING COUNT(DISTINCT CONCAT(e.evi_candidato_tipo, e.evi_candidato_id)) >= 3
          ORDER BY COUNT(DISTINCT CONCAT(e.evi_candidato_tipo, e.evi_candidato_id)) DESC, e.evi_nivel1, e.evi_nivel2
          LIMIT 30'
    )->fetchAll();

    $codigos = $pdo->prepare(
        'SELECT e.evi_tos_codigo AS codigo, t.tos_grupo AS grupo, t.tos_subgrupo AS subgrupo, t.tos_obra_servico AS obra
           FROM crea_evidencias e
           JOIN crea_tos t ON t.tos_codigo = e.evi_tos_codigo
          WHERE e.evi_nivel1 = :n1 AND e.evi_nivel2 = :n2
          GROUP BY e.evi_tos_codigo, t.tos_grupo, t.tos_subgrupo, t.tos_obra_servico
          ORDER BY COUNT(DISTINCT CONCAT(e.evi_candidato_tipo, e.evi_candidato_id)) DESC, e.evi_tos_codigo
          LIMIT 2'
    );

    $saida = [];

    foreach ($subgrupos as $sg) {
        $codigos->execute([':n1' => $sg['n1'], ':n2' => $sg['n2']]);
        $linhas = $codigos->fetchAll();

        if ($linhas === []) {
            continue;
        }

        $saida[] = $linhas[0] + ['secundaria' => $linhas[1]['codigo'] ?? null];
    }

    return $saida;
}

/** "de instalações radioativas" vira "Instalações radioativas". */
function servicoEmTitulo(string $obra): string
{
    $obra = trim((string) preg_replace('/^(de|da|do|das|dos|em|para)\s+/iu', '', trim($obra)));

    return mb_strtoupper(mb_substr($obra, 0, 1)) . mb_substr($obra, 1);
}

function momento(DateTimeImmutable $base, string $deslocamento): string
{
    return $base->modify($deslocamento)->format('Y-m-d H:i:s');
}

printf("\e[1mSemeando o histórico\e[0m  demandas encerradas, com candidaturas, convites e conversa\n\n");

$atividades = atividadesComAcervo($pdo);

if ($atividades === []) {
    printf("\e[31mO índice de evidência está vazio.\e[0m  Rode scripts/semear-candidatos.php antes.\n");
    exit(1);
}

$criadas = 0;

foreach ($historico as $item) {
    $dona = $usuarios->porEmail($item['dona']);

    if ($dona === null) {
        printf("  \e[33mpulada\e[0m  a conta %s não existe\n", $item['dona']);
        continue;
    }

    $donaId = (int) $dona['usu_id'];
    $ativ   = $atividades[$item['posicao'] % count($atividades)];
    $titulo = servicoEmTitulo($ativ['obra']) . ' em ' . $item['municipio'];

    foreach ($demandas->doUsuario($donaId) as $d) {
        if ($d['dem_titulo'] === $titulo) {
            printf("  já existe  #%d %s\n", $d['dem_id'], $titulo);
            continue 2;
        }
    }

    $secundaria = $ativ['secundaria'];

    try {
        $id = $servico->criar($donaId, [
            'titulo'          => $titulo,
            'escopo'          => sprintf(
                'Serviço de %s (%s, %s) em %s/AM. A demanda ficou aberta por %d dias, recebeu candidaturas '
                . 'e convites, e foi encerrada depois da contratação.',
                mb_strtolower(servicoEmTitulo($ativ['obra'])), $ativ['subgrupo'], $ativ['grupo'],
                $item['municipio'], $item['dias'],
            ),
            'local_uf'        => 'AM',
            'local_municipio' => $item['municipio'],
            'tipo_contrato'   => $item['contrato'],
            // O serviço recusa prazo no passado, como deve; a data vai para o passado no fim.
            'inicio_ate'      => (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d'),
            'alvo'            => $item['alvo'],
        ]);

        $servico->alterarTos($donaId, $id, $ativ['codigo'], 'principal');

        if ($secundaria !== null) {
            $servico->alterarTos($donaId, $id, $secundaria, 'secundaria');
        }

        $servico->publicar($donaId, $id);
    } catch (ValidacaoException $e) {
        printf("  \e[31mfalhou\e[0m  %s · %s\n", $titulo, $e->getMessage());
        continue;
    }

    $sessao = $motor->paraDemanda($id, $donaId, null, true);
    $candidatos = $sessao['candidatos'];

    // Duas ou três candidaturas pelo formulário, e um convite da empresa a quem não se candidatou.
    $feitas = [];

    foreach ($candidatos as $i => $c) {
        if (count($feitas) >= 3) {
            break;
        }

        $resposta = $candidaturas[count($feitas)];

        try {
            $manId = $interesse->manifestar((int) $c['usuario_id'], $id, $resposta['texto'], [
                'aceita_contrato' => $resposta['contrato'],
                'atende_local'    => $resposta['local'],
                'inicio_em'       => (new DateTimeImmutable('today'))->modify('+' . $resposta['dias'] . ' days')->format('Y-m-d'),
            ]);
            $feitas[] = ['man' => (int) $manId, 'usuario' => (int) $c['usuario_id'], 'origem' => 'C'];
        } catch (ValidacaoException) {
            continue;
        }
    }

    foreach ($candidatos as $c) {
        if (in_array((int) $c['usuario_id'], array_column($feitas, 'usuario'), true)) {
            continue;
        }

        try {
            $manId = $interesse->registrarInteresse($donaId, $id, (int) $c['usuario_id'],
                'Olá. Vimos o seu acervo nesta atividade e gostaríamos de conversar sobre a demanda.');
            $feitas[] = ['man' => (int) $manId, 'usuario' => (int) $c['usuario_id'], 'origem' => 'D'];
            break;
        } catch (ValidacaoException) {
            continue;
        }
    }

    // Quem recebe abre, e a conversa acontece: a primeira candidatura avança, o convite é respondido.
    foreach ($feitas as $n => $f) {
        $recebe = $f['origem'] === 'C' ? $donaId : $f['usuario'];
        $interessados->abrir($recebe, $f['man']);

        $conversa = match (true) {
            $f['origem'] === 'D' => $conversaConvite,
            $n === 0             => $conversaCandidatura,
            default              => [$conversaCandidatura[0]],
        };

        foreach ($conversa as $fala) {
            $interessados->responder($fala['quem'] === 'dona' ? $donaId : $f['usuario'], $f['man'], $fala['texto']);
        }
    }

    $servico->encerrar($donaId, $id);

    // ---------------------------------------------------------------- as datas vão para o passado

    $publicacao = (new DateTimeImmutable('today 09:30'))->modify('-' . ($item['semanas'] * 7) . ' days');

    $pdo->prepare(
        'UPDATE pro_demandas
            SET dem_dt_registro = :registro, dem_dt_publicacao = :publicacao,
                dem_dt_encerramento = :encerramento, dem_inicio_ate = :inicio
          WHERE dem_id = :id'
    )->execute([
        ':registro'     => momento($publicacao, '-1 day'),
        ':publicacao'   => momento($publicacao, '+0 day'),
        ':encerramento' => momento($publicacao, '+' . $item['dias'] . ' days'),
        ':inicio'       => $publicacao->modify('+30 days')->format('Y-m-d'),
        ':id'           => $id,
    ]);

    $pdo->prepare('UPDATE mat_sessoes SET mts_dt_registro = :quando WHERE mts_dem_id = :id')
        ->execute([':quando' => momento($publicacao, '+10 minutes'), ':id' => $id]);

    foreach ($feitas as $n => $f) {
        // Candidaturas e convites ao longo da primeira semana; a leitura, no dia seguinte.
        $chegou = $publicacao->modify('+' . ($n + 1) . ' days +' . (2 * $n + 1) . ' hours');

        $pdo->prepare(
            'UPDATE pro_manifestacoes SET man_dt_registro = :registro, man_dt_visualizacao = :visto
              WHERE man_id = :id'
        )->execute([':registro' => momento($chegou, '+0 day'), ':visto' => momento($chegou, '+1 day'), ':id' => $f['man']]);

        $mensagens = $pdo->prepare('SELECT msg_id FROM pro_mensagens WHERE msg_man_id = :id ORDER BY msg_id');
        $mensagens->execute([':id' => $f['man']]);

        foreach ($mensagens->fetchAll(PDO::FETCH_COLUMN) as $k => $msgId) {
            $quando = $chegou->modify('+' . ($k + 1) . ' days +' . (3 * $k) . ' hours');
            $pdo->prepare('UPDATE pro_mensagens SET msg_dt_registro = :registro, msg_dt_leitura = :lida WHERE msg_id = :id')
                ->execute([':registro' => momento($quando, '+0 day'), ':lida' => momento($quando, '+2 hours'), ':id' => (int) $msgId]);
        }
    }

    $criadas++;
    printf("  \e[32mencerrada\e[0m  #%-4d %-58s %d contato(s) · publicada há %d semanas\n",
        $id, mb_strimwidth($titulo, 0, 58, '…'), count($feitas), $item['semanas']);
}

printf("\n\e[32mPRONTO\e[0m  %d demanda(s) no histórico\n", $criadas);
