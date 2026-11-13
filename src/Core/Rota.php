<?php
/**
 * Who serves where: assignments of volunteers to roles at services.
 *
 * @package Congregation
 */

namespace Congregation\Core;

/**
 * A rota. Assignments are [service id, role, volunteer id]; a volunteer id of 0 is an unfilled place.
 */
final class Rota {

	/**
	 * Assignments, in the order they were made.
	 *
	 * @var array<int, array{0: int, 1: string, 2: int}>
	 */
	public array $assignments = array();

	/**
	 * Constructor.
	 *
	 * @param array<int, Service>   $services   Services by id.
	 * @param array<int, Volunteer> $volunteers Volunteers by id.
	 */
	public function __construct( public readonly array $services, public readonly array $volunteers ) {}

	/**
	 * Add an assignment.
	 *
	 * @param int    $service   Service id.
	 * @param string $role      Role.
	 * @param int    $volunteer Volunteer id (0 = unfilled).
	 */
	public function add( int $service, string $role, int $volunteer ): void {
		$this->assignments[] = array( $service, $role, $volunteer );
	}

	/**
	 * Put a volunteer (0 for nobody) in an existing place.
	 *
	 * @param int $index     Assignment index.
	 * @param int $volunteer Volunteer id.
	 */
	public function set( int $index, int $volunteer ): void {
		[ $service, $role ]          = $this->assignments[ $index ];
		$this->assignments[ $index ] = array( $service, $role, $volunteer );
	}

	/**
	 * Is the volunteer already serving at this service (in any role)?
	 *
	 * @param int $volunteer Volunteer.
	 * @param int $service   Service.
	 */
	public function serving_at( int $volunteer, int $service ): bool {
		foreach ( $this->assignments as [ $s, , $v ] ) {
			if ( $v === $volunteer && $s === $service ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Times the volunteer serves in a month (Y-m).
	 *
	 * @param int    $volunteer Volunteer.
	 * @param string $month     Month.
	 */
	public function count_in_month( int $volunteer, string $month ): int {
		$n = 0;
		foreach ( $this->assignments as [ $s, , $v ] ) {
			$n += ( $v === $volunteer && $this->services[ $s ]->month() === $month ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Total times the volunteer serves.
	 *
	 * @param int $volunteer Volunteer.
	 */
	public function count( int $volunteer ): int {
		$n = 0;
		foreach ( $this->assignments as [ , , $v ] ) {
			$n += $v === $volunteer ? 1 : 0;
		}
		return $n;
	}

	/**
	 * Dates the volunteer serves on.
	 *
	 * @param int $volunteer Volunteer.
	 * @return list<string>
	 */
	public function dates( int $volunteer ): array {
		$out = array();
		foreach ( $this->assignments as [ $s, , $v ] ) {
			if ( $v === $volunteer ) {
				$out[] = $this->services[ $s ]->date;
			}
		}
		return $out;
	}

	/**
	 * Service times at which members of a household serve on a date.
	 *
	 * @param int    $household Household id.
	 * @param string $date      Date.
	 * @param int    $except    Volunteer to leave out.
	 * @return list<string>
	 */
	public function household_times( int $household, string $date, int $except ): array {
		$out = array();
		foreach ( $this->assignments as [ $s, , $v ] ) {
			if ( $v && $v !== $except && $this->volunteers[ $v ]->household === $household && $this->services[ $s ]->date === $date ) {
				$out[] = $this->services[ $s ]->time;
			}
		}
		return $out;
	}

	/**
	 * Assignments for a service.
	 *
	 * @param int $service Service id.
	 * @return list<array{0: int, 1: string, 2: int}>
	 */
	public function for_service( int $service ): array {
		return array_values( array_filter( $this->assignments, static fn ( array $a ): bool => $a[0] === $service ) );
	}
}
