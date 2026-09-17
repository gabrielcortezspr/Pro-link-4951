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
