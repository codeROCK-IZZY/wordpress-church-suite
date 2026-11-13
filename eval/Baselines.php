<?php
/**
 * How rotas are usually made, for comparison.
 *
 * - Team spreadsheets: each team leader rotates through their own list, skipping people who said they are away.
 *   They cannot see other teams, so a person can be put on two teams at the same service, and each leader only keeps
 *   to the person's monthly limit within their own team.
 * - First available: one coordinator who sees everything and keeps every hard rule, but fills Sundays in order and
 *   gives each place to the first person on the list who can take it.
 *
 * @package Congregation
 */

namespace Congregation\Evaluation;

use Congregation\Core\Rota;
use Congregation\Core\Scheduler;
use Congregation\Core\Service;

/**
 * Baseline rota builders.
 */
final class Baselines {

	/**
	 * Team spreadsheets.
	 *
	 * @param Rota $rota Empty rota.
	 */
	public static function teams( Rota $rota ): Rota {
		$next = array();
		foreach ( $rota->services as $service ) {
			foreach ( $service->needs as $role => $n ) {
				$list = array_values( array_filter( $rota->volunteers, static fn ( $v ): bool => $v->can( $role ) ) );
				for ( $i = 0; $i < $n; $i++ ) {
					$pick = 0;
					for ( $k = 0; $k < count( $list ); $k++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed
						$idx = ( ( $next[ $role ] ?? 0 ) + $k ) % max( 1, count( $list ) );
						$v   = $list[ $idx ];
						if ( $v->free_on( $service->date ) && ! self::team_has( $rota, $service, $role, $v->id ) && self::team_month( $rota, $role, $v->id, $service ) < $v->per_month ) {
							$pick          = $v->id;
							$next[ $role ] = $idx + 1;
							break;
						}
					}
					$rota->add( $service->id, $role, $pick );
				}
			}
		}
		return $rota;
	}

	/**
	 * Already in this role at this service?
	 *
	 * @param Rota    $rota    Rota.
	 * @param Service $service Service.
	 * @param string  $role    Role.
	 * @param int     $v       Volunteer.
	 */
	private static function team_has( Rota $rota, Service $service, string $role, int $v ): bool {
		foreach ( $rota->for_service( $service->id ) as [ , $r, $x ] ) {
			if ( $r === $role && $x === $v ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Times in this team this month.
	 *
	 * @param Rota    $rota    Rota.
	 * @param string  $role    Role.
	 * @param int     $v       Volunteer.
	 * @param Service $service Service.
	 */
	private static function team_month( Rota $rota, string $role, int $v, Service $service ): int {
		$n = 0;
		foreach ( $rota->assignments as [ $s, $r, $x ] ) {
			$n += ( $x === $v && $r === $role && $rota->services[ $s ]->month() === $service->month() ) ? 1 : 0;
		}
		return $n;
	}

	/**
	 * First available.
	 *
	 * @param Rota $rota Empty rota.
	 */
	public static function first( Rota $rota ): Rota {
		foreach ( $rota->services as $service ) {
			foreach ( $service->needs as $role => $n ) {
				for ( $i = 0; $i < $n; $i++ ) {
					$pick = 0;
					foreach ( $rota->volunteers as $v ) {
						if ( Scheduler::eligible( $rota, $v, $service, $role ) ) {
							$pick = $v->id;
							break;
						}
					}
					$rota->add( $service->id, $role, $pick );
				}
			}
		}
		return $rota;
	}
}
