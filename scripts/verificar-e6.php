<?php

declare(strict_types=1);

/**
 * Verificação da E6 — denúncias e painel administrativo (RF06).
 *
 * Exercita serviço e repositório contra o banco de verdade, sem rede e sem gastar chamada da API
 * oficial. O que é estático (listas fechadas, rótulos) fica no PHPUnit; aqui mora o que só a
 * conexão prova: a linha gravada, a situação inicial e a trilha de auditoria na mesma transação.
 *
 * Uso: docker compose exec php php scripts/verificar-e6.php
 *
 * Idempotente por acréscimo: cada rodada abre denúncias novas de verificação. Elas ficam, porque
 * pro_denuncias é insumo do painel e sis_auditoria é insert-only por trigger.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\AuditoriaRepository;
use ProLink\Repository\UsuarioRepository;
use ProLink\Service\DenunciaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Database;
use ProLink\Support\View;

const EMAIL_ALVO  = 'cobaia@prolink.local';
const EMAIL_AUTOR = 'camila@prolink.local';

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

function idPorEmail(PDO $pdo, string $email): ?int
{
    $stmt = $pdo->prepare('SELECT usu_id FROM sis_usuarios WHERE usu_email = :email');
    $stmt->bindValue(':email', $email);
    $stmt->execute();
    $id = $stmt->fetchColumn();

    return $id === false ? null : (int) $id;
}

$alvoId  = idPorEmail($pdo, EMAIL_ALVO);
$autorId = idPorEmail($pdo, EMAIL_AUTOR);

if ($alvoId === null || $autorId === null) {
    printf(
        "\e[31mFalta conta de apoio.\e[0m  %s: %s · %s: %s\n"
        . "Crie o administrador com scripts/criar-admin.php e a cobaia pelo formulário de cadastro.\n",
        EMAIL_AUTOR,
        $autorId === null ? 'ausente' : 'ok',
        EMAIL_ALVO,
        $alvoId === null ? 'ausente' : 'ok',
    );

    exit(1);
}

$servico = new DenunciaService();

secao('Denúncia');

$id = $servico->abrir($autorId, 'USUARIO', $alvoId, 'DADO_ENGANOSO', 'Denúncia de verificação automática.');

$stmt = $pdo->prepare('SELECT den_id, den_situacao FROM pro_denuncias WHERE den_id = :id');
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();
$linha = $stmt->fetch();

conferir('abrir() devolve id e grava a denúncia', $linha !== false, "id devolvido: {$id}");
conferir(
    'a denúncia nasce PENDENTE',
    ($linha['den_situacao'] ?? null) === 'PENDENTE',
    'situação: ' . (string) ($linha['den_situacao'] ?? 'nenhuma'),
);

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM sis_auditoria
      WHERE aud_acao = :acao AND aud_entidade = :entidade AND aud_entidade_id = :id'
);
$stmt->bindValue(':acao', 'CRIAR');
$stmt->bindValue(':entidade', 'pro_denuncias');
$stmt->bindValue(':id', $id, PDO::PARAM_INT);
$stmt->execute();

conferir('sis_auditoria registra CRIAR em pro_denuncias', ((int) $stmt->fetchColumn()) >= 1);

conferir(
    'tipo fora da lista é recusado',
    recusa(fn () => $servico->abrir($autorId, 'USUARIO', $alvoId, 'INVENTADO', 'Tipo que não existe.')),
);

conferir(
    'alvo zero é recusado',
    recusa(fn () => $servico->abrir($autorId, 'USUARIO', 0, 'SPAM', 'Denúncia sem alvo.')),
);

conferir(
    'não dá para denunciar a própria conta',
    recusa(fn () => $servico->abrir($autorId, 'USUARIO', $autorId, 'SPAM', 'Autodenúncia.')),
);

secao('Fila do painel');

$idFila = $servico->abrir($autorId, 'USUARIO', $alvoId, 'SPAM', 'Fila de verificação.');

$pendentes = array_column($servico->fila('PENDENTE'), 'den_id');
$emAnalise = array_column($servico->fila('EM_ANALISE'), 'den_id');

// O par positivo/negativo é o que prova que a condição chegou ao SQL: um filtro testado só no
// caso positivo passa mesmo quando o WHERE foi ignorado.
conferir(
    'o filtro PENDENTE traz a denúncia recém-aberta',
    in_array($idFila, array_map('intval', $pendentes), true),
    "id {$idFila}",
);

conferir(
    'o filtro EM_ANALISE não traz denúncia pendente',
    !in_array($idFila, array_map('intval', $emAnalise), true),
);

secao('Moderação e bloqueio (operação atômica 5)');

// A cobaia precisa estar ativa para o bloqueio ter o que derrubar. Se uma rodada anterior a
// deixou bloqueada, devolve antes de medir.
(new UsuarioRepository())->alterarStatus($alvoId, STATUS_ATIVO);

$idBloqueio = $servico->abrir($autorId, 'USUARIO', $alvoId, 'PERFIL_FRAUDULENTO', 'Bloqueio de verificação.');
$r = $servico->tratar($idBloqueio, $autorId, 'RESOLVIDA', 'BLOQUEAR');

conferir('tratar() com BLOQUEAR devolve bloqueado = true', $r['bloqueado'] === true);

$stmt = $pdo->prepare('SELECT usu_status FROM sis_usuarios WHERE usu_id = :id');
$stmt->bindValue(':id', $alvoId, PDO::PARAM_INT);
$stmt->execute();

conferir(
    'a conta alvo fica INATIVA, não excluída',
    $stmt->fetchColumn() === STATUS_INATIVO,
    "'I' é bloqueio administrativo; 'X' seria exclusão a pedido do titular",
);

$stmt = $pdo->prepare(
    'SELECT COUNT(*) FROM sis_auditoria WHERE aud_acao = :acao AND aud_entidade_id = :id'
);
$stmt->bindValue(':acao', 'BLOQUEAR');
$stmt->bindValue(':id', $alvoId, PDO::PARAM_INT);
$stmt->execute();

conferir('sis_auditoria registra BLOQUEAR na conta alvo', ((int) $stmt->fetchColumn()) >= 1);

$stmt = $pdo->prepare('SELECT den_situacao, den_providencia FROM pro_denuncias WHERE den_id = :id');
$stmt->bindValue(':id', $idBloqueio, PDO::PARAM_INT);
$stmt->execute();
$tratada = $stmt->fetch();

conferir(
    'a denúncia fica RESOLVIDA com a providência registrada',
    ($tratada['den_situacao'] ?? null) === 'RESOLVIDA' && ($tratada['den_providencia'] ?? null) === 'BLOQUEAR',
);

// A guarda que impede o painel de se trancar sozinho no meio da demonstração.
$idContraAdmin = $servico->abrir($alvoId, 'USUARIO', $autorId, 'SPAM', 'Tentativa de bloquear administração.');

conferir(
    'conta de administração não pode ser bloqueada',
    recusa(fn () => $servico->tratar($idContraAdmin, $autorId, 'RESOLVIDA', 'BLOQUEAR')),
);

// Devolve a cobaia ao estado ativo: o script é insumo das próximas rodadas e da demonstração.
(new UsuarioRepository())->alterarStatus($alvoId, STATUS_ATIVO);

secao('Trilha de auditoria');

$trilha = new AuditoriaRepository();

conferir('a trilha lista sem filtro', count($trilha->listar(null, null, null, null, 10)) > 0);

$soBloqueio = $trilha->listar(null, 'BLOQUEAR', null, null, 20);

conferir(
    'o filtro por ação devolve só aquela ação',
    $soBloqueio !== [] && array_unique(array_column($soBloqueio, 'aud_acao')) === ['BLOQUEAR'],
);

// O ponto de demonstração do cenário 6: quem modera aparece na própria trilha.
conferir(
    'a ação do próprio administrador aparece na trilha',
    count($trilha->listar($autorId, 'MODERAR', null, null, 5)) >= 1,
);

secao('Indicadores da visão geral');

// O painel abria com "Não medido" nos quatro tiles e uma nota dizendo que os indicadores
// entrariam em 15/09. A conferência aqui não é do número em si, que muda a cada rodada, e sim de
// que o repositório e o banco contam a mesma coisa: indicador que diverge da tabela é pior do que
// indicador ausente, porque ninguém desconfia dele.
$indicadores = (new ProLink\Repository\IndicadorRepository())->resumo();

foreach (['contas', 'demandas', 'manifestacoes', 'denuncias', 'evidencia', 'motor'] as $chave) {
    conferir("resumo() traz o grupo {$chave}", isset($indicadores[$chave]));
}

$contasAtivas = (int) $pdo->query(
    "SELECT COUNT(*) FROM sis_usuarios WHERE usu_status = 'A'"
)->fetchColumn();

conferir(
    'contas ativas batem com sis_usuarios',
    $indicadores['contas']['total'] === $contasAtivas,
    "indicador {$indicadores['contas']['total']} · tabela {$contasAtivas}",
);

$denunciasAtivas = (int) $pdo->query(
    "SELECT COUNT(*) FROM pro_denuncias WHERE den_status = 'A'"
)->fetchColumn();

conferir(
    'denúncias batem com pro_denuncias',
    $indicadores['denuncias']['total'] === $denunciasAtivas,
    "indicador {$indicadores['denuncias']['total']} · tabela {$denunciasAtivas}",
);

conferir(
    'a soma da quebra por perfil é o total de contas',
    array_sum($indicadores['contas']['por_perfil']) === $indicadores['contas']['total'],
);

conferir(
    'a lixeira não entra na contagem de contas ativas',
    $indicadores['contas']['excluidas'] > 0
        ? $indicadores['contas']['excluidas'] !== $indicadores['contas']['total']
        : true,
);

secao('Parâmetros do motor (edital 12.3, supervisão humana)');

$parametros = new ProLink\Service\ParametroService();

conferir('listar() devolve os parâmetros ativos', count($parametros->listar()) > 0);

conferir(
    'valor fora da faixa reprova o lote',
    recusa(fn () => $parametros->salvar($autorId, ['match.limiar' => '1.5'])),
);

conferir(
    'chave fora da lista fechada reprova o lote',
    recusa(fn () => $parametros->salvar($autorId, ['par.inventado' => '1'])),
);

conferir(
    'níveis de afinidade decrescentes são recusados',
    recusa(fn () => $parametros->salvar($autorId, [
        'match.afinidade.niveis' => '[0.00,0.90,0.40,0.75,1.00]',
    ])),
);

conferir(
    'limiar aceita número inteiro só quando o parâmetro é inteiro',
    recusa(fn () => $parametros->salvar($autorId, ['match.early_career.min_arts' => '3.5'])),
);

// Um lote de dois, com um valor inválido no meio: o par com o caso válido é o que prova que a
// recusa veio da validação e não de tudo estar sendo recusado. Mesma lição da D27 e da D28.
conferir(
    'um valor inválido no meio do lote impede a gravação do lote inteiro',
    recusa(fn () => $parametros->salvar($autorId, [
        'match.peso.contrato' => '0.20',
        'match.limiar'        => '-1',
    ]))
    && (new ProLink\Repository\ParametroRepository())->numero('match.peso.contrato', -1.0) !== 0.20,
);

$limiarOriginal = (string) (new ProLink\Repository\ParametroRepository())->numero('match.limiar', 0.35);
$alteradas      = $parametros->salvar($autorId, ['match.limiar' => '0.42']);

conferir('salvar() devolve a chave alterada', $alteradas === ['match.limiar']);

ProLink\Repository\ParametroRepository::esquecer();

conferir(
    'o valor novo vale na leitura seguinte',
    abs((new ProLink\Repository\ParametroRepository())->numero('match.limiar', 0.0) - 0.42) < 0.0001,
);

$stmt = $pdo->prepare(
    'SELECT aud_valor_anterior, aud_valor_novo FROM sis_auditoria
      WHERE aud_entidade = :entidade AND aud_campo = :campo
      ORDER BY aud_id DESC LIMIT 1'
);
$stmt->execute([':entidade' => 'sis_parametros', ':campo' => 'match.limiar']);
$trilhaParametro = $stmt->fetch();

conferir(
    'a trilha guarda o valor anterior e o novo',
    ($trilhaParametro['aud_valor_novo'] ?? null) === '0.42'
        && ($trilhaParametro['aud_valor_anterior'] ?? null) !== null,
    'antes: ' . (string) ($trilhaParametro['aud_valor_anterior'] ?? 'nenhum'),
);

conferir(
    'gravar o mesmo valor não gera alteração nem linha de trilha',
    $parametros->salvar($autorId, ['match.limiar' => '0.42']) === [],
);

// Devolve o limiar ao valor de antes: o script é insumo da próxima rodada e da demonstração.
$parametros->salvar($autorId, ['match.limiar' => number_format((float) $limiarOriginal, 2, '.', '')]);
ProLink\Repository\ParametroRepository::esquecer();

secao('Lixeira (edital 8.6j)');

$lixeira = new ProLink\Service\LixeiraService();
$visao   = $lixeira->visao('sis_usuarios', 1);

conferir('visão da lixeira traz as contagens de todas as entidades', count($visao['contagens']) === 5);
conferir(
    'entidade desconhecida cai na primeira, sem estourar',
    $lixeira->visao('tabela_que_nao_existe', 1)['entidade'] === 'sis_usuarios',
);

// Uma conta descartável, excluída **pelo próprio titular**, é o caso que a regra de privacidade
// precisa reconhecer. Criada direto no banco, sem API e sem PrivacidadeService, para não revogar
// consentimento nem derrubar sessão de conta usada por outras verificações.
$emailCobaiaLixeira = 'lixeira@verificacao.local';
$idLixeira          = idPorEmail($pdo, $emailCobaiaLixeira);

if ($idLixeira === null) {
    $perfilTerceiro = (int) $pdo->query(
        "SELECT per_id FROM sis_perfis WHERE per_codigo = 'TERCEIRO'"
    )->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO sis_usuarios (usu_per_id, usu_nome, usu_email, usu_senha_hash,
                                   usu_tipo_pessoa, usu_email_verificado, usu_status)
         VALUES (:perfil, :nome, :email, :hash, :tipo, 1, :status)'
    );
    $stmt->execute([
        ':perfil' => $perfilTerceiro,
        ':nome'   => 'Verificação da lixeira',
        ':email'  => $emailCobaiaLixeira,
        ':hash'   => password_hash(bin2hex(random_bytes(16)), PASSWORD_ALGO),
        ':tipo'   => 'F',
        ':status' => STATUS_ATIVO,
    ]);

    $idLixeira = (int) $pdo->lastInsertId();
}

$pdo->prepare('UPDATE sis_usuarios SET usu_status = :x WHERE usu_id = :id')
    ->execute([':x' => STATUS_EXCLUIDO, ':id' => $idLixeira]);

ProLink\Support\Auditoria::registrar(
    ProLink\Support\Auditoria::EXCLUIR,
    'sis_usuarios',
    $idLixeira,
    'usu_status',
    STATUS_ATIVO,
    STATUS_EXCLUIDO,
    $idLixeira,
);

$registro = (new ProLink\Repository\LixeiraRepository())->porId('sis_usuarios', $idLixeira);

conferir('conta excluída aparece na lixeira', $registro !== null);
conferir(
    'a lixeira reconhece que quem excluiu foi o próprio titular',
    ($registro['pelo_titular'] ?? false) === true,
    'autor da exclusão: ' . (string) ($registro['excluido_por'] ?? 'desconhecido'),
);
conferir(
    'conta excluída pelo titular não é restaurada por ato administrativo',
    recusa(fn () => $lixeira->restaurar('sis_usuarios', $idLixeira, $autorId, 'Motivo de verificação automática.')),
);
conferir(
    'a conta continua excluída depois da tentativa',
    $pdo->query("SELECT usu_status FROM sis_usuarios WHERE usu_id = {$idLixeira}")->fetchColumn() === STATUS_EXCLUIDO,
);

// O mesmo registro, agora excluído pela administração: o caso que **deve** voltar.
ProLink\Support\Auditoria::registrar(
    ProLink\Support\Auditoria::EXCLUIR,
    'sis_usuarios',
    $idLixeira,
    'usu_status',
    STATUS_ATIVO,
    STATUS_EXCLUIDO,
    $autorId,
);

conferir(
    'restauração sem motivo é recusada',
    recusa(fn () => $lixeira->restaurar('sis_usuarios', $idLixeira, $autorId, 'curto')),
);

$restaurado = $lixeira->restaurar(
    'sis_usuarios',
    $idLixeira,
    $autorId,
    'Restauração de verificação automática do script da E6.',
);

conferir('restauração devolve o registro', ($restaurado['id'] ?? 0) === $idLixeira);
conferir(
    'o registro volta para o status ativo',
    $pdo->query("SELECT usu_status FROM sis_usuarios WHERE usu_id = {$idLixeira}")->fetchColumn() === STATUS_ATIVO,
);

$stmt = $pdo->prepare(
    'SELECT aud_valor_novo FROM sis_auditoria
      WHERE aud_acao = :acao AND aud_entidade = :entidade AND aud_entidade_id = :id
      ORDER BY aud_id DESC LIMIT 1'
);
$stmt->execute([':acao' => 'RESTAURAR', ':entidade' => 'sis_usuarios', ':id' => $idLixeira]);
$trilhaRestauro = (string) ($stmt->fetchColumn() ?: '');

conferir(
    'a trilha de RESTAURAR guarda o motivo escrito pelo administrador',
    str_contains($trilhaRestauro, 'verificação automática'),
    $trilhaRestauro,
);

conferir(
    'registro inexistente não é restaurado',
    recusa(fn () => $lixeira->restaurar('sis_usuarios', 999999999, $autorId, 'Motivo suficientemente longo.')),
);

// Devolve a conta de apoio à lixeira, para a próxima rodada encontrar o mesmo cenário.
$pdo->prepare('UPDATE sis_usuarios SET usu_status = :x WHERE usu_id = :id')
    ->execute([':x' => STATUS_EXCLUIDO, ':id' => $idLixeira]);

secao('Render das telas');

// A tela de auditoria subiu em 500 com as 16 verificações anteriores no verde: elas provavam
// repositório e serviço, e nenhuma tocava o template. Render com dado real é o que falta para
// a verificação valer como prova de que a tela existe. Compilação de todas as telas fica em
// scripts/verificar-telas.php; aqui é a renderização das telas da E6.
$telas = [
    'admin/auditoria.html.twig' => [
        'ativo'  => 'auditoria',
        'linhas' => $trilha->listar(null, null, null, null, 20),
        'total'  => $trilha->contar(null, null, null, null),
        'acoes'  => $trilha->acoesDistintas(),
        'filtro' => ['usuario' => null, 'acao' => null, 'dias' => 7],
    ],
    'admin/denuncias.html.twig' => [
        'ativo'     => 'denuncias',
        'fila'      => $servico->fila(null),
        'situacao'  => null,
        'situacoes' => DenunciaService::SITUACOES,
        'tipos'     => DenunciaService::TIPOS,
    ],
    'admin/denuncia.html.twig' => [
        'ativo'        => 'denuncias',
        'denuncia'     => $servico->porId($id),
        'tipos'        => DenunciaService::TIPOS,
        'situacoes'    => DenunciaService::SITUACOES,
        'providencias' => DenunciaService::PROVIDENCIAS,
    ],
];

foreach ($telas as $tela => $dados) {
    $erro = '';

    try {
        $html = View::render($tela, $dados);
    } catch (Throwable $e) {
        $html = '';
        $erro = $e->getMessage();
    }

    conferir("{$tela} renderiza com dado do banco", $html !== '', $erro);
}

printf(
    "\n%s  %d aprovadas, %d falharam\n",
    $falhou === 0 ? "\e[32mE6 (DENÚNCIAS E PAINEL) VERIFICADA\e[0m" : "\e[31mE6 COM FALHA\e[0m",
    $aprovado,
    $falhou,
);

exit($falhou === 0 ? 0 : 1);
