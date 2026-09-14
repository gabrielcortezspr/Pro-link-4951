<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Leitura da Certidão de Acervo Operacional e do quadro técnico (RF02; edital Anexo I, item 6).
 *
 * Classe sem estado e sem banco, como `Support\Acervo` e pelo mesmo motivo: é o pedaço da
 * integração que carrega decisão, e decisão precisa de teste rápido.
 *
 * ## A estrutura do CAO não é a que a documentação da organização descreve
 *
 * Não existe a chave `cao_arts`. O que a API devolve é um objeto plano com a empresa e uma
 * árvore de dois níveis:
 *
 *     { emp_cnpj, emp_razao_social, emp_nome_fantasia, emp_registro_crea,
 *       quadro_tecnico: [ { pro_nome, pro_rnp, pro_registro_crea, qut_funcao,
 *                           arts: [ { art_numero, ..., atividades: [...] } ] } ] }
 *
 * **O profissional contém as ARTs, e não o contrário.** Confirmado contra a API em 06/09/2026 e
 * capturado em `fixtures/empresa_cao.json`. Achatar essa árvore é o serviço desta classe: cada
 * ART sai daqui já sabendo de qual RNP ela é, que é o que `crea_arts` precisa e o que a herança
 * da empresa depende.
 *
 * ## O CAO não filtra vínculo encerrado, e nós também não
 *
 * O CAO devolve o acervo inteiro que o conselho atribui à empresa, sem dizer nada sobre datas de
 * vínculo — `qut_dt_fim` só existe no endpoint `quadro-tecnico`. Gravamos o que a certidão trouxe,
 * inteiro: é cache de resposta real, datado e selado. Quem aplica a regra de herança é a view
 * `crea_evidencias`, num lugar só, e ela só enxerga vínculo com `qut_dt_fim IS NULL` (D19).
 * Filtrar na importação espalharia a mesma regra por dois lugares, e o dia em que os dois
 * discordassem ninguém saberia qual estava certo.
 */
final class Cao
{
    /**
     * Achata a árvore do CAO numa lista de ARTs, cada uma com o RNP de quem a registrou.
     *
     * Profissional sem RNP e ART sem número são descartados em silêncio: sem esses dois campos
     * não há linha em `crea_arts` para gravar, e derrubar a importação inteira por causa de um
     * item malformado custaria o acervo todo da empresa.
     *
     * @param array<string, mixed> $cao resposta de `?p=empresas/{registro}/cao`
     * @return list<array{rnp: string, art: array<string, mixed>, atividades: array<int, array<string, mixed>>}>
     */
    public static function acervo(array $cao): array
    {
        $achatado = [];

        foreach (self::lista($cao['quadro_tecnico'] ?? null) as $profissional) {
            if (!is_array($profissional)) {
                continue;
            }

            $rnp = (string) ($profissional['pro_rnp'] ?? '');

            if ($rnp === '') {
                continue;
            }

            foreach (self::lista($profissional['arts'] ?? null) as $art) {
                if (!is_array($art) || (string) ($art['art_numero'] ?? '') === '') {
                    continue;
                }

                $achatado[] = [
                    'rnp'        => $rnp,
                    'art'        => $art,
                    'atividades' => self::lista($art['atividades'] ?? null),
                ];
            }
        }

        return $achatado;
    }

    /**
     * Resposta de `?p=empresas/{registro}/quadro-tecnico` → linhas de `crea_quadro_tecnico`.
     *
     * `qut_dt_fim` nulo significa vínculo vigente e chega como `null`, não como string vazia —
     * a distinção importa porque é dela que a herança de acervo depende, e `'' === null` é falso
     * em toda comparação que a view faz. `normalizarData` devolve null para os dois casos.
     *
     * @param array<int, mixed> $daApi
     * @return list<array{pro_rnp: string, pro_nome: ?string, tipo: ?string, funcao: ?string,
     *                    dt_inicio: ?string, dt_fim: ?string}>
     */
    public static function vinculos(array $daApi): array
    {
        $vinculos = [];

        foreach ($daApi as $linha) {
            if (!is_array($linha)) {
                continue;
            }

            $rnp = (string) ($linha['pro_rnp'] ?? '');

            if ($rnp === '') {
                continue;
            }

            $vinculos[] = [
                'pro_rnp'   => $rnp,
                'pro_nome'  => self::texto($linha['pro_nome'] ?? null),
                'tipo'      => self::texto($linha['qut_tipo'] ?? null),
                'funcao'    => self::texto($linha['qut_funcao'] ?? null),
                'dt_inicio' => self::data($linha['qut_dt_inicio'] ?? null),
                'dt_fim'    => self::data($linha['qut_dt_fim'] ?? null),
            ];
        }

        return $vinculos;
    }

    /**
     * O registro no CREA que o CAO afirma ser o dono daquela certidão.
     *
     * Serve de conferência: pedimos o CAO de um registro e a resposta diz de quem ele é. Se os
     * dois discordarem, gravar o acervo seria atribuir à empresa errada.
     *
     * @param array<string, mixed> $cao
     */
    public static function registroCrea(array $cao): ?string
    {
        $registro = (string) ($cao['emp_registro_crea'] ?? '');

        return $registro === '' ? null : $registro;
    }

    // ---------------------------------------------------------------- interno

    /** @return array<int, mixed> */
    private static function lista(mixed $valor): array
    {
        return is_array($valor) ? array_values($valor) : [];
    }

    private static function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /** Data da API → `DATE` do banco, ou null. Aceita `YYYY-MM-DD` e `YYYY-MM-DD HH:MM:SS`. */
    private static function data(mixed $valor): ?string
    {
        $texto = self::texto($valor);

        if ($texto === null) {
            return null;
        }

        return preg_match('/^(\d{4}-\d{2}-\d{2})/', $texto, $m) === 1 ? $m[1] : null;
    }
}
