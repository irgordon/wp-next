<?php
declare( strict_types=1 );
namespace Psr\Container;
interface ContainerInterface {
	public function get( string $id ): mixed;
	public function has( string $id ): bool;
}
interface ContainerExceptionInterface {
}
interface NotFoundExceptionInterface extends ContainerExceptionInterface {
}
