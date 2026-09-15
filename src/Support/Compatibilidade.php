<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * O cálculo do motor de compatibilização, sem banco e sem sessão.
 *
 * Aqui mora só aritmética sobre dados já carregados: as seis dimensões do item 3.2 do edital e a
 * composição delas. Quem busca evidência é `Repository\EvidenciaRepository`, quem grava a sessão é
 * `Service\CompatibilizacaoService`. A separação existe para esta parte ser testável sem subir
 * banco, que é onde as regras finas (retorno decrescente, dimensão ausente, saturação) precisam de
 * caso de teste e não de inspeção visual.
 *
 * Três regras que valem para tudo daqui:
 *
 * **Nada passa de 1.** Todo reforço satura: multiplicidade e CAT empurram o valor na direção de 1
 * sem nunca ultrapassar, porque score acima do teto quebraria a comparação com o limiar.
 *
 * **Dimensão sem dado sai da média, não vale zero.** Perfil incompleto seria punido, e o edital
 * pede inclusão de quem está começando. O peso da dimensão ausente é redistribuído entre as
 * presentes pela própria divisão pela soma dos pesos usados.
 *
 * **Nada aqui ordena candidato.** O resultado é um número por candidato para comparar com o
 * limiar, e só. A ordem do pool é sorteada pela semente da sessão (item 10.1).
 */
final class Compatibilidade
{
    /**
     * Quanto a multiplicidade e a CAT conseguem empurrar uma dimensão na direção de 1.
     *
     * Não são pesos de dimensão (esses vivem em `sis_parametros`, sob supervisão do
     * administrador): são a forma da curva de reforço. Ficam aqui, e não no banco, porque mexer
     * neles é mudar o desenho do cálculo, não calibrar uma preferência.
     */
    public const REFORCO_CAT = 0.30;

    /** As seis dimensões do item 3.2, na ordem em que a interface as apresenta. */
    public const DIMENSOES = [
        'competencia',
        'area',
        'localizacao',
        'experiencia',
        'contrato',
        'disponibilidade',
    ];

    /**
     * Competência técnica: a única dimensão inteiramente sustentada por documento da API.
     *
     * Para cada código pedido pela demanda, procura no acervo do candidato a melhor afinidade
     * (`Tos::afinidade`), e a reforça por duas evidências de força: quantas ARTs distintas
     * sustentam aquela afinidade, e se alguma delas está coberta por CAT. O resultado é a média
     * das contribuições ponderada pelo peso que a demanda deu a cada código (principal vale mais
     * que secundária, `dts_peso`).
     *
     * @param  array<string, float>        $codigosDaDemanda  código TOS => peso declarado
     * @param  list<array<string, mixed>>  $acervo            linhas de `crea_evidencias`
     * @param  list<float>                 $pesosAfinidade    os cinco níveis, de `sis_parametros`
     * @return array{score: ?float, evidencias: list<array<string, mixed>>}
     */
    public static function competencia(
        array $codigosDaDemanda,
        array $acervo,
        array $pesosAfinidade,
    ): array {
        if ($codigosDaDemanda === [] || $acervo === []) {
            return ['score' => null, 'evidencias' => []];
        }

        $somaPesos       = 0.0;
        $somaPonderada   = 0.0;
        $justificativas  = [];

        foreach ($codigosDaDemanda as $codigo => $peso) {
            $peso = (float) $peso;

            if ($peso <= 0) {
                continue;
            }

            $melhor      = 0.0;
            $arts        = [];      // ARTs distintas que sustentam a melhor afinidade
            $temCat      = false;
            $codigoAcervo = null;
            $catNumero   = null;

            foreach ($acervo as $linha) {
                $afinidade = Tos::afinidade($codigo, (string) $linha['evi_tos_codigo'], $pesosAfinidade);

                if ($afinidade <= 0) {
                    continue;
                }

                // Afinidade melhor recomeça a contagem: multiplicidade só conta no mesmo patamar,
                // senão cinco correspondências fracas empatariam com uma exata.
                if ($afinidade > $melhor + 0.0001) {
                    $melhor       = $afinidade;
                    $arts         = [];
                    $temCat       = false;
                    $codigoAcervo = (string) $linha['evi_tos_codigo'];
                    $catNumero    = null;
                }

                if (abs($afinidade - $melhor) < 0.0001) {
                    $arts[(string) $linha['evi_art_numero']] = true;

                    if (($linha['evi_cat_numero'] ?? null) !== null) {
                        $temCat    = true;
                        $catNumero = (string) $linha['evi_cat_numero'];
                    }
                }
            }

            $somaPesos += $peso;

            if ($melhor <= 0) {
                continue;   // o código não tem correspondência nenhuma: contribui zero
            }

            $valor = self::reforcarPorVolume($melhor, count($arts));

            if ($temCat) {
                $valor = self::saturar($valor, self::REFORCO_CAT);
            }

            $somaPonderada += $peso * $valor;

            $justificativas[] = [
                'codigo_demanda' => $codigo,
                'codigo_acervo'  => $codigoAcervo,
                'afinidade'      => round($melhor, 3),
                'arts'           => array_keys($arts),
                'cat'            => $catNumero,
                'contribuicao'   => round($valor, 3),
            ];
        }

        if ($somaPesos <= 0) {
            return ['score' => null, 'evidencias' => []];
        }

        return [
            'score'      => round($somaPonderada / $somaPesos, 3),
            'evidencias' => $justificativas,
        ];
    }

    /**
     * Reforço por volume, com retorno decrescente.
     *
     * Cinco ARTs no mesmo código valem mais que uma e menos que cinco vezes uma. A raiz é o que
     * mantém isso verdadeiro, e há uma razão de edital por trás: volume alto de ART é, na prática,
     * antiguidade de carreira, e deixar o volume dominar transformaria o motor num ranking
     * implícito por tempo de profissão (item 10.1).
     */
    private static function reforcarPorVolume(float $base, int $quantidade): float
    {
        if ($quantidade <= 1) {
            return $base;
        }

        // 1 ART: nada. 4 ARTs: metade do que falta para 1. 9 ARTs: dois terços. Nunca chega a 1.
        return self::saturar($base, 1 - 1 / sqrt($quantidade));
    }

    /** Empurra o valor `$fracao` do caminho que falta até 1, sem nunca ultrapassar. */
    private static function saturar(float $valor, float $fracao): float
    {
        return $valor + (1 - $valor) * max(0.0, min(1.0, $fracao));
    }

    /**
     * Área de atuação: a modalidade do candidato cobre o grupo TOS que a demanda pede?
     *
     * Binária por dimensão e proporcional no conjunto: das áreas pedidas, quantas o candidato
     * atende. Sem modalidade cadastrada devolve null, e a dimensão sai da média.
     *
     * @param  list<int> $gruposDaDemanda   primeiros níveis dos códigos pedidos
     * @param  list<int> $gruposDoCandidato grupos cobertos pelas modalidades do candidato
     */
    public static function area(array $gruposDaDemanda, array $gruposDoCandidato): ?float
    {
        if ($gruposDaDemanda === [] || $gruposDoCandidato === []) {
            return null;
        }

        $pedidos    = array_unique($gruposDaDemanda);
        $atendidos  = count(array_intersect($pedidos, $gruposDoCandidato));

        return round($atendidos / count($pedidos), 3);
    }

    /**
     * Localização: onde o candidato já executou, comparado com onde a demanda acontece.
     *
     * Mesmo município vale cheio, mesma UF vale parcial, fora disso zero. Demanda sem local
     * declarado devolve null: não é o candidato que está incompleto, é a demanda, e penalizar
     * todo mundo igualmente é o mesmo que não ter a dimensão.
     *
     * Vale dizer, porque afeta a leitura da demonstração: nesta massa todas as ARTs são de
     * Manaus/AM, então a dimensão não separa candidato nenhum. Ela existe como regra de negócio,
     * não como critério discriminante nos dados fictícios.
     *
     * @param list<array{uf: ?string, municipio: ?string}> $locaisDoAcervo
     */
    public static function localizacao(?string $uf, ?string $municipio, array $locaisDoAcervo): ?float
    {
        if ($uf === null || $uf === '' || $locaisDoAcervo === []) {
            return null;
        }

        $melhor = 0.0;

        foreach ($locaisDoAcervo as $local) {
            if (strcasecmp((string) ($local['uf'] ?? ''), $uf) !== 0) {
                continue;
            }

            $melhor = max($melhor, 0.6);

            if ($municipio !== null && $municipio !== ''
                && Tos::normalizar((string) ($local['municipio'] ?? '')) === Tos::normalizar($municipio)) {
                return 1.0;
            }
        }

        return round($melhor, 3);
    }

    /**
     * Experiência declarada: dimensão autodeclarada, e o peso dela diz isso (0.10 contra 0.40 da
     * competência). Pontua por ter relato relacionado ao que a demanda pede, e pontua cheio
     * quando o relato está amarrado a uma ART do próprio candidato, que é o único caso em que o
     * autodeclarado encosta em evidência.
     *
     * @param list<array{vinculada: bool}> $experiencias relatos que tocam os códigos da demanda
     */
    public static function experiencia(array $experiencias, int $totalDeclarado): ?float
    {
        if ($totalDeclarado <= 0) {
            return null;
        }

        if ($experiencias === []) {
            return 0.0;
        }

        $comArt = count(array_filter($experiencias, static fn (array $e): bool => $e['vinculada']));

        return $comArt > 0 ? 1.0 : 0.5;
    }

    /**
     * Abrangência geográfica: a UF onde a demanda acontece está entre as que o candidato aceita?
     *
     * Dimensão própria, e não mais um `correspondenciaDeclarada()` entre a UF da demanda e o
     * campo de abrangência. A comparação exata entre os dois era errada por construção: de um
     * lado vinha "AM", do outro um texto livre que nunca seria a string "AM", e a dimensão
     * devolvia 0.0 — afirmando que o candidato não atende, quando o que havia era um campo que o
     * motor não sabia ler. Agora o vocabulário é fechado (`Support\Preferencias`) e o que não dá
     * para ler volta null, saindo da média como manda a regra do topo desta classe.
     */
    public static function abrangencia(?string $ufDaDemanda, ?string $declarada): ?float
    {
        $cobre = Preferencias::abrangenciaCobre($declarada, $ufDaDemanda);

        return $cobre === null ? null : ($cobre ? 1.0 : 0.0);
    }

    /**
     * Tipo de contrato: preferência declarada contra o que a demanda pede. Autodeclarada dos dois
     * lados, nula quando qualquer um dos dois não declarou.
     *
     * "QUALQUER" dos dois lados casa com tudo: é declaração de flexibilidade, não ausência de
     * dado, e por isso pontua cheio em vez de sair da média.
     */
    public static function correspondenciaDeclarada(?string $daDemanda, ?string $doCandidato): ?float
    {
        if ($doCandidato === null || $doCandidato === '') {
            return null;
        }

        if ($daDemanda === null || $daDemanda === '') {
            return null;
        }

        if ($daDemanda === Preferencias::QUALQUER || $doCandidato === Preferencias::QUALQUER) {
            return 1.0;
        }

        return strcasecmp($daDemanda, $doCandidato) === 0 ? 1.0 : 0.0;
    }

    /**
     * Compõe o score final: média das dimensões presentes, ponderada pelos pesos do administrador.
     *
     * Dimensão nula é retirada do numerador e do denominador, o que redistribui o peso dela entre
     * as que sobraram. Candidato sem nenhuma dimensão medível devolve null e não chega a ser
     * comparado com o limiar.
     *
     * @param  array<string, ?float> $dimensoes
     * @param  array<string, float>  $pesos
     */
    public static function compor(array $dimensoes, array $pesos): ?float
    {
        $soma      = 0.0;
        $somaPesos = 0.0;

        foreach ($dimensoes as $nome => $valor) {
            if ($valor === null) {
                continue;
            }

            $peso = (float) ($pesos[$nome] ?? 0);

            if ($peso <= 0) {
                continue;
            }

            $soma      += $peso * $valor;
            $somaPesos += $peso;
        }

        return $somaPesos > 0 ? round($soma / $somaPesos, 3) : null;
    }

    /**
     * Embaralha o pool de forma reproduzível a partir da semente da sessão.
     *
     * É o que substitui a ordenação por score, vedada pelo item 10.1: quem entrou com 0.9 e quem
     * entrou com 0.6 aparecem em ordem sorteada. Reproduzível de propósito, para o administrador
     * refazer qualquer sessão passada a partir da semente gravada (item 12.3).
     *
     * Ordena por hash de (semente + chave do candidato) em vez de usar `shuffle()` com seed
     * global: não depende do estado do gerador do PHP nem da versão dele, então a mesma semente
     * devolve a mesma ordem em qualquer máquina, hoje e na apresentação.
     *
     * @param  list<array<string, mixed>> $pool
     * @return list<array<string, mixed>>
     */
    public static function embaralhar(array $pool, string $semente, string $chave = 'chave'): array
    {
        usort($pool, static function (array $a, array $b) use ($semente, $chave): int {
            return strcmp(
                hash('sha256', $semente . '|' . (string) $a[$chave]),
                hash('sha256', $semente . '|' . (string) $b[$chave]),
            );
        });

        return $pool;
    }
}
