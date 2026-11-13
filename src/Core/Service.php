<?php
/**
 * A gathering that needs people: a date, a time, and how many of each role.
 *
 * @package Congregation
 */

namespace Congregation\Core;

/**
 * Service value object.
 */
final class Service {

	/**
	 * Constructor.
	 *
	 * @param int                $id    Id.
	 * @param string             $date  Y-m-d.
	 * @param string             $time  H:i.
	 * @param array<string, int> $needs Role => people needed.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $date,
		public readonly string $time,
		public readonly array $needs
	) {}

	/**
	 * Year and month, for monthly limits.
	 */
	public function month(): string {
		return substr( $this->date, 0, 7 );
	}
}
