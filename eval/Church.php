<?php
/**
 * Simulated churches: volunteers, teams and a quarter of Sunday services.
 *
 * Volunteers can do one to three roles, offer one to four Sundays a month, are away for a holiday or two, may prefer
 * one service time, and some live in households that want to serve together. Some roles are scarce (one or two
 * trained people), as in real churches. After the rota is published, some people decline and must be replaced.
 *
 * @package Congregation
 */

namespace Congregation\Evaluation;

use Congregation\Core\Rota;
use Congregation\Core\Service;
use Congregation\Core\Volunteer;

/**
 * Generator.
 */
final class Church {

	private const ROLES = array(
		'worship-leader' => array( 1, 0.06 ),
		'vocals'         => array( 2, 0.18 ),
		'band'           => array( 3, 0.16 ),
		'sound'          => array( 1, 0.06 ),
		'slides'         => array( 1, 0.10 ),
		'kids-leader'    => array( 1, 0.08 ),
		'kids-helper'    => array( 2, 0.22 ),
		'welcome'        => array( 3, 0.35 ),
		'coffee'         => array( 2, 0.30 ),
	);

	/**
	 * Services by id.
	 *
	 * @var array<int, Service>
	 */
	public array $services = array();

	/**
	 * Volunteers by id.
	 *
	 * @var array<int, Volunteer>
	 */
	public array $volunteers = array();

	/**
	 * How likely each volunteer is to decline a request (0-1).
	 *
	 * @var array<int, float>
	 */
	public array $decline = array();

	/**
	 * Constructor.
	 *
	 * @param int  $seed      Seed.
	 * @param bool $different The held-back shape: a small church (scarce people, one service) or a large one with three.
	 */
	public function __construct( public readonly int $seed, bool $different = false ) {
		mt_srand( $seed );
		$times = $different ? ( 0 === $seed % 2 ? array( '10:30' ) : array( '08:30', '10:00', '11:30' ) ) : ( mt_rand( 0, 1 ) ? array( '10:30' ) : array( '09:00', '11:00' ) );
		$size  = $different ? ( 1 === count( $times ) ? mt_rand( 18, 26 ) : mt_rand( 140, 200 ) ) : mt_rand( 35, 110 );
		$start = new \DateTimeImmutable( array( '2026-01-04', '2026-04-05', '2026-09-06' )[ mt_rand( 0, 2 ) ] );
		$id    = 0;
		for ( $w = 0; $w < 13; $w++ ) {
			$date = $start->modify( '+' . ( 7 * $w ) . ' days' )->format( 'Y-m-d' );
			foreach ( $times as $t ) {
				$needs = array();
				foreach ( self::ROLES as $role => [ $n ] ) {
					$needs[ $role ] = $n;
				}
				$this->services[ ++$id ] = new Service( $id, $date, $t, $needs );
			}
		}
		$dates     = array_values( array_unique( array_map( static fn ( Service $s ): string => $s->date, $this->services ) ) );
		$household = 0;
		$hh_left   = 0;
		$hh_id     = 0;
		$hh_tog    = false;
		for ( $i = 1; $i <= $size; $i++ ) {
			$roles = array();
			foreach ( self::ROLES as $role => [ , $p ] ) {
				if ( mt_rand( 0, 999 ) / 1000 < $p ) {
					$roles[] = $role;
				}
			}
			if ( ! $roles ) {
				$roles[] = mt_rand( 0, 1 ) ? 'welcome' : 'coffee';
			}
			$roles = array_slice( $roles, 0, 3 );
			$away  = array();
			for ( $h = 0; $h < mt_rand( 0, 2 ); $h++ ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed -- Simulation: one to two holidays.
				$from = mt_rand( 0, count( $dates ) - 1 );
				$away = array_merge( $away, array_slice( $dates, $from, mt_rand( 1, 3 ) ) );
			}
			if ( 0 === $hh_left && mt_rand( 0, 99 ) < 30 ) {
				$hh_left = mt_rand( 2, 3 );
				$hh_id   = ++$household;
				$hh_tog  = mt_rand( 0, 1 ) === 1;
			}
			$hh = 0;
			if ( $hh_left > 0 ) {
				$hh = $hh_id;
				--$hh_left;
			}
			$this->volunteers[ $i ] = new Volunteer(
				$i,
				'Volunteer ' . $i,
				$roles,
				array( 1, 1, 2, 2, 2, 3, 4 )[ mt_rand( 0, 6 ) ],
				array_values( array_unique( $away ) ),
				$hh,
				$hh > 0 && $hh_tog,
				count( $times ) > 1 && mt_rand( 0, 2 ) === 0 ? $times[ mt_rand( 0, count( $times ) - 1 ) ] : ''
			);
			$this->decline[ $i ]    = array( 0.05, 0.1, 0.1, 0.2, 0.35 )[ mt_rand( 0, 4 ) ];
		}
	}

	/**
	 * An empty rota for this church.
	 */
	public function rota(): Rota {
		return new Rota( $this->services, $this->volunteers );
	}
}
