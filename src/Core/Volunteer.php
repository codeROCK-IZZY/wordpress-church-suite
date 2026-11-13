<?php
/**
 * A person who serves.
 *
 * @package Congregation
 */

namespace Congregation\Core;

/**
 * Volunteer value object.
 */
final class Volunteer {

	/**
	 * Constructor.
	 *
	 * @param int          $id           Id.
	 * @param string       $name         Name.
	 * @param list<string> $roles        Roles they can do (e.g. "sound", "kids-leader").
	 * @param int          $per_month    How many times a month they are happy to serve.
	 * @param list<string> $away         Dates (Y-m-d) they cannot serve.
	 * @param int          $household    Household id (0 for none).
	 * @param bool         $together     Wants to serve at the same service as their household.
	 * @param string       $prefers      Preferred service time ("" for any), e.g. "09:00".
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly array $roles,
		public readonly int $per_month = 2,
		public readonly array $away = array(),
		public readonly int $household = 0,
		public readonly bool $together = false,
		public readonly string $prefers = ''
	) {}

	/**
	 * Can do a role.
	 *
	 * @param string $role Role.
	 */
	public function can( string $role ): bool {
		return in_array( $role, $this->roles, true );
	}

	/**
	 * Available on a date.
	 *
	 * @param string $date Y-m-d.
	 */
	public function free_on( string $date ): bool {
		return ! in_array( $date, $this->away, true );
	}
}
