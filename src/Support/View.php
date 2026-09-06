<?php

declare(strict_types=1);

namespace ProLink\Support;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Twig como camada de apresentação. O edital, item 8.2, veda PHP misturado no HTML; nenhum
 * template desta aplicação contém lógica de negócio, e o autoescape do Twig cobre XSS
 * (item 8.5d) por padrão.
 */
final class View
{
    private static ?Environment $twig = null;

    public static function motor(): Environment
    {
        if (self::$twig instanceof Environment) {
            return self::$twig;
        }

        $twig = new Environment(new FilesystemLoader(PATH_TEMPLATES), [
            'cache'            => APP_DEBUG ? false : PATH_CACHE . '/twig',
            'debug'            => APP_DEBUG,
            'autoescape'       => 'html',
            'strict_variables' => APP_DEBUG,
        ]);

        $twig->addGlobal('app_url', APP_URL);
        $twig->addGlobal('url_img', URL_IMG);
        $twig->addGlobal('url_assets', URL_ASSETS);

        // Funções, não globais: o valor é lido no momento do render, depois do login/logout.
        $twig->addFunction(new TwigFunction('usuario', [Sessao::class, 'usuarioAtual']));
        $twig->addFunction(new TwigFunction('csrf_token', [Csrf::class, 'token']));
        $twig->addFunction(new TwigFunction('flashes', [Flash::class, 'consumir']));

        self::$twig = $twig;

        return $twig;
    }

    public static function render(string $template, array $dados = []): string
    {
        return self::motor()->render($template, $dados);
    }

    /** Resposta de erro padronizada: status HTTP + template. */
    public static function erro(int $codigo, string $mensagem): string
    {
        http_response_code($codigo);

        return self::render('erro.html.twig', ['codigo' => $codigo, 'mensagem' => $mensagem]);
    }

    public static function redirecionar(string $caminho): never
    {
        header('Location: ' . APP_URL . $caminho, true, 303);
        exit;
    }
}
