<?php
/**
 * What a rota is like for the church and for the people on it.
 *
 * @package Congregation
 */

namespace Congregation\Evaluation;

use Congregation\Core\Rota;
use Congregation\Core\Scheduler;

/**
 * Metrics.
 */
final class Measure {

	/**
	 * Measure a rota, then simulate declines and replacements and measure the final fill.
	 *
	 * @param Rota          $rota     Rota.
	 * @param Church        $church   Church (for decline rates).
	 * @param callable|null $replace  Rota, index => new volunteer id (0 if none); null to leave declined places empty.
	 * @return array<string, float|int>
	 */
	public static function rota( Rota $rota, Church $church, ?callable $replace ): array {
		$places = count( $rota->assignments );
		$filled = count( array_filter( $rota->assignments, static fn ( array $a ): bool => $a[2] > 0 ) );
		$double = 0;
		$seen   = array();
		foreach ( $rota->assignments as [ $s, , $v ] ) {
			if ( $v ) {
				$key           = $s . ':' . $v;
				$double       += isset( $seen[ $key ] ) ? 1 : 0;
				$seen[ $key ]  = true;
			}
		}
		$over   = 0;
		$loads  = array();
		$unused = 0;
		$months = array_values( array_unique( array_map( static fn ( $s ): string => $s->month(), $rota->services ) ) );
		$consec = 0;
		$total  = 0;
		foreach ( $rota->volunteers as $v ) {
			foreach ( $months as $m ) {
				$over += max( 0, $rota->count_in_month( $v->id, $m ) - $v->per_month );
			}
			$n       = $rota->count( $v->id );
			$loads[] = $n / max( 1, $v->per_month * count( $months ) );
			$unused += 0 === $n ? 1 : 0;
			$dates   = array_values( array_unique( $rota->dates( $v->id ) ) );
			sort( $dates );
			for ( $i = 1; $i < count( $dates ); $i++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed
				$consec += 7 === (int) ( new \DateTimeImmutable( $dates[ $i - 1 ] ) )->diff( new \DateTimeImmutable( $dates[ $i ] ) )->format( '%a' ) ? 1 : 0;
			}
			$total += count( $dates );
		}
		$split = 0;
		$tog   = 0;
		foreach ( $rota->volunteers as $v ) {
			if ( ! $v->together || ! $v->household ) {
				continue;
			}
			foreach ( array_unique( $rota->dates( $v->id ) ) as $d ) {
				$times = $rota->household_times( $v->household, $d, $v->id );
				if ( $times ) {
					++$tog;
					$mine   = array();
					foreach ( $rota->assignments as [ $s, , $x ] ) {
						if ( $x === $v->id && $rota->services[ $s ]->date === $d ) {
							$mine[] = $rota->services[ $s ]->time;
						}
					}
					$split += array_intersect( $mine, $times ) ? 0 : 1;
				}
			}
		}
		$mean = array_sum( $loads ) / max( 1, count( $loads ) );
		$var  = 0.0;
		foreach ( $loads as $l ) {
			$var += ( $l - $mean ) ** 2;
		}
		// Declines: each person declines each request with their own probability (seeded per church).
		mt_srand( $church->seed * 7919 );
		$after = $filled;
		$lost  = 0;
		foreach ( $rota->assignments as $i => [ , , $v ] ) {
			if ( $v && mt_rand( 0, 999 ) / 1000 < $church->decline[ $v ] ) {
				$w = null === $replace ? 0 : (int) $replace( $rota, $i );
				if ( 0 === $w ) {
					--$after;
					++$lost;
					$rota->assignments[ $i ][2] = 0;
				}
			}
		}
		return array(
			'places'        => $places,
			'filled'        => $filled,
			'double_booked' => $double,
			'over_limit'    => $over,
			'load_spread'   => round( sqrt( $var / max( 1, count( $loads ) ) ), 4 ),
			'unused'        => $unused,
			'volunteers'    => count( $rota->volunteers ),
			'consecutive'   => $consec,
			'served_dates'  => $total,
			'household_split' => $split,
			'household_dates' => $tog,
			'filled_after_declines' => $after,
		);
	}

	/**
	 * Replacement for a decline by each method.
	 *
	 * @param string    $method Method.
	 * @param Scheduler $s      Scheduler (for the plugin).
	 */
	public static function replacer( string $method, Scheduler $s ): callable {
		if ( 'plugin' === $method ) {
			return static fn ( Rota $r, int $i ): int => $s->replace( $r, $i );
		}
		// Baselines: the next person on the list who keeps the hard rules (a careful coordinator by phone).
		return static function ( Rota $r, int $i ): int {
			[ $sid, $role, $was ]       = $r->assignments[ $i ];
			$r->assignments[ $i ][2] = 0;
			foreach ( $r->volunteers as $v ) {
				if ( $v->id !== $was && Scheduler::eligible( $r, $v, $r->services[ $sid ], $role ) ) {
					$r->assignments[ $i ][2] = $v->id;
					return $v->id;
				}
			}
			return 0;
		};
	}
}
