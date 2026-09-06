<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Roteador mínimo. Casa método + caminho e devolve controller, método, perfis permitidos e
 * parâmetros do caminho. Segmentos dinâmicos usam {nome}.
 *
 * Perfis: PERFIL_PUBLICO significa "qualquer um, inclusive anônimo" — o edital diz que todo
 * perfil tem "tudo do público". Qualquer outro valor exige login e um dos perfis listados.
 * Não existe superusuário implícito: rota de ADMIN só aceita ADMIN.
 */
final class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string, 2: list<string>}>> */
    private array $rotas = [];

    /** @param string|list<string> $perfis */
    public function get(string $caminho, string $controller, string $metodo, string|array $perfis = PERFIL_PUBLICO): void
    {
        $this->registrar('GET', $caminho, $controller, $metodo, $perfis);
    }

    /** @param string|list<string> $perfis */
    public function post(string $caminho, string $controller, string $metodo, string|array $perfis = PERFIL_PUBLICO): void
    {
        $this->registrar('POST', $caminho, $controller, $metodo, $perfis);
    }

    /** @param string|list<string> $perfis */
    private function registrar(string $verbo, string $caminho, string $controller, string $metodo, string|array $perfis): void
    {
        $this->rotas[$verbo][$caminho] = [$controller, $metodo, (array) $perfis];
    }

    /**
     * @return array{controller: class-string, metodo: string, perfis: list<string>, params: array<string, string>}|null
     */
    public function resolver(string $verbo, string $caminho): ?array
    {
        $caminho = '/' . trim((string) (parse_url($caminho, PHP_URL_PATH) ?: '/'), '/');

        foreach ($this->rotas[strtoupper($verbo)] ?? [] as $padrao => [$controller, $metodo, $perfis]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $padrao) . '$#';

            if (preg_match($regex, $caminho, $achados)) {
                $params = array_filter($achados, 'is_string', ARRAY_FILTER_USE_KEY);

                return compact('controller', 'metodo', 'perfis', 'params');
            }
        }

        return null;
    }

    /** Verdadeiro se a rota é aberta a qualquer visitante. */
    public static function ehPublica(array $perfis): bool
    {
        return in_array(PERFIL_PUBLICO, $perfis, true);
    }
}
