<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * As regras da Certidão de Acervo Técnico que não podem morar em SQL nem em serviço: como a
 * resposta da API vira linha, o que o selo da CAT assina e quando uma CAT conta (D76).
 *
 * Irmã da `Support\Acervo`, e sem estado e sem banco pelo mesmo motivo: é o pedaço que carrega
 * decisão, e decisão precisa de teste rápido.
 *
 * ## As duas formas que a API dá a CAT
 *
 * `?p=profissionais/{rnp}/cats` lista as certidões do profissional só com os dados da certidão:
 * número, tipo, emissão, validade e finalidade. `?p=cats&rnp=&cat_numero=` devolve a mesma CAT
 * com as ARTs que ela agrupa, cada uma com as suas atividades TOS. É a segunda que liga a CAT ao
 * acervo, e por isso a importação precisa das duas: a lista para saber os números, o detalhe
 * para saber o que cada número certifica.
 *
 * ## O que o selo da CAT prova
 *
 * O mesmo que o Selo ART (ver `Support\Acervo`): integridade em repouso, não procedência. Ele
 * assina os campos da certidão **e a lista de ARTs que ela agrupa**, porque o dado que o motor
 * usa é justamente o vínculo. Quem acrescentar uma linha em `crea_cat_arts` por fora, para dar a
 * uma ART qualquer o reforço de uma CAT, quebra o selo da CAT na próxima conferência.
 */
final class Cat
{
    /** As colunas de `crea_cats` que vêm da API. Fora daqui: id, hash, datas, log e status. */
    private const CAMPOS = [
        'cat_numero',
        'cat_pro_rnp',
        'cat_tipo',
        'cat_dt_emissao',
        'cat_dt_validade',
        'cat_finalidade',
    ];

    /**
     * Resposta da API → colunas de `crea_cats`.
     *
     * Serve às duas formas (lista e detalhe), que trazem os mesmos campos da certidão. O RNP vem
     * de quem pediu, e não da resposta: a lista não o repete, e o detalhe o repete para ser
     * conferido por `pertence()`, não para ser gravado.
     *
     * @param array<string, mixed> $daApi
     * @return array<string, string|null>
     */
    public static function projetar(array $daApi, string $rnp): array
    {
        $linha = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $daApi[$campo] ?? null;
            $linha[$campo] = ($valor === null || $valor === '') ? null : (string) $valor;
        }

        $linha['cat_pro_rnp'] = $rnp;

        return $linha;
    }

    /**
     * O detalhe devolvido é mesmo a certidão que pedimos, e do titular que pedimos?
     *
     * A API filtra por RNP e número, então a resposta deveria sempre conferir. Conferir assim
     * mesmo é a regra do CAO (`PortfolioService::importarCao`): gravar uma resposta que não
     * confere atribuiria o acervo certificado de uma pessoa a outra, e isso não se desfaz
     * olhando a tela.
     *
     * @param array<string, mixed> $detalhe
     */
    public static function pertence(array $detalhe, string $rnp, string $numero): bool
    {
        $rnpVolta    = isset($detalhe['pro_rnp']) ? (string) $detalhe['pro_rnp'] : $rnp;
        $numeroVolta = (string) ($detalhe['cat_numero'] ?? '');

        return $rnpVolta === $rnp && $numeroVolta === $numero;
    }

    /**
     * As ARTs que a certidão agrupa, cada uma com as suas atividades, na forma que o
     * `PortfolioService::persistir()` recebe.
     *
     * ART sem número é descartada em silêncio pelo mesmo motivo do CAO (`Support\Cao`): não há
     * linha para gravar, e derrubar a certidão inteira por causa de um item mal formado apagaria
     * a evidência das outras.
     *
     * @param array<string, mixed> $detalhe
     * @return list<array{art: array<string, mixed>, atividades: list<array<string, mixed>>}>
     */
    public static function arts(array $detalhe): array
    {
        $arts = [];

        foreach ((array) ($detalhe['arts'] ?? []) as $art) {
            if (!is_array($art) || (string) ($art['art_numero'] ?? '') === '') {
                continue;
            }

            $arts[] = [
                'art'        => $art,
                'atividades' => array_values(array_filter(
                    (array) ($art['atividades'] ?? []),
                    'is_array',
                )),
            ];
        }

        return $arts;
    }

    /**
     * O selo da certidão: HMAC dos campos da CAT e da lista de ARTs que ela agrupa.
     *
     * A lista entra ordenada e sem repetição, para o selo não depender da ordem em que a API
     * devolveu as ARTs nem da ordem em que o banco as leu na conferência.
     *
     * @param array<string, string|null> $cat
     * @param list<string> $artNumeros
     */
    public static function selo(array $cat, array $artNumeros): string
    {
        $assinado = [];

        foreach (self::CAMPOS as $campo) {
            $assinado[$campo] = $cat[$campo] ?? null;
        }

        $numeros = array_values(array_unique(array_map('strval', $artNumeros)));
        sort($numeros, SORT_STRING);
        $assinado['arts'] = $numeros;

        return Crypto::selo($assinado);
    }

    /**
     * A CAT ainda vale na data informada?
     *
     * Validade nula conta como vigente: a API não prometeu o campo, e ausência de data não é
     * prova de vencimento. Datas chegam como `YYYY-MM-DD`, que se comparam como texto.
     */
    public static function vigente(?string $validade, string $hoje): bool
    {
        if ($validade === null || $validade === '') {
            return true;
        }

        return substr($validade, 0, 10) >= substr($hoje, 0, 10);
    }
}
