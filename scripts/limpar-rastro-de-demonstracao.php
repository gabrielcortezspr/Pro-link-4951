<?php

declare(strict_types=1);

/**
 * Tira da vista o rastro que a própria verificação deixa nas contas de demonstração.
 *
 * ## O problema
 *
 * A suíte de ponta a ponta cria dado **de verdade**, pela interface, porque é isso que a torna
 * uma prova. Cada execução acrescenta uma experiência ao perfil do profissional de demonstração,
 * e depois de dezenas de execuções o perfil que a banca abre tem "Experiência de verificação
 * automática 153822" e "Plano de intervenção urbana em área central (175934)" repetidos, no meio
 * do que a pessoa de fato declarou.
 *
 * Não é defeito da aplicação: é consequência de testar pela interface, que é o certo. O que não
 * pode é a banca ver isso.
 *
 * ## O que este script faz, e o que ele recusa fazer
 *
 * **Exclusão lógica, nunca DELETE.** É a mesma regra do item 8.6j que vale para toda a
 * plataforma: o registro sai da operação, continua na lixeira administrativa, e a trilha de
 * auditoria continua fazendo sentido. Um script de limpeza que apagasse fisicamente contradiria
 * a tela que a demonstração usa para provar a exclusão lógica.
 *
 * **Só o que a verificação criou, e só em conta de demonstração.** O critério é o padrão de
 * título que os scripts e a suíte geram. Experiência escrita à mão fica, e conta que não seja
 * `@prolink.local` não é tocada.
 *
 * Uso:
 *   php scripts/limpar-rastro-de-demonstracao.php            mostra o que faria
 *   php scripts/limpar-rastro-de-demonstracao.php --aplicar  aplica
 */

require_once __DIR__ . '/../_config.php';

use ProLink\Support\Auditoria;
use ProLink\Support\Database;

$aplicar = in_array('--aplicar', $argv, true);
$pdo     = Database::conexao();

/**
 * Os padrões que a verificação gera. Cada um é literal o suficiente para não pegar texto humano:
 * quem escreve "Plano de intervenção urbana" à mão não põe um número de seis dígitos entre
 * parênteses no fim.
 */
$padroes = [
    'Experiência de verificação automática %',
    'Plano de intervenção urbana em área central (%)',
    'Experiência da jornada %',
];

$condicoes = implode(' OR ', array_fill(0, count($padroes), 'e.exp_titulo LIKE ?'));

$sql = "SELECT e.exp_id, e.exp_titulo, u.usu_nome
          FROM pro_experiencias e
          JOIN pro_profissionais p ON p.prf_id = e.exp_prf_id
          JOIN sis_usuarios u ON u.usu_id = p.prf_usu_id
         WHERE u.usu_email LIKE '%@prolink.local'
           AND e.exp_status = ?
           AND ({$condicoes})
         ORDER BY e.exp_id";

$stmt = $pdo->prepare($sql);
$stmt->execute(array_merge([STATUS_ATIVO], $padroes));
$alvos = $stmt->fetchAll();

if ($alvos === []) {
    printf("\e[32mNADA A LIMPAR\e[0m  nenhuma experiência de verificação nas contas de demonstração\n");
    exit(0);
}

printf("%d experiência(s) de verificação em conta de demonstração:\n\n", count($alvos));

foreach (array_slice($alvos, 0, 8) as $a) {
    printf("  %-28s %s\n", mb_substr((string) $a['usu_nome'], 0, 26), $a['exp_titulo']);
}

if (count($alvos) > 8) {
    printf("  ... e mais %d\n", count($alvos) - 8);
}

echo "\n";

if (!$aplicar) {
    printf("\e[33mSIMULAÇÃO\e[0m  rode com --aplicar para excluir logicamente estas %d\n", count($alvos));
    exit(0);
}

$excluidas = Database::transacao(function (\PDO $pdo) use ($alvos): int {
    $marcar = $pdo->prepare('UPDATE pro_experiencias SET exp_status = :x WHERE exp_id = :id');
    $feitas = 0;

    foreach ($alvos as $a) {
        $marcar->execute([':x' => STATUS_EXCLUIDO, ':id' => (int) $a['exp_id']]);

        // A trilha registra quem excluiu e por quê. Sem autor, porque não houve pessoa: a coluna
        // aceita nulo e a origem diz o que aconteceu, o que é mais honesto que carimbar a conta
        // de alguém numa ação que ela não praticou.
        Auditoria::registrar(
            Auditoria::EXCLUIR,
            'pro_experiencias',
            (int) $a['exp_id'],
            'exp_status',
            STATUS_ATIVO,
            ['status' => STATUS_EXCLUIDO, 'origem' => 'limpeza_de_rastro_de_verificacao'],
            null,
            $pdo,
        );

        ++$feitas;
    }

    return $feitas;
});

printf("\e[32mLIMPO\e[0m  %d experiência(s) em exclusão lógica, com registro na trilha\n", $excluidas);
printf("Elas continuam acessíveis em /admin/lixeira, que é o que o item 8.6j exige.\n");
