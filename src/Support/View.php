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
            'cache'       => APP_DEBUG ? false : PATH_CACHE . '/twig',
            'debug'       => APP_DEBUG,
            'autoescape'  => 'html',
            'strict_variables' => APP_DEBUG,
        ]);

        $twig->addGlobal('app_url', APP_URL);
        $twig->addGlobal('url_img', URL_IMG);
        $twig->addGlobal('url_assets', URL_ASSETS);
        $twig->addGlobal('usuario', $_SESSION['usuario'] ?? null);

        $twig->addFunction(new TwigFunction('csrf_token', [Csrf::class, 'token']));

        self::$twig = $twig;

        return $twig;
    }

    public static function render(string $template, array $dados = []): string
    {
        return self::motor()->render($template, $dados);
    }
}
