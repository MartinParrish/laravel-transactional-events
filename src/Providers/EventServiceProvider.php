<?php

namespace Neves\TransactionalEvents\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Contracts\Events\Dispatcher as EventDispatcher;
use Neves\TransactionalEvents\Events\TransactionalDispatcher;
use Illuminate\Database\Eloquent\Model;

class EventServiceProvider extends ServiceProvider
{
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        if (! config('transactional-events.enable', true)) {
            return;
        }

        $this->app->extend('events', function () {
            $dispatcher = new TransactionalDispatcher(
                $this->app->make(EventDispatcher::class)
            );

            if (is_array($events = config('transactional-events.events'))) {
                $dispatcher->setTransactionalEvents($events);
            }

            $dispatcher->setExcludedEvents(config('transactional-events.excluded', []));

            return $dispatcher;
        });

        Model::setEventDispatcher($this->app['events']);

    }

    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        $this->app->configure('transactional-events');
    }
}
