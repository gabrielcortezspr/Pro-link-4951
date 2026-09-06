<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Support\View;

/** Painel administrativo (RF06). Por enquanto só prova a segregação de perfil; o conteúdo entra na E6. */
final class AdminController
{
    public function index(): string
    {
        return View::render('admin/index.html.twig');
    }
}
