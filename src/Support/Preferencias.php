<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * O vocabulário das duas dimensões autodeclaradas do item 3.2: tipo de contrato e abrangência
 * geográfica.
 *
 * ## Por que existe uma lista fechada
 *
 * As duas dimensões comparam o que a demanda pede com o que o candidato declarou. Comparação só
 * significa alguma coisa se os dois lados escreverem a mesma palavra — e enquanto os dois campos
 * eram texto livre, não escreviam: a demanda dizia "AM" e o perfil dizia "Manaus e região
 * metropolitana", a comparação exata falhava sempre, e a dimensão devolvia zero em vez de sair da
 * média. Zero é uma afirmação ("este candidato não atende"), e era uma afirmação falsa.
 *
 * O vocabulário fica aqui, e não em `sis_parametros`, porque não é calibragem do motor: mudar a
 * lista muda o que os dois formulários oferecem e o que já está gravado significa. É desenho, não
 * preferência do administrador.
 *
 * ## Abrangência é lista de UF, não raio em quilômetros
 *
 * A demanda declara `dem_local_uf` e `dem_local_municipio`; a API não devolve coordenada de nada.
 * Um raio em quilômetros exigiria geocodificar município, que é dado que não temos e que nenhuma
 * das duas pontas sabe informar com honestidade. A lista de UFs é grosseira e é verdadeira: o
 * candidato marca onde aceita trabalhar, e a comparação é pertinência, não distância.
 *
 * `QUALQUER` dos dois lados é declaração de flexibilidade, e pontua cheio — o mesmo tratamento
 * que `Compatibilidade::correspondenciaDeclarada` já dava ao tipo de contrato.
 */
final class Preferencias
{
    /** Valor que casa com tudo, nas duas dimensões. */
    public const QUALQUER = 'QUALQUER';

    /**
     * Regimes de contratação oferecidos aos dois lados.
     *
     * Chave gravada em `prf_tipo_contrato` e `dem_tipo_contrato`; valor é o rótulo da tela. A
     * chave é ASCII e em caixa alta de propósito: é ela que vai para o banco e para a comparação,
     * e rótulo com acento em coluna de comparação é a armadilha que o `CLAUDE.md` descreve.
     */
    public const CONTRATOS = [
        self::QUALQUER => 'Qualquer regime',
        'CLT'          => 'CLT',
        'PJ'           => 'Pessoa jurídica',
        'OBRA_CERTA'   => 'Obra certa (empreitada)',
        'TEMPORARIO'   => 'Temporário / por projeto',
        'CONSULTORIA'  => 'Consultoria',
    ];

    /** As 27 unidades da federação. Fonte da verdade do formulário e da validação. */
    public const UFS = [
        'AC', 'AL', 'AM', 'AP', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MG', 'MS', 'MT', 'PA',
        'PB', 'PE', 'PI', 'PR', 'RJ', 'RN', 'RO', 'RR', 'RS', 'SC', 'SE', 'SP', 'TO',
    ];

    /** Quantas UFs cabem em `prf_disponibilidade` (VARCHAR(120)): 27 × "XX," = 81 caracteres. */
    public const ABRANGENCIA_TAMANHO_MAXIMO = 120;

    public static function contratoValido(?string $valor): bool
    {
        return $valor !== null && $valor !== '' && isset(self::CONTRATOS[$valor]);
    }

    /**
     * Põe a abrangência na forma canônica de gravação, ou devolve null se não sobrar nada válido.
     *
     * Aceita o que o formulário manda (lista de UFs) e o que um humano digitaria numa migração
     * ("am, rr"), porque a coluna existia antes desta lista e pode ter texto antigo. UF repetida
     * cai fora, e a ordem é a de `UFS` para que dois perfis com a mesma escolha gravem a mesma
     * string — comparar sessões de compatibilização gravadas depende disso.
     *
     * @param list<string>|string|null $bruto
     */
    public static function normalizarAbrangencia(array|string|null $bruto): ?string
    {
        if ($bruto === null) {
            return null;
        }

        $partes = is_array($bruto) ? $bruto : explode(',', $bruto);
        $limpo  = [];

        foreach ($partes as $parte) {
            $parte = mb_strtoupper(trim((string) $parte));

            if ($parte === self::QUALQUER) {
                // Flexibilidade total absorve qualquer lista: declarar "QUALQUER, AM" e guardar
                // as duas coisas deixaria a coluna dizendo duas coisas ao mesmo tempo.
                return self::QUALQUER;
            }

            if (in_array($parte, self::UFS, true)) {
                $limpo[$parte] = true;
            }
        }

        if ($limpo === []) {
            return null;
        }

        return implode(',', array_values(array_filter(
            self::UFS,
            static fn (string $uf): bool => isset($limpo[$uf]),
        )));
    }

    /**
     * As UFs que a abrangência declarada cobre. Lista vazia para `QUALQUER`, que não é um
     * conjunto de UFs e sim a ausência de restrição — quem pergunta "cobre esta UF?" deve usar
     * `abrangenciaCobre()`, que trata o caso.
     *
     * @return list<string>
     */
    public static function ufsDaAbrangencia(?string $declarada): array
    {
        if ($declarada === null || $declarada === '' || $declarada === self::QUALQUER) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (string $uf): string => mb_strtoupper(trim($uf)), explode(',', $declarada)),
            static fn (string $uf): bool => in_array($uf, self::UFS, true),
        ));
    }

    /**
     * A abrangência declarada alcança esta UF?
     *
     * Devolve **null** quando não há o que comparar — sem declaração do candidato ou sem local na
     * demanda. Null é o que tira a dimensão da média; false seria dizer "não atende", que é outra
     * afirmação e não é esta.
     */
    public static function abrangenciaCobre(?string $declarada, ?string $uf): ?bool
    {
        $uf = $uf === null ? '' : mb_strtoupper(trim($uf));

        if ($declarada === null || $declarada === '' || $uf === '') {
            return null;
        }

        if ($declarada === self::QUALQUER) {
            return true;
        }

        $ufs = self::ufsDaAbrangencia($declarada);

        // Coluna com texto que não é nem QUALQUER nem UF nenhuma (resquício de quando o campo era
        // livre) é dado que não sabemos ler: não vira "não atende", vira ausência.
        return $ufs === [] ? null : in_array($uf, $ufs, true);
    }
}
