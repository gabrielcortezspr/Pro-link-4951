<?php

declare(strict_types=1);

namespace ProLink\Support;

/**
 * Roteador mínimo. Casa método + caminho e devolve [Controller::class, 'metodo'].
 * Segmentos dinâmicos usam {nome} e chegam ao método como argumentos nomeados.
 */
final class Router
{
    /** @var array<string, array<string, array{0: class-string, 1: string, 2: string}>> */
    private array $rotas = [];

    public function get(string $caminho, string $controller, string $metodo, string $perfil = 'PUBLICO'): void
    {
        $this->registrar('GET', $caminho, $controller, $metodo, $perfil);
    }

    public function post(string $caminho, string $controller, string $metodo, string $perfil = 'PUBLICO'): void
    {
        $this->registrar('POST', $caminho, $controller, $metodo, $perfil);
    }

    private function registrar(string $verbo, string $caminho, string $controller, string $metodo, string $perfil): void
    {
        $this->rotas[$verbo][$caminho] = [$controller, $metodo, $perfil];
    }

    /**
     * @return array{controller: class-string, metodo: string, perfil: string, params: array}|null
     */
    public function resolver(string $verbo, string $caminho): ?array
    {
        $caminho = '/' . trim(parse_url($caminho, PHP_URL_PATH) ?: '/', '/');

        foreach ($this->rotas[$verbo] ?? [] as $padrao => [$controller, $metodo, $perfil]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $padrao) . '$#';

            if (preg_match($regex, $caminho, $achados)) {
                $params = array_filter($achados, 'is_string', ARRAY_FILTER_USE_KEY);

                return compact('controller', 'metodo', 'perfil', 'params');
            }
        }

        return null;
    }
}
