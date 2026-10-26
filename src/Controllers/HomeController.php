<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Services\Plans;

final class HomeController
{
    public function index(): string
    {
        return view('home/index', [
            'title' => 'Лента заказов Wildberries в реальном времени',
            'plans' => array_filter(Plans::all(), static fn($p) => $p['public'] ?? true),
            'user'  => Auth::user(),
        ], 'main');
    }

    public function help(): string
    {
        return view('home/help', ['title' => 'Инструкция', 'user' => Auth::user()], 'main');
    }

    public function oferta(): string
    {
        return view('home/legal', ['title' => 'Публичная оферта', 'doc' => 'oferta', 'user' => Auth::user()], 'main');
    }

    public function privacy(): string
    {
        return view('home/legal', ['title' => 'Политика конфиденциальности', 'doc' => 'privacy', 'user' => Auth::user()], 'main');
    }
}
