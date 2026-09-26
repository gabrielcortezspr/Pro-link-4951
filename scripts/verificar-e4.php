<?php

declare(strict_types=1);

/**
 * Verificação da E4 — motor de compatibilização (RF04, itens 3.2, 10.1, 10.2, 12.3).
 *
 * A aritmética já é coberta por PHPUnit em `tests/Support/CompatibilidadeTest.php`, sem banco.
 * Aqui mora o que só a conexão prova: a query por prefixo sobre a view, os portões de entrada no
 * pool, a transação da operação atômica 2 e a reprodutibilidade da sessão gravada.
 *
 * Uso: docker compose exec php php scripts/verificar-e4.php
 *
 * Idempotente por acréscimo: cada rodada cria uma demanda de verificação e uma sessão nova. Elas
 * ficam, porque sessão de compatibilização é registro de auditoria e mat_sessoes não é lixo.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\CandidatoRepository;
use ProLink\Repository\CompatibilizacaoRepository;
use ProLink\Repository\DemandaRepository;
use ProLink\Repository\EvidenciaRepository;
use ProLink\Service\CompatibilizacaoService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Database;

$aprovado = 0;
$falhou   = 0;
$pdo      = Database::conexao();

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

/** Recusa esperada: devolve true quando a chamada lança ValidacaoException. */
function recusa(callable $chamada): bool
{
    try {
        $chamada();
    } catch (ValidacaoException) {
        return true;
    }

    return false;
}

// ---------------------------------------------------------------- cenário

$evidencias = new EvidenciaRepository();
$candidatos = new CandidatoRepository();
$demandas   = new DemandaRepository();
$sessoes    = new CompatibilizacaoRepository();
$motor      = new CompatibilizacaoService();

// O acervo desta base decide o cenário, e não o contrário: a massa tem vínculos ART -> TOS
// aleatórios, então demanda escrita antes de olhar o índice não encontraria ninguém (ver
// docs/matching.md, "Cenários de demonstração").
$stmt = $pdo->query(
    'SELECT evi_tos_codigo, COUNT(DISTINCT CONCAT(evi_candidato_tipo, evi_candidato_id)) AS candidatos
       FROM crea_evidencias GROUP BY evi_tos_codigo ORDER BY candidatos DESC, evi_tos_codigo LIMIT 1'
);
$maisComum = $stmt->fetch();

if ($maisComum === false) {
    printf(
        "\e[31mÍndice de evidência vazio.\e[0m  Nenhum candidato tem ART importada.\n"
        . "Cadastre ao menos um profissional pelo formulário para a E4 ter o que medir.\n"
    );

    exit(1);
}

$codigoAlvo = (string) $maisComum['evi_tos_codigo'];

// O demandante não pode ser candidato da própria demanda, então precisa ser uma conta sem acervo.
$demandanteId = (int) $pdo->query(
    "SELECT u.usu_id FROM sis_usuarios u
       JOIN sis_perfis p ON p.per_id = u.usu_per_id
      WHERE u.usu_status = 'A' AND p.per_codigo IN ('EMPRESA', 'TERCEIRO', 'ADMIN')
      ORDER BY u.usu_id LIMIT 1"
)->fetchColumn();

if ($demandanteId === 0) {
    printf("\e[31mNenhuma conta pode publicar demanda.\e[0m\n");

    exit(1);
}

$demandaId = $demandas->criar([
    'usuario_id'      => $demandanteId,
    'titulo'          => 'Demanda de verificação automática da E4',
    'escopo'          => 'Criada por scripts/verificar-e4.php sobre o código de maior acervo.',
    'local_uf'        => 'AM',
    'local_municipio' => 'Manaus',
    'tipo_contrato'   => null,
    'alvo'            => 'A',
]);

$demandas->sincronizarTos($demandaId, [$codigoAlvo => 1.0]);

printf(
    "\e[2mdemanda %d, código %s, %d candidato(s) com esse código no acervo\e[0m\n",
    $demandaId,
    $codigoAlvo,
    (int) $maisComum['candidatos'],
);

// ---------------------------------------------------------------- índice

secao('Índice de evidência');

$niveis = ProLink\Support\Tos::niveis($codigoAlvo);
$grupo  = (string) $niveis[0];

conferir('a view devolve evidência para o grupo pedido', $evidencias->porPrimeiroNivel([$grupo]) !== []);
conferir('grupo inexistente devolve vazio', $evidencias->porPrimeiroNivel(['999']) === []);
conferir('lista vazia não consulta o banco', $evidencias->porPrimeiroNivel([]) === []);

$agrupado = $evidencias->agrupadoPorCandidato([$grupo]);
conferir('agrupa por candidato com a chave tipo:id', $agrupado !== [] && preg_match('/^[PE]:\d+$/', (string) array_key_first($agrupado)) === 1);

$perfis = $candidatos->porChaves(array_keys($agrupado));
conferir('todo candidato do índice tem perfil carregável', count($perfis) === count($agrupado), sprintf('%d perfis para %d candidatos', count($perfis), count($agrupado)));

// ---------------------------------------------------------------- execução

secao('Operação atômica 2');

$r = $motor->executar($demandaId, $demandanteId, '127.0.0.1');

conferir('executar() devolve sessão gravada', $r['sessao_id'] > 0, "id {$r['sessao_id']}");
conferir('a semente tem os 32 caracteres da coluna', strlen($r['semente']) === 32);
conferir('avaliou ao menos um candidato', $r['avaliados'] >= 1, "avaliados: {$r['avaliados']}");
conferir('o pool não é maior que os avaliados', count($r['pool']) <= $r['avaliados']);

foreach ($r['pool'] as $c) {
    conferir(
        "candidato {$c['chave']} está acima do limiar",
        $c['score'] >= $r['limiar'],
        "score {$c['score']} contra limiar {$r['limiar']}",
    );
    conferir("candidato {$c['chave']} traz evidência documental", $c['criterios']['evidencias'] !== []);
}

$gravada = $sessoes->porId($r['sessao_id']);
conferir('a sessão é recuperável pelo id', $gravada !== null);
conferir('a semente gravada é a mesma devolvida', ($gravada['mts_semente'] ?? '') === $r['semente']);
conferir('a sessão guarda os pesos vigentes, não só a referência', json_decode((string) ($gravada['mts_pesos'] ?? ''), true) === $r['pesos']);
conferir('o total do pool bate com as linhas gravadas', (int) ($gravada['mts_total_pool'] ?? -1) === count($r['pool']));

$poolGravado = $sessoes->pool($r['sessao_id']);
conferir('o pool gravado tem uma linha por candidato', count($poolGravado) === count($r['pool']));

conferir(
    'a ordem gravada é a ordem sorteada, não a ordem de score',
    array_map(static fn (array $l): string => $l['msp_candidato_tipo'] . ':' . $l['msp_candidato_id'], $poolGravado)
        === array_column($r['pool'], 'chave'),
);

// ---------------------------------------------------------------- item 10.1 e 12.3

secao('Sem ranking, e reproduzível');

$reproduzido = ProLink\Support\Compatibilidade::embaralhar(
    array_map(static fn (array $c): array => ['chave' => $c['chave']], $r['pool']),
    $r['semente'],
);

conferir(
    'a semente gravada reproduz a mesma ordem',
    array_column($reproduzido, 'chave') === array_column($r['pool'], 'chave'),
);

// A tabela não tem coluna de posição, e não pode ganhar uma: posição gravada é ranking
// persistido (item 10.1). Conferido no esquema, não numa linha, porque pool vazio não prova nada.
$colunas = $pdo->query('SHOW COLUMNS FROM mat_sessao_pool')->fetchAll(PDO::FETCH_COLUMN);

conferir(
    'a tabela do pool não tem coluna de posição nem de ordem',
    array_filter($colunas, static fn (string $c): bool => (bool) preg_match('/posicao|ordem|rank/i', $c)) === [],
    implode(', ', $colunas),
);

// ---------------------------------------------------------------- portões

secao('Portões de entrada');

$chavesNoPool = array_column($r['pool'], 'chave');
$doDemandante = null;

foreach ($candidatos->porChaves(array_keys($agrupado)) as $chave => $perfil) {
    if ((int) $perfil['usuario_id'] === $demandanteId) {
        $doDemandante = $chave;
    }
}

conferir(
    'quem publicou não entra no próprio pool',
    $doDemandante === null || !in_array($doDemandante, $chavesNoPool, true),
    $doDemandante === null ? 'o demandante não tem acervo nesta base' : "candidato {$doDemandante}",
);

// Alvo restrito a empresa: nenhum profissional pode sobrar no pool.
$soEmpresa = $demandas->criar([
    'usuario_id'      => $demandanteId,
    'titulo'          => 'Demanda de verificação, alvo restrito a empresa',
    'escopo'          => 'Confere o portão de alvo.',
    'local_uf'        => 'AM',
    'local_municipio' => 'Manaus',
    'tipo_contrato'   => null,
    'alvo'            => 'E',
]);
$demandas->sincronizarTos($soEmpresa, [$codigoAlvo => 1.0]);

$rEmpresa = $motor->executar($soEmpresa, $demandanteId, null);

conferir(
    'demanda dirigida a empresa não traz profissional',
    array_filter($rEmpresa['pool'], static fn (array $c): bool => $c['tipo'] === 'P') === [],
);

// ---------------------------------------------------------------- recusas

secao('Recusas');

conferir('demanda inexistente é recusada', recusa(fn () => $motor->executar(999999, $demandanteId, null)));

$semTos = $demandas->criar([
    'usuario_id'      => $demandanteId,
    'titulo'          => 'Demanda de verificação sem código TOS',
    'escopo'          => 'Confere a recusa por falta de código.',
    'local_uf'        => 'AM',
    'local_municipio' => 'Manaus',
    'tipo_contrato'   => null,
    'alvo'            => 'A',
]);

conferir('demanda sem código TOS é recusada', recusa(fn () => $motor->executar($semTos, $demandanteId, null)));

conferir(
    'a recusa não deixa sessão órfã gravada',
    $sessoes->daDemanda($semTos) === [],
);

secao('O demandante registra interesse (Anexo I item 3)');

// O caminho inverso do feed, que era declaradamente passivo: quem publica a demanda via o
// compatível na tela e não tinha o que fazer com ele. O Anexo I dá ao Terceiro, e por
// consequência a todo demandante, o direito de registrar interesse em profissional ou empresa.
$interesse = new ProLink\Service\ManifestacaoService();

// A própria demanda de verificação desta rodada, publicada agora: ela já tem sessão do motor, e
// não depende do que houver no banco. Pegar "a última demanda publicada" quebrava depois do
// limpar-rastro (D80), que encerra as de verificação, e podia cair numa demanda da demonstração.
$demandas->publicar($demandaId);

$demandaPublicada = ['dem_id' => $demandaId, 'dem_usu_id' => $demandanteId];

if ($demandaPublicada === false) {
    conferir('há demanda publicada para exercitar o registro de interesse', false,
        'nenhuma demanda publicada no banco');
} else {
    $idDemanda   = (int) $demandaPublicada['dem_id'];
    $idDemandante = (int) $demandaPublicada['dem_usu_id'];

    conferir(
        'demanda de outra conta é recusada',
        recusa(fn () => $interesse->registrarInteresse(999999, $idDemanda, 1, '')),
    );

    conferir(
        'registrar interesse em si mesmo é recusado',
        recusa(fn () => $interesse->registrarInteresse($idDemandante, $idDemanda, $idDemandante, '')),
    );

    $candidatoLivre = $pdo->query(
        'SELECT p.prf_usu_id
           FROM mat_sessao_pool sp
           JOIN mat_sessoes s ON s.mts_id = sp.msp_mts_id
           JOIN pro_profissionais p ON p.prf_id = sp.msp_candidato_id AND sp.msp_candidato_tipo = "P"
          WHERE s.mts_dem_id = ' . $idDemanda . '
            AND p.prf_usu_id NOT IN (SELECT man_usu_id FROM pro_manifestacoes
                                      WHERE man_dem_id = ' . $idDemanda . ' AND man_status = "A")
          LIMIT 1'
    )->fetchColumn();

    if ($candidatoLivre === false) {
        printf("  \e[33mpulado\e[0m  todo o pool desta demanda já tem interesse registrado\n");
    } else {
        $idInteresse = $interesse->registrarInteresse(
            $idDemandante,
            $idDemanda,
            (int) $candidatoLivre,
            'Interesse registrado pela verificação automática da E4.',
        );

        $linha = $pdo->query(
            "SELECT man_origem, man_usu_id, man_snapshot_hash FROM pro_manifestacoes WHERE man_id = {$idInteresse}"
        )->fetch();

        conferir('a manifestação nasce com origem de demandante',
            ($linha['man_origem'] ?? '') === 'D', 'origem: ' . (string) ($linha['man_origem'] ?? ''));
        conferir('o candidato é o alvo, e não quem agiu',
            (int) ($linha['man_usu_id'] ?? 0) === (int) $candidatoLivre);
        conferir('o retrato do candidato é congelado, com hash',
            strlen((string) ($linha['man_snapshot_hash'] ?? '')) === 64);

        $aviso = $pdo->query(
            'SELECT not_id, not_usu_id, not_assunto FROM sis_notificacoes ORDER BY not_id DESC LIMIT 1'
        )->fetch();

        conferir('o aviso vai para o candidato, e não para quem registrou',
            (int) ($aviso['not_usu_id'] ?? 0) === (int) $candidatoLivre);
        // "Convite" desde a D87: o assunto era "Registraram interesse no seu perfil".
        conferir('o assunto diz que a pessoa recebeu um convite',
            str_contains((string) ($aviso['not_assunto'] ?? ''), 'recebeu um convite'));

        conferir(
            'o mesmo par não recebe interesse duas vezes',
            recusa(fn () => $interesse->registrarInteresse($idDemandante, $idDemanda, (int) $candidatoLivre, '')),
        );

        $trilha = $pdo->query(
            "SELECT aud_valor_novo FROM sis_auditoria
              WHERE aud_entidade = 'pro_manifestacoes' AND aud_entidade_id = {$idInteresse}
              ORDER BY aud_id DESC LIMIT 1"
        )->fetchColumn();

        conferir('a trilha registra que a origem foi o demandante',
            str_contains((string) $trilha, '"origem":"D"'), (string) $trilha);

        // ## Por que este bloco apaga fisicamente o que criou
        //
        // A plataforma nunca apaga: o item 8.6j exige exclusão lógica, e `man_status = 'X'` é o
        // que a aplicação faz. Aqui, e só aqui, a linha é removida de verdade, por três motivos.
        //
        // **Sem isto a verificação se degrada sozinha.** Cada execução consumia um candidato do
        // pool e nunca o devolvia. Depois de algumas rodadas o pool esgotava, o bloco inteiro
        // passava a ser pulado, e o placar caía de 41 para 34 sem nada ter quebrado. Verificação
        // que enfraquece a cada execução é pior que verificação nenhuma, porque o número continua
        // verde enquanto a cobertura desaparece.
        //
        // **Exclusão lógica não resolveria**, e o motivo é uma limitação conhecida: o índice
        // `uq_man_dem_usu` é sobre (demanda, usuário) sem o status, então o par excluído não pode
        // ser recriado. Marcar como excluído deixaria o candidato queimado do mesmo jeito.
        //
        // **O rastro não é dado da aplicação.** É linha que este arquivo inseriu segundos atrás,
        // pelo id que ele mesmo guardou, e que a banca veria como interesse que ninguém registrou.
        // A trilha de auditoria da operação **fica**: ela é insert-only por gatilho, e é ela que
        // prova que o registro aconteceu.
        $pdo->exec("DELETE FROM sis_notificacoes WHERE not_usu_id = {$candidatoLivre}
                      AND not_assunto LIKE '%interesse no seu perfil%'
                      AND not_id >= " . (int) ($aviso['not_id'] ?? 0));
        $pdo->exec("DELETE FROM pro_manifestacoes WHERE man_id = {$idInteresse}");

        conferir(
            'a verificação devolve o candidato ao pool e não deixa rastro',
            (int) $pdo->query("SELECT COUNT(*) FROM pro_manifestacoes WHERE man_id = {$idInteresse}")
                ->fetchColumn() === 0,
        );
    }
}

printf(
    "\n%s  %d aprovadas, %d falharam\n",
    $falhou === 0 ? "\e[32mE4 (MOTOR DE COMPATIBILIZAÇÃO) VERIFICADA\e[0m" : "\e[31mE4 COM FALHA\e[0m",
    $aprovado,
    $falhou,
);

exit($falhou === 0 ? 0 : 1);
