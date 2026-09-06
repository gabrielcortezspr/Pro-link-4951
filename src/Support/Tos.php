<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Operações sobre o código da Tabela de Obras e Serviços.
 *
 * O código É a hierarquia: TOS_<grupo>.<subgrupo>.<obra_servico>[.<complementar>].
 * Daí sai a medida de afinidade do motor, sem NLP e sem tabela de sinônimos.
 *
 * Duas armadilhas, ambas documentadas em docs/modelo-de-dados.md:
 *   · nunca ordenar como texto — "TOS_10" vem antes de "TOS_2" em ordem alfabética;
 *   · códigos têm três ou quatro níveis; nenhum tem cinco.
 */
final class Tos
{
    /** 'TOS_1.1.2.1' => [1, 1, 2, 1] */
    public static function niveis(string $codigo): array
    {
        $limpo = str_starts_with($codigo, 'TOS_') ? substr($codigo, 4) : $codigo;

        return array_map('intval', explode('.', $limpo));
    }

    /**
     * Ordena códigos pela hierarquia numérica, não pelo texto.
     *
     * Armadilha do PHP: `<=>` entre arrays compara o TAMANHO antes dos elementos, então
     * [1,1,2,1] seria "maior" que [10,1,1]. Preenchemos com zero até quatro níveis para a
     * comparação ser elemento a elemento, como em Python. Pego por tests/Support/TosTest.php.
     */
    public static function ordenar(array $codigos): array
    {
        $chave = static fn (string $c): array => array_pad(self::niveis($c), 4, 0);

        usort($codigos, static fn (string $a, string $b): int => $chave($a) <=> $chave($b));

        return $codigos;
    }

    /**
     * Afinidade entre um código da demanda e um do acervo: quantos componentes iniciais
     * coincidem. Coincidência total dos dois códigos é o caso máximo, tratado à parte porque
     * TOS_1.1.6 (três níveis) e TOS_1.1.6.2 (quatro) não são a mesma atividade.
     *
     * @param array<int, float> $pesos índice 0..4 = componentes iguais; vem de sis_parametros
     *                                 (chave match.afinidade.niveis), nunca de constante.
     */
    public static function afinidade(string $demanda, string $acervo, array $pesos): float
    {
        $a = self::niveis($demanda);
        $b = self::niveis($acervo);

        $iguais = 0;
        $limite = min(count($a), count($b));

        for ($i = 0; $i < $limite; $i++) {
            if ($a[$i] !== $b[$i]) {
                break;
            }

            $iguais++;
        }

        if ($iguais === count($a) && $iguais === count($b)) {
            return (float) $pesos[4];
        }

        return (float) $pesos[min($iguais, 3)];
    }

    /**
     * Normaliza texto para comparação: NFD, remove marcas diacríticas, casefold.
     * Mesmo procedimento do exemplo em docs/api.md. Não usar iconv//TRANSLIT: no Alpine (musl)
     * ele devolve "amaz^onia" em vez de "amazonia".
     */
    public static function normalizar(string $texto): string
    {
        $decomposto = \Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto;
        $semMarcas  = preg_replace('/\p{Mn}+/u', '', $decomposto) ?? $decomposto;

        return mb_strtolower(trim($semMarcas), 'UTF-8');
    }
}
