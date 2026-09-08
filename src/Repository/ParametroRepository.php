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
}
