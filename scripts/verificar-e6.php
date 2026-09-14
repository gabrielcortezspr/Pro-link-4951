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

use ProLink\Service\DenunciaService;
use ProLink\Service\ValidacaoException;
use ProLink\Support\Database;

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

printf(
    "\n%s  %d aprovadas, %d falharam\n",
    $falhou === 0 ? "\e[32mE6 (DENÚNCIAS E PAINEL) VERIFICADA\e[0m" : "\e[31mE6 COM FALHA\e[0m",
    $aprovado,
    $falhou,
);

exit($falhou === 0 ? 0 : 1);
