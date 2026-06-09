<?php
namespace TfSigner\Controllers;

use TfSigner\Core\Router;

class DashboardController
{
    public static function index(): string
    {
        // All dashboard data is loaded async via /api/dashboard-stats in JS
        return Router::view('dashboard');
    }
}
