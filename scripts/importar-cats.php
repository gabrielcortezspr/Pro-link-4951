<?php

declare(strict_types=1);

/**
 * Traz as Certidões de Acervo Técnico de quem se cadastrou antes da D76.
 *
 * Desde a D76 o cadastro importa as CATs logo depois das ARTs. Quem já estava cadastrado ficou
 * só com as ARTs, e este script completa o acervo dessas pessoas **pelo mesmo serviço** que o
 * cadastro usa (`PortfolioService::importarCats`), com o consentimento de consulta conferido
 * antes de cada chamada, auditoria e selo.
 *
 * **Consome chamada da API oficial**: uma para a lista de certidões do profissional e mais uma
 * por CAT encontrada (na massa, uma por profissional: duas chamadas por pessoa). Não é varredura
 * (item 10.4): é o acervo de cada titular cadastrado que consentiu, um de cada vez, e por isso o
 * limite é obrigatório.
 *
 * Uso:
 *   docker compose exec php php scripts/importar-cats.php --limite=20
 *   docker compose exec php php scripts/importar-cats.php --limite=20 --simular   (não chama nem grava)
 *
 * Idempotente: quem já tem CAT importada sai da fila. Quem foi consultado e não tem nenhuma
 * continua nela, e é consultado de novo se o script rodar outra vez.
 */

require_once dirname(__DIR__) . '/_config.php';

use ProLink\Repository\CatRepository;
use ProLink\Service\ApiIndisponivelException;
use ProLink\Service\PortfolioService;
use ProLink\Service\ValidacaoException;

$opcoes = getopt('', ['limite:', 'simular']);

if (!isset($opcoes['limite'])) {
    fwrite(STDERR, "Informe --limite=N. Cada profissional custa duas chamadas registradas da API.\n");
    exit(2);
}

$limite  = max(1, (int) $opcoes['limite']);
$simular = isset($opcoes['simular']);

$fila = (new CatRepository())->profissionaisSemCat($limite, FINALIDADE_CONSULTA_API);

printf(
    "\e[1mImportando CATs\e[0m  %s\n  na fila: %d (limite %d) · custo estimado: %d chamadas\n\n",
    $simular ? "\e[33mSIMULAÇÃO, nada é consultado nem gravado\e[0m" : 'consultando a API e gravando',
    count($fila),
    $limite,
    count($fila) * 2,
);

$portfolio = new PortfolioService();
$total     = ['cats' => 0, 'arts' => 0, 'sem_cat' => 0, 'recusadas' => 0, 'falhas' => 0];

foreach ($fila as $pessoa) {
    $rotulo = sprintf('%-36s RNP %s', mb_strimwidth($pessoa['usu_nome'], 0, 36, '…'), $pessoa['prf_rnp']);

    if ($simular) {
        printf("  %s  seria consultado\n", $rotulo);
        continue;
    }

    try {
        $r = $portfolio->importarCats($pessoa['usu_id'], $pessoa['prf_rnp']);
    } catch (ApiIndisponivelException $e) {
        // Para aqui: insistir com o resto da fila só produziria uma rajada de falhas registrada
        // do lado da organização (a mesma regra do sincronizar-status.php).
        printf("  %s  \e[31mAPI indisponível, parando: %s\e[0m\n", $rotulo, $e->getMessage());
        $total['falhas']++;
        break;
    } catch (ValidacaoException $e) {
        printf("  %s  \e[33mpulado: %s\e[0m\n", $rotulo, $e->getMessage());
        $total['falhas']++;
        continue;
    }

    $total['cats']      += $r['cats'];
    $total['arts']      += $r['arts_certificadas'];
    $total['recusadas'] += $r['recusadas'];
    $total['sem_cat']   += $r['cats'] === 0 ? 1 : 0;

    printf(
        "  %s  %d CAT(s), %d ART(s) certificada(s)%s\n",
        $rotulo,
        $r['cats'],
        $r['arts_certificadas'],
        $r['recusadas'] > 0 ? sprintf(", \e[33m%d recusada(s)\e[0m", $r['recusadas']) : '',
    );
}

printf(
    "\nCATs importadas: %d · ARTs certificadas: %d · sem CAT: %d · recusadas: %d · falhas: %d\n",
    $total['cats'], $total['arts'], $total['sem_cat'], $total['recusadas'], $total['falhas'],
);

exit($total['falhas'] > 0 ? 1 : 0);
