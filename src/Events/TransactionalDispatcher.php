<?php

namespace Neves\TransactionalEvents\Events;

use Illuminate\Support\Str;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Events\Dispatcher as EventDispatcher;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;

class TransactionalDispatcher implements DispatcherContract
{

    /**
     * The event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private $dispatcher;

    /**
     * The events that must have transactional behavior.
     *
     * @var array
     */
    private $transactionalEvents = [
        'eloquent.',
    ];

    /**
     * The events that are not considered on transactional layer.
     *
     * @var array
     */
    private $exclude = [
        'Illuminate\Database\Events',
    ];

    /**
     * The current pending events per transaction level of connections.
     *
     * @var array
     */
    private $pendingTransactionalEvents = [];

    /**
     * Create a new transactional event dispatcher instance.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $eventDispatcher
     */
    public function __construct(EventDispatcher $eventDispatcher)
    {
        $this->dispatcher = $eventDispatcher;
        $this->setUpListeners();
    }

    /**
     * Fire an event and call the listeners.
     *
     * @param  string|object  $event
     * @param  mixed  $payload
     * @param  bool  $halt
     * @return array|null
     */
    public function fire($event, $payload = [], $halt = false)
    {
        return $this->dispatch($event, $payload, $halt);
    }

    /**
     * Dispatch an event and call the listeners.
     *
     * @param  string|object $event
     * @param  mixed $payload
     * @param  bool $halt
     * @return array|null
     */
    public function dispatch($event, $payload = [], $halt = false)
    {
        if (is_object($event) && $event instanceof \Neves\TransactionalEvents\Contracts\TransactionalEvent) {
            $connection = $event->getConnection();
        } elseif (is_object($payload) && $payload instanceof \Illuminate\Database\Eloquent\Model) {
            $connection = $payload->getConnection();
        } else {
            return $this->dispatcher->dispatch($event, $payload, $halt);
        }

        if (! $this->isTransactionalEvent($connection, $event)) {
            return $this->dispatcher->dispatch($event, $payload, $halt);
        }

        $connectionId = spl_object_hash($connection);

        $transactionLevel = $connection->transactionLevel();
        $this->pendingTransactionalEvents[$connectionId][$transactionLevel][] = compact('event', 'payload');
    }

    /**
     * Flush all enqueued events.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @return void
     */
    public function commit(ConnectionInterface $connection)
    {
        $connectionId = spl_object_hash($connection);

        // Prevent events to be raised when a nested transaction is
        // committed, so no intermediate state is considered saved.
        // Dispatch events only after outer transaction commits.
        if ($connection->transactionLevel() > 0 || ! isset($this->pendingTransactionalEvents[$connectionId])) {
            return;
        }

        foreach ($this->pendingTransactionalEvents[$connectionId] as $transactionalLevel => $events) {
            foreach ($events as $event) {
                $this->dispatcher->dispatch($event['event'], $event['payload']);
            }
        }

        unset($this->pendingTransactionalEvents[$connectionId]);
    }

    /**
     * Set list of events that should be handled by transactional layer.
     *
     * @param  array|null  $events
     * @return void
     */
    public function setTransactionalEvents(array $events)
    {
        $this->transactionalEvents = $events;
    }

    /**
     * Set exceptions list.
     *
     * @param  array  $exclude
     * @return void
     */
    public function setExcludedEvents(array $exclude = [])
    {
        $this->exclude = array_merge(['Illuminate\Database\Events'], $exclude);
    }

    /**
     * Clear enqueued events.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @return void
     */
    public function rollback(ConnectionInterface $connection)
    {
        $connectionId = spl_object_hash($connection);

        $transactionLevel = $connection->transactionLevel() + 1;

        if ($transactionLevel > 1) {
            unset($this->pendingTransactionalEvents[$connectionId][$transactionLevel]);
        } else {
            unset($this->pendingTransactionalEvents[$connectionId]);
        }
    }

    /**
     * Check whether an event is a transactional event or not.
     *
     * @param  \Illuminate\Database\ConnectionInterface  $connection
     * @param  string|object $event
     * @return bool
     */
    private function isTransactionalEvent(ConnectionInterface $connection, $event)
    {
        if ($connection->transactionLevel() < 1) {
            return false;
        }

        return $this->shouldHandle($event);
    }

    /**
     * Check whether an event should be handled by this layer or not.
     *
     * @param  string|object  $event
     * @return bool
     */
    private function shouldHandle($event)
    {
        $eventName = is_string($event) ? $event : get_class($event);

        foreach ($this->exclude as $excluded) {
            if ($this->matches($excluded, $eventName)) {
                return false;
            }
        }

        foreach ($this->transactionalEvents as $transactionalEvent) {
            if ($this->matches($transactionalEvent, $eventName)) {
                return true;
            }
        }


        if (is_object($event) && $event instanceof \Neves\TransactionalEvents\Contracts\TransactionalEvent) {
            return true;
        }

        return false;
    }

    /**
     * Check whether an event name matches a pattern or not.
     *
     * @param  string  $pattern
     * @param  string  $event
     * @return bool
     */
    private function matches($pattern, $event)
    {
        return (Str::contains($pattern, '*') && Str::is($pattern, $event))
            || Str::startsWith($event, $pattern);
    }

    private function setUpListeners()
    {
        $this->dispatcher->listen(TransactionCommitted::class, function ($event) {
            $this->commit($event->connection);
        });

        $this->dispatcher->listen(TransactionRolledBack::class, function ($event) {
            $this->rollback($event->connection);
        });
    }

    /**
     * Register an event listener with the dispatcher.
     *
     * @param  string|array $events
     * @param  mixed $listener
     * @return void
     */
    public function listen($events, $listener)
    {
        $this->dispatcher->listen($events, $listener);
    }

    /**
     * Determine if a given event has listeners.
     *
     * @param  string $eventName
     * @return bool
     */
    public function hasListeners($eventName)
    {
        return $this->dispatcher->hasListeners($eventName);
    }

    /**
     * Register an event subscriber with the dispatcher.
     *
     * @param  object|string $subscriber
     * @return void
     */
    public function subscribe($subscriber)
    {
        $this->dispatcher->subscribe($subscriber);
    }

    /**
     * Dispatch an event until the first non-null response is returned.
     *
     * @param  string|object $event
     * @param  mixed $payload
     * @return array|null
     */
    public function until($event, $payload = [])
    {
        return $this->dispatcher->until($event, $payload);
    }

    /**
     * Register an event and payload to be fired later.
     *
     * @param  string $event
     * @param  array $payload
     * @return void
     */
    public function push($event, $payload = [])
    {
        $this->dispatcher->push($event, $payload);
    }

    /**
     * Flush a set of pushed events.
     *
     * @param  string $event
     * @return void
     */
    public function flush($event)
    {
        $this->dispatcher->flush($event);
    }

    /**
     * Remove a set of listeners from the dispatcher.
     *
     * @param  string $event
     * @return void
     */
    public function forget($event)
    {
        $this->dispatcher->forget($event);
    }

    /**
     * Forget all of the queued listeners.
     *
     * @return void
     */
    public function forgetPushed()
    {
        $this->dispatcher->forgetPushed();
    }

    /**
     * Dynamically pass methods to the default dispatcher.
     *
     * @param  string  $method
     * @param  array   $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->dispatcher->$method(...$parameters);
    }
}
