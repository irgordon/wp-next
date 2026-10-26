<?php
declare( strict_types=1 );
namespace Psr\EventDispatcher;
interface EventDispatcherInterface { public function dispatch( object $event ): object;
}
interface ListenerProviderInterface { public function getListenersForEvent( object $event ): iterable;
}
interface StoppableEventInterface { public function isPropagationStopped(): bool;
}
