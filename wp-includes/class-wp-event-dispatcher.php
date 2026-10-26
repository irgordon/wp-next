<?php
declare( strict_types=1 );
namespace WordPress\Events;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;
use Psr\Container\ContainerInterface;
// Definitions carried forward from the earlier supplied event subsystem.
interface Rejectable_Event_Interface extends StoppableEventInterface {
	public function reject( string $reason ): void;
	public function is_rejected(): bool;
	public function get_rejection_reason(): ?string;
}
class WP_Post_Saving_Event implements Rejectable_Event_Interface {
	protected bool $propagation_stopped = false;
	protected ?string $rejection_reason = null;
	public function __construct( public readonly array $post_data, public readonly ?array $existing_post = null )
	{
	}
	public function reject( string $reason ): void
	{
		$this->rejection_reason = $reason;
		$this->propagation_stopped = true;
	}
	public function is_rejected(): bool
	{
		return null !== $this->rejection_reason;
	}
	public function get_rejection_reason(): ?string
	{
		return $this->rejection_reason;
	}
	public function isPropagationStopped(): bool
	{
		return $this->propagation_stopped;
	}
}
class WP_Hierarchical_Listener_Provider implements ListenerProviderInterface {
	protected array $listeners = [];
	public function __construct( protected ContainerInterface $container )
	{
	}
	public function add_listener( string $event_class, string $service_id, string $method ): void
	{
		$this->listeners[ $event_class ][] = [ $service_id, $method ];
	}
	public function getListenersForEvent( object $event ): iterable
	{
		$types = class_parents( $event ) ?: [];
		$types[] = $event::class;
		$types = array_merge( $types, class_implements( $event ) ?: [] );
		foreach ( array_unique( $types ) as $type ) {
			if ( ! isset( $this->listeners[ $type ] ) ) {
				continue;
			}
			foreach ( $this->listeners[ $type ] as [ $service_id, $method ] ) {
				yield [ $this->container->get( $service_id ), $method ];
			}
		}
	}
}
