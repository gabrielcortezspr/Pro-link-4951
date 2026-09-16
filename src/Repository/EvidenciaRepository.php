<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * Leitura do índice de evidência (view `crea_evidencias`) para o motor de compatibilização.
 *
 * A view já entrega uma linha por (candidato, código TOS, ART, CAT) com os quatro níveis do
 * código em colunas próprias, e a herança do acervo da empresa (vínculo vigente, D19) mora
 * dentro dela. Aqui só há recorte e leitura.
 *
 * **A afinidade não é calculada em SQL.** O filtro daqui é grosso, por primeiro nível, que é o
 * que o índice resolve bem; a afinidade fina fica em `Support\Tos::afinidade()`, que já existe,
 * já está testada e é a única dona da regra. Repetir a tabela de pesos dentro de um CASE seria
 * a mesma decisão escrita em dois lugares, e o dia em que o administrador mudasse os pesos em
 * `sis_parametros` só um dos dois obedeceria.
 *
 * O universo de candidatos são os cadastrados na plataforma, e não a API: não existe busca
 * reversa por código TOS (ver docs/matching.md), e o item 10.4 veda varredura.
 */
final class EvidenciaRepository extends Repositorio
{
    private const COLUNAS = 'evi_candidato_tipo, evi_candidato_id, evi_tos_codigo,
                             evi_nivel1, evi_nivel2, evi_nivel3, evi_nivel4,
                             evi_art_numero, evi_art_situacao,
                             evi_art_local_uf, evi_art_local_municipio,
                             evi_cat_numero, evi_cat_dt_validade, evi_pro_rnp';

    /**
     * Evidências que compartilham o primeiro nível com pelo menos um código da demanda.
     *
     * Primeiro nível é o corte certo porque abaixo dele a afinidade é zero pela tabela de pesos:
     * grupo diferente não pontua. Trazer o resto seria carregar linha que o serviço descartaria.
     *
     * @param  list<string> $niveis1  primeiros níveis dos códigos da demanda
     * @return list<array<string, mixed>>
     */
    public function porPrimeiroNivel(array $niveis1): array
    {
        $niveis1 = array_values(array_unique(array_map('intval', $niveis1)));

        if ($niveis1 === []) {
            return [];
        }

        // Placeholder numerado por posição: ATTR_EMULATE_PREPARES está desligado, e nome repetido
        // na mesma query não funciona.
        $marcadores = [];
        $params     = [];

        foreach ($niveis1 as $i => $nivel) {
            $marcadores[]      = ":n{$i}";
            $params[":n{$i}"]  = $nivel;
        }

        $stmt = $this->pdo->prepare(
            'SELECT ' . self::COLUNAS . '
               FROM crea_evidencias
              WHERE evi_nivel1 IN (' . implode(', ', $marcadores) . ')
              ORDER BY evi_candidato_tipo, evi_candidato_id, evi_tos_codigo'
        );

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor, \PDO::PARAM_INT);
        }

        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * As mesmas evidências, agrupadas por candidato. É a forma que o motor consome: uma
     * pontuação por candidato, sobre o acervo inteiro dele.
     *
     * @param  list<string> $niveis1
     * @return array<string, list<array<string, mixed>>>  "P:12" => linhas
     */
    public function agrupadoPorCandidato(array $niveis1): array
    {
        $grupos = [];

        foreach ($this->porPrimeiroNivel($niveis1) as $linha) {
            $chave = $linha['evi_candidato_tipo'] . ':' . $linha['evi_candidato_id'];
            $grupos[$chave][] = $linha;
        }

        return $grupos;
    }

    /**
     * Quantas ARTs distintas o candidato tem no acervo inteiro, sem recorte por demanda.
     *
     * Alimenta o rótulo de perfil em construção (`match.early_career.min_arts`) e o retorno
     * decrescente da multiplicidade. Conta ART, não linha da view: uma ART com cinco atividades
     * aparece cinco vezes ali.
     */
    public function totalDeArts(string $tipo, int $candidatoId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT evi_art_numero)
               FROM crea_evidencias
              WHERE evi_candidato_tipo = :tipo AND evi_candidato_id = :id'
        );
        $stmt->bindValue(':tipo', $tipo);
        $stmt->bindValue(':id', $candidatoId, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Dono e id de cada ART, a partir do número — para o portão de visibilidade do feed (D52).
     *
     * A view `crea_evidencias` expõe `evi_art_numero` e não o id, e é pelo id que
     * `pro_visibilidade` guarda a escolha do titular. Resolver aqui, e não acrescentar a coluna à
     * view, mantém o filtro funcionando também para sessões **já gravadas**: o `msp_criterios`
     * delas guarda números, e sessão antiga é registro de auditoria que não se reescreve.
     *
     * O dono é sempre o profissional, mesmo quando o candidato do pool é a empresa que herdou o
     * acervo pelo quadro técnico: quem decide se a ART aparece é quem a assinou.
     *
     * @param  list<string> $numeros
     * @return array<string, array{id: int, usuario_id: int}> número => dono
     */
    public function donosPorNumero(array $numeros): array
    {
        $numeros = array_values(array_unique(array_filter(array_map('strval', $numeros))));

        if ($numeros === []) {
            return [];
        }

        // Marcadores próprios: o helper do Repositorio liga como inteiro, e número de ART é
        // string com letras ("AM2026...").
        $marcadores = [];
        $params     = [];

        foreach ($numeros as $i => $numero) {
            $marcadores[]     = ":n{$i}";
            $params[":n{$i}"] = $numero;
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.art_id, a.art_numero, p.prf_usu_id
               FROM crea_arts a
               JOIN pro_profissionais p
                 ON p.prf_rnp = a.art_pro_rnp AND p.prf_status = :ativo
              WHERE a.art_numero IN (' . implode(', ', $marcadores) . ')'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);

        foreach ($params as $nome => $valor) {
            $stmt->bindValue($nome, $valor);
        }

        $stmt->execute();

        $saida = [];

        foreach ($stmt->fetchAll() as $linha) {
            $saida[(string) $linha['art_numero']] = [
                'id'         => (int) $linha['art_id'],
                'usuario_id' => (int) $linha['prf_usu_id'],
            ];
        }

        return $saida;
    }
}
