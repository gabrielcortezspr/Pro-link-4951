<?php

declare(strict_types=1);

namespace ProLink\Controller;

use ProLink\Support\View;

final class HomeController
{
    public function index(): string
    {
        return View::render('home.html.twig');
    }
}
