<?php

declare(strict_types=1);

namespace ProLink\Repository;

/**
 * `sis_parametros`: os números do motor e da operação, editáveis pelo administrador sem deploy.
 *
 * Lê com cache de processo porque o motor consulta os mesmos pesos muitas vezes por requisição
 * (`docs/matching.md`). O cache dura uma requisição — parâmetro alterado pelo painel vale a
 * partir da próxima, o que é o comportamento certo para valor que muda de vez em quando.
 */
final class ParametroRepository extends Repositorio
{
    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    public function numero(string $chave, float $padrao): float
    {
        $valor = $this->valor($chave);

        return $valor === null || !is_numeric($valor) ? $padrao : (float) $valor;
    }

    public function inteiro(string $chave, int $padrao): int
    {
        return (int) $this->numero($chave, (float) $padrao);
    }

    public function texto(string $chave, string $padrao = ''): string
    {
        return $this->valor($chave) ?? $padrao;
    }

    private function valor(string $chave): ?string
    {
        if (self::$cache === null) {
            $stmt = $this->pdo->prepare(
                'SELECT par_chave, par_valor FROM sis_parametros WHERE par_status = :ativo'
            );
            $stmt->execute([':ativo' => STATUS_ATIVO]);

            self::$cache = [];

            foreach ($stmt->fetchAll() as $linha) {
                self::$cache[$linha['par_chave']] = $linha['par_valor'];
            }
        }

        return self::$cache[$chave] ?? null;
    }

    /** Descarta o cache. Usado pelo painel do administrador ao salvar, e pelos scripts. */
    public static function esquecer(): void
    {
        self::$cache = null;
    }

    /**
     * Todos os parâmetros ativos, com os metadados que a tela do administrador precisa.
     *
     * Ordenado por grupo e depois por chave, e não por `par_id`, porque a ordem de inserção da
     * carga inicial não é ordem de leitura: o administrador procura pelo assunto.
     *
     * @return list<array<string, mixed>>
     */
    public function todos(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT par_id, par_chave, par_valor, par_tipo, par_grupo, par_descricao, par_sensivel
               FROM sis_parametros
              WHERE par_status = :ativo
              ORDER BY par_grupo, par_chave'
        );

        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Uma linha completa pela chave, ou null.
     *
     * Separado de `valor()` de propósito: aquele é o caminho quente do motor, com cache de
     * processo e devolvendo só o valor; este lê do banco sempre, porque quem edita precisa do
     * valor que está gravado agora, não do que foi lido no começo da requisição.
     *
     * @return array<string, mixed>|null
     */
    public function porChave(string $chave): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT par_id, par_chave, par_valor, par_tipo, par_grupo, par_descricao, par_sensivel
               FROM sis_parametros
              WHERE par_chave = :chave
                AND par_status = :ativo'
        );

        $stmt->bindValue(':chave', $chave);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return $stmt->fetch() ?: null;
    }

    /**
     * A última alteração de cada parâmetro: quando, e por quem.
     *
     * Vem de `sis_auditoria`, que já guarda o antes e o depois de cada gravação. Não existe coluna
     * de "alterado em" em `sis_parametros`, e não deveria existir: seria o mesmo fato em dois
     * lugares, e o segundo divergiria no primeiro caminho de código que esquecesse dele.
     *
     * Uma consulta para todas as chaves, e não uma por parâmetro: são onze linhas na tela, e onze
     * consultas correlacionadas para montar uma coluna seria o padrão N+1 que a revisão de 15/09
     * já tirou do portão de privacidade do pool.
     *
     * @return array<string, array{quando: string, quem: string|null}>
     */
    public function ultimasAlteracoes(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.aud_campo, MAX(a.aud_id) AS ultima
               FROM sis_auditoria a
              WHERE a.aud_entidade = :entidade
                AND a.aud_acao = :acao
                AND a.aud_campo IS NOT NULL
              GROUP BY a.aud_campo'
        );

        $stmt->bindValue(':entidade', 'sis_parametros');
        $stmt->bindValue(':acao', 'EDITAR');
        $stmt->execute();

        $ids = [];

        foreach ($stmt->fetchAll() as $linha) {
            $ids[(int) $linha['ultima']] = (string) $linha['aud_campo'];
        }

        if ($ids === []) {
            return [];
        }

        $linhas = $this->buscarPorIds(
            static fn (array $marcadores): string =>
                'SELECT a.aud_id, a.aud_campo, a.aud_dt_registro, u.usu_nome
                   FROM sis_auditoria a
                   LEFT JOIN sis_usuarios u ON u.usu_id = a.aud_usu_id
                  WHERE a.aud_id IN (' . implode(', ', $marcadores) . ')',
            array_keys($ids),
        );

        $saida = [];

        foreach ($linhas as $linha) {
            $saida[(string) $linha['aud_campo']] = [
                'quando' => (string) $linha['aud_dt_registro'],
                'quem'   => $linha['usu_nome'] === null ? null : (string) $linha['usu_nome'],
            ];
        }

        return $saida;
    }

    /**
     * Grava o valor de uma chave que já existe.
     *
     * Não cria chave nova de propósito: parâmetro novo nasce em `estrutura.sql`, junto do código
     * que o lê e do valor padrão que ele assume quando a linha falta. Chave criada pela tela
     * ficaria sem leitor e sem padrão, e o `UPDATE` que não casa devolve `false` para quem chama
     * tratar em vez de gravar em silêncio.
     */
    public function atualizar(string $chave, string $valor): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sis_parametros
                SET par_valor = :valor
              WHERE par_chave = :chave
                AND par_status = :ativo'
        );

        $stmt->bindValue(':valor', $valor);
        $stmt->bindValue(':chave', $chave);
        $stmt->bindValue(':ativo', STATUS_ATIVO);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }
}
