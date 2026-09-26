<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * As regras de Minhas demandas (D93), sem banco: em que situação cada demanda está, os filtros
 * que a URL traz, a busca, a contagem por área e a ordem.
 *
 * A lista era uma tabela só, com rascunho, aberta e encerrada misturadas, e um filtro de texto no
 * navegador. A demanda encerrada existia ali, mas só quem digitasse "encerrada" a separava. Agora
 * a situação é a primeira escolha da tela, como abas, e o resto segue o molde da vitrine (D89):
 * busca, áreas da TOS, "com algo esperando você" e ordem, tudo na URL.
 *
 * **Ordenar por pendência não é ranking** (item 10.1): ordena as demandas da própria conta pelo
 * que espera resposta nelas, e não compara pessoa nenhuma.
 */
final class MinhasDemandas
{
    public const SITUACOES = ['abertas', 'rascunhos', 'encerradas', 'todas'];

    public const ORDENS = ['recentes', 'pendencias', 'inicio'];

    /** Onde a demanda está, na linguagem das abas. */
    public static function situacao(array $d): string
    {
        return match (true) {
            ($d['dem_dt_publicacao'] ?? null) === null  => 'rascunhos',
            ($d['dem_situacao'] ?? '') === 'ENCERRADA' => 'encerradas',
            default                                     => 'abertas',
        };
    }

    /**
     * Lê e valida os filtros. Valor inválido é ignorado, como na vitrine: um link velho não
     * derruba a tela.
     *
     * @param array<string, mixed> $get
     * @return array{situacao: string, texto: ?string, areas: list<int>, pendentes: bool, ordem: string}
     */
    public static function lerFiltros(array $get): array
    {
        $situacao = (string) ($get['situacao'] ?? '');
        $ordem    = (string) ($get['ordem'] ?? '');
        $texto    = trim((string) ($get['q'] ?? ''));

        $areas = [];

        foreach ((array) ($get['area'] ?? []) as $a) {
            if (is_scalar($a) && ctype_digit((string) $a) && (int) $a > 0) {
                $areas[] = (int) $a;
            }
        }

        return [
            'situacao'  => in_array($situacao, self::SITUACOES, true) ? $situacao : 'abertas',
            'texto'     => $texto === '' ? null : mb_substr($texto, 0, 120),
            'areas'     => array_values(array_unique($areas)),
            'pendentes' => ($get['pendentes'] ?? '') === '1',
            'ordem'     => in_array($ordem, self::ORDENS, true) ? $ordem : 'recentes',
        ];
    }

    /**
     * Quantas há em cada aba. Sobre a lista inteira, e não sobre a filtrada: o número da aba diz
     * quantas demandas a conta tem naquela situação, e não muda enquanto se digita a busca.
     *
     * @param list<array<string, mixed>> $demandas
     * @return array{abertas: int, rascunhos: int, encerradas: int, todas: int}
     */
    public static function contarSituacoes(array $demandas): array
    {
        $contagem = ['abertas' => 0, 'rascunhos' => 0, 'encerradas' => 0, 'todas' => count($demandas)];

        foreach ($demandas as $d) {
            $contagem[self::situacao($d)]++;
        }

        return $contagem;
    }

    /**
     * A busca livre: título, escopo, município e as atividades da TOS, sem acento e sem caixa.
     */
    public static function bate(array $d, string $termo): bool
    {
        $alvo = Tos::normalizar(implode(' ', [
            $d['dem_titulo'] ?? '', $d['dem_escopo'] ?? '', $d['dem_local_municipio'] ?? '',
        ]));

        return str_contains($alvo, Tos::normalizar($termo))
            || Vitrine::temAtividade((array) ($d['tos'] ?? []), $termo);
    }

    /**
     * O que espera por quem publicou: candidatura não aberta e mensagem não lida (D87). Demanda
     * encerrada não espera nada: o que ficou sem ler nela é histórico, e contá-lo faria "com algo
     * esperando você" e a ordem por pendência trazerem de volta o que já foi fechado.
     */
    public static function temPendencia(array $d): bool
    {
        return self::pendencias($d) > 0;
    }

    public static function pendencias(array $d): int
    {
        return self::situacao($d) === 'encerradas' ? 0 : (int) ($d['painel']['pendentes'] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $demandas
     * @return list<array<string, mixed>>
     */
    public static function ordenar(array $demandas, string $ordem): array
    {
        if ($ordem === 'inicio') {
            return Vitrine::ordenar($demandas, 'inicio');
        }

        usort($demandas, static function (array $a, array $b) use ($ordem): int {
            if ($ordem === 'pendencias') {
                $porPendencia = self::pendencias($b) <=> self::pendencias($a);

                if ($porPendencia !== 0) {
                    return $porPendencia;
                }
            }

            return (int) $b['dem_id'] <=> (int) $a['dem_id'];
        });

        return $demandas;
    }
}
