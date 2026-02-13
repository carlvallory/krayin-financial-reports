<?php

namespace CarlVallory\KrayinFinancialReports\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;

class KrayinFinancialReportsServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        $this->loadViewsFrom(__DIR__ . '/../Resources/views', 'krayin-financial-reports');

        $this->loadRoutesFrom(__DIR__ . '/../Http/routes.php');

        Event::listen('core.menu.make', function ($menu) {
            $menu->add([
                'key'        => 'financial_reports',
                'name'       => 'Informes',
                'route'      => 'admin.financial_reports.index',
                'sort'       => 2,
                'icon-class' => 'icon-sales',
            ]);
        });
    }

    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
    }
}
