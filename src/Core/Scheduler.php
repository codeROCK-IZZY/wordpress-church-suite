<?php
/**
 * Building a rota that fills every place without overloading anyone.
 *
 * Hard rules (never broken): a person only does roles they are trained for, never on dates they are away, never two
 * roles at the same service, and never more often in a month than they offered.
 *
 * Within those rules, places are filled scarcest first (the role with the fewest people who could do it on that
 * date), each going to the person with the lowest score:
 *   share of their offered capacity already used
 *   + spacing:    if they serve the week before or after
 *   + household:  if they want to serve with their household and it is at another service that day
 *   + preference: if it is not their preferred service time
 *   + same day:   if they already serve at another service that day
 * Then a repair pass fills empty places by moving people: a person who could fill an empty place but is at their monthly
 * limit, or is already in another role at that service, hands that other place to someone else who can take it.
 *
 * @package Congregation
 */

namespace Congregation\Core;

/**
 * Scheduler.
 */
final class Scheduler {

	/**
	 * Constructor. Weights were chosen on simulated tuning churches (eval/).
	 *
	 * @param float $spacing    Penalty for serving in consecutive weeks.
	 * @param float $household  Penalty for splitting a household that wants to serve together.
	 * @param float $preference Penalty for a service time they did not prefer.
	 * @param float $same_day   Penalty for serving at two services on one day.
	 * @param bool  $repair     Run the repair pass.
	 */
	public function __construct(
		public readonly float $spacing = 1.0,
		public readonly float $household = 1.0,
		public readonly float $preference = 0.3,
		public readonly float $same_day = 0.6,
		public readonly bool $repair = true
	) {}

	/**
	 * Could this person take this place, by the hard rules?
	 *
	 * @param Rota      $rota    Rota so far.
	 * @param Volunteer $v       Volunteer.
	 * @param Service   $service Service.
	 * @param string    $role    Role.
	 */
	public static function eligible( Rota $rota, Volunteer $v, Service $service, string $role ): bool {
		return $v->can( $role ) && $v->free_on( $service->date ) && ! $rota->serving_at( $v->id, $service->id )
			&& $rota->count_in_month( $v->id, $service->month() ) < $v->per_month;
	}

	/**
	 * Score a candidate (lower is better).
	 *
	 * @param Rota      $rota    Rota so far.
	 * @param Volunteer $v       Volunteer.
	 * @param Service   $service Service.
	 * @param int       $months  Months the rota covers.
	 */
	public function score( Rota $rota, Volunteer $v, Service $service, int $months ): float {
		$s    = $rota->count( $v->id ) / max( 1, $v->per_month * $months );
		$date = new \DateTimeImmutable( $service->date );
		foreach ( $rota->dates( $v->id ) as $d ) {
			$gap = abs( (int) $date->diff( new \DateTimeImmutable( $d ) )->format( '%r%a' ) );
			if ( $gap > 0 && $gap <= 7 ) {
				$s += $this->spacing;
			} elseif ( 0 === $gap ) {
				$s += $this->same_day;
			}
		}
		if ( $v->together && $v->household ) {
			$times = $rota->household_times( $v->household, $service->date, $v->id );
			if ( $times && ! in_array( $service->time, $times, true ) ) {
				$s += $this->household;
			} elseif ( $times ) {
				$s -= $this->household / 2;
			}
		}
		if ( '' !== $v->prefers && $v->prefers !== $service->time ) {
			$s += $this->preference;
		}
		return $s;
	}

	/**
	 * Build a rota (keeping any assignments already in $rota).
	 *
	 * @param Rota $rota Rota with services and volunteers, possibly partly filled.
	 */
	public function plan( Rota $rota ): Rota {
		$months = count( array_unique( array_map( static fn ( Service $s ): string => $s->month(), $rota->services ) ) );
		$slots  = array();
		foreach ( $rota->services as $service ) {
			$have = array_count_values( array_map( static fn ( array $a ): string => $a[1], array_filter( $rota->for_service( $service->id ), static fn ( array $a ): bool => $a[2] > 0 ) ) );
			foreach ( $service->needs as $role => $n ) {
				for ( $i = $have[ $role ] ?? 0; $i < $n; $i++ ) {
					$pool    = count( array_filter( $rota->volunteers, static fn ( Volunteer $v ): bool => $v->can( $role ) && $v->free_on( $service->date ) ) );
					$slots[] = array( $pool, $service->date, $service->time, $service->id, $role );
				}
			}
		}
		sort( $slots );
		$empty = array();
		foreach ( $slots as [ , , , $sid, $role ] ) {
			$best = $this->best( $rota, $rota->services[ $sid ], $role, $months );
			$rota->add( $sid, $role, $best );
			if ( 0 === $best ) {
				$empty[] = count( $rota->assignments ) - 1;
			}
		}
		if ( $this->repair ) {
			foreach ( $empty as $i ) {
				$this->repair_slot( $rota, $i, $months );
			}
		}
		return $rota;
	}

	/**
	 * The best eligible person for a place, or 0.
	 *
	 * @param Rota    $rota    Rota.
	 * @param Service $service Service.
	 * @param string  $role    Role.
	 * @param int     $months  Months.
	 * @param int     $skip    A volunteer to leave out.
	 */
	public function best( Rota $rota, Service $service, string $role, int $months, int $skip = 0 ): int {
		$best  = 0;
		$score = INF;
		foreach ( $rota->volunteers as $v ) {
			if ( $v->id === $skip || ! self::eligible( $rota, $v, $service, $role ) ) {
				continue;
			}
			$sc = $this->score( $rota, $v, $service, $months );
			if ( $sc < $score ) {
				$score = $sc;
				$best  = $v->id;
			}
		}
		return $best;
	}

	/**
	 * Fill an empty place by moving someone from another place that another person can take.
	 *
	 * @param Rota $rota   Rota.
	 * @param int  $index  Index of the empty assignment.
	 * @param int  $months Months.
	 */
	private function repair_slot( Rota $rota, int $index, int $months ): void {
		[ $sid, $role ] = $rota->assignments[ $index ];
		$service        = $rota->services[ $sid ];
		foreach ( $rota->volunteers as $v ) {
			if ( ! $v->can( $role ) || ! $v->free_on( $service->date ) ) {
				continue;
			}
			// Places this person holds that block them: same service (another role) or same month (at the limit).
			$blocking = array();
			foreach ( $rota->assignments as $j => [ $s2, , $v2 ] ) {
				if ( $v2 !== $v->id ) {
					continue;
				}
				if ( $s2 === $sid || $rota->count_in_month( $v->id, $service->month() ) >= $v->per_month && $rota->services[ $s2 ]->month() === $service->month() ) {
					$blocking[] = $j;
				}
			}
			foreach ( $blocking as $j ) {
				[ $s2, $r2 ]                 = $rota->assignments[ $j ];
				$rota->assignments[ $j ][2] = 0; // Free that place, then see whether both can be filled.
				if ( self::eligible( $rota, $v, $service, $role ) ) {
					$w = $this->best( $rota, $rota->services[ $s2 ], $r2, $months, $v->id );
					if ( $w ) {
						$rota->assignments[ $j ][2]     = $w;
						$rota->assignments[ $index ][2] = $v->id;
						return;
					}
				}
				$rota->assignments[ $j ][2] = $v->id;
			}
		}
	}

	/**
	 * Replace someone who declined: the best other eligible person, or 0.
	 *
	 * @param Rota $rota  Rota.
	 * @param int  $index Assignment index.
	 */
	public function replace( Rota $rota, int $index ): int {
		[ $sid, $role, $was ]           = $rota->assignments[ $index ];
		$rota->assignments[ $index ][2] = 0;
		$months                         = count( array_unique( array_map( static fn ( Service $s ): string => $s->month(), $rota->services ) ) );
		$w                              = $this->best( $rota, $rota->services[ $sid ], $role, $months, $was );
		$rota->assignments[ $index ][2] = $w;
		return $w;
	}
}
