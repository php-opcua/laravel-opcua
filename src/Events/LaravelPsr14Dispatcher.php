<?php

declare(strict_types=1);

namespace PhpOpcua\LaravelOpcua\Events;

use Illuminate\Contracts\Events\Dispatcher as LaravelDispatcher;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Adapts Laravel's event dispatcher to the PSR-14 interface.
 *
 * The OPC UA client and session manager dispatch their event objects on a
 * {@see EventDispatcherInterface}. Laravel's own {@see \Illuminate\Events\Dispatcher}
 * is *shaped* like PSR-14 (it can dispatch an object by class name) but does
 * not formally implement the interface, so passing it directly would fail the
 * type check. Wrapping it here lets OPC UA events reach listeners registered
 * with the normal `Event::listen(...)` API — including inside the
 * `opcua:session` daemon process.
 *
 * Bound as the default {@see EventDispatcherInterface} in
 * {@see \PhpOpcua\LaravelOpcua\OpcuaServiceProvider::register()} (via
 * `singletonIf`, so an application may bind its own implementation instead).
 */
final class LaravelPsr14Dispatcher implements EventDispatcherInterface
{
    public function __construct(private readonly LaravelDispatcher $events)
    {
    }

    public function dispatch(object $event): object
    {
        $this->events->dispatch($event);

        return $event;
    }
}
