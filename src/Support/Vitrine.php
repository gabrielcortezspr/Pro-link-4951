<?php

declare(strict_types=1);

namespace ProLink\Support;

use DateTimeImmutable;

/**
 * As regras da vitrine de demandas abertas (D89), sem banco: os filtros que a URL traz, a relação
 * de cada demanda com o acervo de quem olha, a contagem por área, a ordem e a página.
 *
 * **A área é o grupo da Tabela de Obras e Serviços** (o primeiro nível do código, 46 grupos). É o
 * vocabulário do Conselho, é o que liga a demanda a quem sabe fazer, e é por isso que ele organiza
 * a vitrine em vez de uma categoria inventada aqui.
 *
 * **"Na minha área" é informação sobre a demanda, não nota de pessoa.** Diz a quem olha se o
 * acervo dele tem a mesma atividade ou uma do mesmo subgrupo, pela mesma afinidade do motor, para
 * ele decidir se vale se candidatar. Sai como frase, nunca como porcentagem, e a vitrine não se
 * ordena por ela (item 10.1).
 */
final class Vitrine
{
    public const POR_PAGINA = 20;

    /** Afinidade mínima para "na minha área": dois níveis em comum, o mesmo subgrupo. */
    public const AFINIDADE_MINIMA = 0.40;

    public const ORDENS = ['recentes', 'inicio'];

    /**
     * Lê e valida os filtros da URL. O que não é valor válido é ignorado, e não vira erro: a
     * vitrine é navegação, e um link velho não pode derrubá-la.
     *
     * @param array<string, mixed> $get
     * @return array{areas: list<int>, atividade: ?string, texto: ?string, uf: ?string,
     *               municipio: ?string, contrato: ?string, inicio: ?string, ate: ?string,
     *               minha_area: bool, para_mim: bool, ordem: string, pagina: int}
     */
    public static function lerFiltros(array $get, string $hoje, bool $temAcervo, bool $podeCandidatar): array
    {
        $texto = static function (mixed $v, int $max = 120): ?string {
            $v = is_string($v) ? trim($v) : '';

            return $v === '' ? null : mb_substr($v, 0, $max);
        };

        $areas = [];

        foreach ((array) ($get['area'] ?? []) as $a) {
            if (is_scalar($a) && ctype_digit((string) $a) && (int) $a > 0 && (int) $a < 1000) {
                $areas[(int) $a] = true;
            }
        }

        $uf       = mb_strtoupper((string) ($texto($get['uf'] ?? null, 2) ?? ''));
        $contrato = mb_strtoupper((string) ($texto($get['contrato'] ?? null, 40) ?? ''));
        $inicio   = in_array($get['inicio'] ?? null, ['30', 'aberto'], true) ? (string) $get['inicio'] : null;
        $ordem    = in_array($get['ordem'] ?? null, self::ORDENS, true) ? (string) $get['ordem'] : 'recentes';
        $pagina   = is_scalar($get['pagina'] ?? null) && ctype_digit((string) $get['pagina'])
            ? max(1, (int) $get['pagina']) : 1;

        // Os dois interruptores têm padrão ligado para quem pode usá-los, e a URL os desliga com
        // "0": é o que deixa "ver todas" ser um link, e não um estado escondido na sessão.
        $minha = $temAcervo && ($get['minha_area'] ?? '1') !== '0';
        $paraMim = $podeCandidatar && ($get['para'] ?? 'mim') !== 'todas';

        return [
            'areas'      => array_keys($areas),
            'atividade'  => $texto($get['atividade'] ?? null, 80),
            'texto'      => $texto($get['q'] ?? null, 120),
            'uf'         => in_array($uf, Preferencias::UFS, true) ? $uf : null,
            'municipio'  => $texto($get['municipio'] ?? null, 120),
            'contrato'   => Preferencias::contratoValido($contrato) ? $contrato : null,
            'inicio'     => $inicio === '30' ? 'ate' : $inicio,
            'ate'        => $inicio === '30' ? (new DateTimeImmutable($hoje))->modify('+30 days')->format('Y-m-d') : null,
            'minha_area' => $minha,
            'para_mim'   => $paraMim,
            'ordem'      => $ordem,
            'pagina'     => $pagina,
        ];
    }

    /**
     * Como a demanda se relaciona com o acervo de quem olha: 'mesma' (algum código idêntico),
     * 'proxima' (mesmo subgrupo ou mais perto) ou null.
     *
     * @param list<string> $codigosDaDemanda
     * @param list<string> $acervo
     * @param list<float>  $pesos os cinco níveis de `match.afinidade.niveis`
     */
    public static function relacaoComAcervo(array $codigosDaDemanda, array $acervo, array $pesos): ?string
    {
        $melhor = 0.0;

        foreach ($codigosDaDemanda as $d) {
            foreach ($acervo as $a) {
                $melhor = max($melhor, Tos::afinidade($d, $a, $pesos));

                if ($melhor >= 1.0) {
                    return 'mesma';
                }
            }
        }

        return $melhor >= self::AFINIDADE_MINIMA ? 'proxima' : null;
    }

    /**
     * A atividade bate com o termo? Compara sem acento e sem caixa com o grupo, o subgrupo e a
     * obra ou serviço de cada código da demanda, e também com o próprio código.
     *
     * @param list<array<string, mixed>> $tos
     */
    public static function temAtividade(array $tos, string $termo): bool
    {
        $termo = Tos::normalizar($termo);

        foreach ($tos as $t) {
            $alvo = Tos::normalizar(implode(' ', [
                $t['dts_tos_codigo'] ?? '', $t['tos_grupo'] ?? '', $t['tos_subgrupo'] ?? '',
                $t['tos_obra_servico'] ?? '', $t['tos_complementar'] ?? '',
            ]));

            if (str_contains($alvo, $termo)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quantas demandas há em cada área, para os chips. Uma demanda com atividades de duas áreas
     * conta nas duas, e por isso a soma dos chips pode passar do total.
     *
     * @param list<array<string, mixed>> $demandas cada uma com a chave `tos`
     * @return list<array{nivel1: int, grupo: string, quantidade: int}> por nome do grupo
     */
    public static function contarAreas(array $demandas): array
    {
        $areas = [];

        foreach ($demandas as $d) {
            $vistas = [];

            foreach ((array) ($d['tos'] ?? []) as $t) {
                $n = (int) $t['tos_nivel1'];

                if (isset($vistas[$n])) {
                    continue;
                }

                $vistas[$n] = true;
                $areas[$n] ??= ['nivel1' => $n, 'grupo' => (string) $t['tos_grupo'], 'quantidade' => 0];
                $areas[$n]['quantidade']++;
            }
        }

        $areas = array_values($areas);
        usort($areas, static fn (array $a, array $b): int => strcmp(Tos::normalizar($a['grupo']), Tos::normalizar($b['grupo'])));

        return $areas;
    }

    /** @param list<array<string, mixed>> $demandas */
    public static function ordenar(array $demandas, string $ordem): array
    {
        if ($ordem === 'inicio') {
            // Prazo mais próximo primeiro; prazo em aberto vai para o fim, porque não tem data.
            usort($demandas, static function (array $a, array $b): int {
                $x = $a['dem_inicio_ate'] ?? null;
                $y = $b['dem_inicio_ate'] ?? null;

                return match (true) {
                    $x === $y   => (int) $b['dem_id'] <=> (int) $a['dem_id'],
                    $x === null => 1,
                    $y === null => -1,
                    default     => strcmp((string) $x, (string) $y),
                };
            });
        }

        return $demandas;
    }
}
