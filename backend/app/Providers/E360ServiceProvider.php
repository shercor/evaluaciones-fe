<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\E360\E360Client;
use App\Support\E360\Resources\EvaluationsApi;
use App\Support\E360\Resources\GroupsApi;
use App\Support\E360\Resources\ParticipantsApi;
use App\Support\E360\Resources\ResultsApi;
use App\Support\E360\Resources\TasksApi;
use App\Support\E360\Resources\TemplatesApi;
use App\Support\E360\Resources\TenantsApi;
use Illuminate\Support\ServiceProvider;

class E360ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(E360Client::class, fn () => new E360Client(config('e360')));

        // Un servicio por recurso de la API. Todos comparten el mismo cliente,
        // que es el único que conoce el token.
        $this->app->singleton(TenantsApi::class);
        $this->app->singleton(EvaluationsApi::class);
        $this->app->singleton(ParticipantsApi::class);
        $this->app->singleton(TemplatesApi::class);
        $this->app->singleton(GroupsApi::class);
        $this->app->singleton(TasksApi::class);
        $this->app->singleton(ResultsApi::class);
    }
}
