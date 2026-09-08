<?php

declare(strict_types=1);

namespace ProLink\Support;

use RuntimeException;

/**
 * As três regras do acervo que não podem morar em SQL nem em controller: como uma resposta da
 * API vira linha, como duas respostas da mesma ART se combinam, e o que exatamente o Selo ART
 * assina (RF02, RF03; proposta cenário 03A).
 *
 * Classe sem estado e sem banco, de propósito. É o pedaço do portfólio que carrega decisão, e
 * decisão precisa de teste rápido — o `PortfolioService` orquestra, o `AcervoRepository` grava,
 * e o que se discute com a banca está aqui.
 *
 * ## O que o Selo ART prova, e o que ele não prova
 *
 * Prova que a linha não foi adulterada **depois de gravada**: o HMAC é recalculado na exibição a
 * partir do que está no banco e comparado com `art_hash`, e a chave nunca sai do servidor. Quem
 * editar `crea_arts` por fora — inclusive o administrador — quebra o selo (OWASP A08).
 *
 * **Não prova origem.** Ele não certifica que o dado veio da API oficial; só que não mudou desde
 * que o gravamos. Prova de origem exigiria assinatura do CREA, que a API não emite. A distinção
 * importa numa arguição: o selo é integridade em repouso, não autenticidade de procedência.
 */
final class Acervo
{
    /** As colunas de `crea_arts` que vêm da API. Fora daqui: id, hash, datas, log e status. */
    private const CAMPOS = [
        'art_numero',
        'art_pro_rnp',
        'art_tipo',
        'art_forma_registro',
        'art_contratante_nome',
        'art_objeto',
        'art_local_uf',
        'art_local_municipio',
        'art_situacao',
    ];

    /**
     * Resposta da API → colunas de `crea_arts`.
     *
     * Serve às três formas que a API dá a mesma ART, cada uma com um subconjunto de campos:
     * `?p=arts` (validação, sem local e sem atividades), `?p=profissionais/{rnp}/arts` (completa)
     * e o CAO (sem local, sem contratante, sem forma de registro). Campo ausente vira null, e é
     * a mescla que decide o que fazer com isso.
     *
     * @param array<string, mixed> $daApi
     * @return array<string, string|null>
     */
    public static function projetarArt(array $daApi, string $rnp): array
    {
        $linha = [];

        foreach (self::CAMPOS as $campo) {
            $valor = $daApi[$campo] ?? null;
            $linha[$campo] = $valor === null ? null : (string) $valor;
        }

        // A lista de ARTs do profissional não repete o RNP em cada item; a validação repete.
        // Identificador é string sempre: convertido a int, o zero à esquerda some (docs/api.md).
        $linha['art_pro_rnp'] = (string) ($daApi['pro_rnp'] ?? $rnp);

        return $linha;
    }

    /**
     * Atividades da API → linhas de `crea_art_atividades`, ordenadas pelo código.
     *
     * A ordem é fixada aqui porque o selo assina esta lista: duas respostas com as mesmas
     * atividades em ordem diferente precisam produzir o mesmo hash.
     *
     * @param array<int, array<string, mixed>> $daApi
     * @return list<array{tos_codigo: string, descricao: string|null}>
     */
    public static function projetarAtividades(array $daApi): array
    {
        $atividades = [];

        foreach ($daApi as $atividade) {
            $codigo = (string) ($atividade['tos_codigo'] ?? '');

            if ($codigo === '') {
                continue;
            }

            $atividades[$codigo] = [
                'tos_codigo' => $codigo,
                'descricao'  => isset($atividade['aat_descricao'])
                    ? (string) $atividade['aat_descricao']
                    : null,
            ];
        }

        // Ordena pela hierarquia numérica, não pelo texto: TOS_10 vem depois de TOS_2, e é
        // `Tos::ordenar` que sabe disso. Reindexa a lista na ordem que ela devolver.
        $ordenados = [];

        foreach (Tos::ordenar(array_keys($atividades)) as $codigo) {
            $ordenados[] = $atividades[$codigo];
        }

        return $ordenados;
    }

    /**
     * Combina o que já está gravado com o que a API acabou de devolver.
     *
     * A regra é uma só: **valor novo vence, exceto quando é nulo e já havia valor**. Existe
     * porque a mesma ART chega por caminhos com campos diferentes — associada à mão pela RF03,
     * a resposta não traz local; importada da lista do profissional, traz. Sem esta regra, a
     * ordem em que o usuário faz as coisas decidiria o que fica gravado, e reassociar uma ART
     * apagaria o município que a importação tinha preenchido.
     *
     * @param array<string, mixed>|null   $existente linha atual, ou null se a ART é nova
     * @param array<string, string|null>  $novo      projeção da resposta de agora
     * @return array<string, string|null>
     */
    public static function mesclar(?array $existente, array $novo): array
    {
        if ($existente === null) {
            return $novo;
        }

        // Mesmo número de ART com RNP diferente não é mescla, é conflito: `uq_art_numero` é
        // global, então a ART pertence a um profissional só. Sobrescrever em silêncio moveria
        // evidência de uma pessoa para outra.
        if (($existente['art_pro_rnp'] ?? null) !== null
            && $existente['art_pro_rnp'] !== $novo['art_pro_rnp']) {
            throw new RuntimeException(sprintf(
                'ART %s já está no acervo do RNP %s e a API a devolveu para o RNP %s.',
                $novo['art_numero'],
                $existente['art_pro_rnp'],
                $novo['art_pro_rnp'],
            ));
        }

        $mesclado = [];

        foreach (self::CAMPOS as $campo) {
            $mesclado[$campo] = $novo[$campo] ?? ($existente[$campo] ?? null);
        }

        return $mesclado;
    }

    /**
     * Atividades novas vencem, mas lista vazia não apaga o que existe.
     *
     * Mesma lógica da mescla de campos: `?p=arts` valida a titularidade sem devolver atividade
     * nenhuma, e uma revalidação não pode zerar a evidência TOS — que é justamente o único campo
     * desta massa que discrimina candidato.
     *
     * @param list<array{tos_codigo: string, descricao: string|null}> $existentes
     * @param list<array{tos_codigo: string, descricao: string|null}> $novas
     * @return list<array{tos_codigo: string, descricao: string|null}>
     */
    public static function mesclarAtividades(array $existentes, array $novas): array
    {
        return $novas === [] ? $existentes : $novas;
    }

    /**
     * O Selo ART: HMAC-SHA256 sobre a ART e suas atividades, canonicalizadas.
     *
     * As atividades entram no selo, e não só os campos da ART. Sem elas, acrescentar um código
     * TOS a uma ART no banco não quebraria o selo — e `tos_codigo` é a única informação desta
     * massa que discrimina um candidato de outro. Seria o lugar exato onde uma adulteração
     * compensaria.
     *
     * @param array<string, string|null> $art
     * @param list<array{tos_codigo: string, descricao: string|null}> $atividades
     */
    public static function selo(array $art, array $atividades): string
    {
        $assinado = [];

        foreach (self::CAMPOS as $campo) {
            $assinado[$campo] = $art[$campo] ?? null;
        }

        $assinado['atividades'] = self::projetarAtividades(array_map(
            static fn (array $a): array => [
                'tos_codigo'    => $a['tos_codigo'],
                'aat_descricao' => $a['descricao'],
            ],
            $atividades,
        ));

        return Crypto::selo($assinado);
    }
}
