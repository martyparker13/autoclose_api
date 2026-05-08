<?php

namespace App\Providers;

use App\Events\DealStatusChanged;
use App\Listeners\PushCreditApplication;
use App\Listeners\SendDealStatusNotification;
use App\Listeners\TriggerPostPurchaseJourney;
use App\Services\Integrations\DealerTrackService;
use App\Services\Integrations\RouteOneService;
use App\Models\Deal;
use App\Models\User;
use App\Models\Vehicle;
use App\Policies\DealPolicy;
use App\Policies\UserPolicy;
use App\Policies\VehiclePolicy;
use App\Repositories\DealRepository;
use App\Repositories\DealRepositoryInterface;
use App\Repositories\VehicleRepository;
use App\Repositories\VehicleRepositoryInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VehicleRepositoryInterface::class, VehicleRepository::class);
        $this->app->bind(DealRepositoryInterface::class, DealRepository::class);
        $this->app->singleton(DealerTrackService::class);
        $this->app->singleton(RouteOneService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(Deal::class, DealPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        Event::listen(DealStatusChanged::class, SendDealStatusNotification::class);
        Event::listen(DealStatusChanged::class, TriggerPostPurchaseJourney::class);
        Event::listen(DealStatusChanged::class, PushCreditApplication::class);
    }
}
