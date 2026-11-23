<?php

namespace App\Providers;

use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn () => PixelforgeClient::fromConfig());
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
