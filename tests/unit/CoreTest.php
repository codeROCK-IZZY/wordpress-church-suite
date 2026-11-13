<?php
/**
 * Rota scheduling, giving statements and answer tokens.
 *
 * @package Congregation
 */

namespace Congregation\Tests;

use Congregation\Core\Rota;
use Congregation\Core\Scheduler;
use Congregation\Core\Service;
use Congregation\Core\Statement;
use Congregation\Core\Token;
use Congregation\Core\Volunteer;
use PHPUnit\Framework\TestCase;

/**
 * Core tests.
 */
final class CoreTest extends TestCase {

	/**
	 * Four Sundays in June 2026, one service, needing one sound and two welcome.
	 *
	 * @param list<Volunteer> $people Volunteers.
	 * @param array<string, int> $needs Needs.
	 * @param list<string>    $times  Service times.
	 */
	private function rota( array $people, array $needs = array(
		'sound'   => 1,
		'welcome' => 2,
	), array $times = array( '10:30' ) ): Rota {
		$services = array();
		$id       = 0;
		foreach ( array( '2026-06-07', '2026-06-14', '2026-06-21', '2026-06-28' ) as $d ) {
			foreach ( $times as $t ) {
				$services[ ++$id ] = new Service( $id, $d, $t, $needs );
			}
		}
		$vols = array();
		foreach ( $people as $p ) {
			$vols[ $p->id ] = $p;
		}
		return new Rota( $services, $vols );
	}

	public function test_hard_rules_are_never_broken(): void {
		$people = array(
			new Volunteer( 1, 'Ann', array( 'sound', 'welcome' ), 2, array( '2026-06-14' ) ),
			new Volunteer( 2, 'Bo', array( 'sound' ), 2 ),
			new Volunteer( 3, 'Cy', array( 'welcome' ), 4 ),
			new Volunteer( 4, 'Di', array( 'welcome' ), 3 ),
			new Volunteer( 5, 'Ed', array( 'welcome' ), 1 ),
		);
		$rota   = ( new Scheduler() )->plan( $this->rota( $people ) );
		$seen   = array();
		foreach ( $rota->assignments as [ $s, $role, $v ] ) {
			if ( ! $v ) {
				continue;
			}
			$person = $rota->volunteers[ $v ];
			$this->assertTrue( $person->can( $role ), "{$person->name} is not trained for {$role}" );
			$this->assertTrue( $person->free_on( $rota->services[ $s ]->date ), "{$person->name} is away" );
			$this->assertArrayNotHasKey( "{$s}:{$v}", $seen, "{$person->name} has two roles at one service" );
			$seen[ "{$s}:{$v}" ] = true;
		}
		foreach ( $people as $p ) {
			$this->assertLessThanOrEqual( $p->per_month, $rota->count_in_month( $p->id, '2026-06' ), $p->name . ' over their limit' );
		}
		$this->assertCount( 12, $rota->assignments );
		$this->assertCount( 12, array_filter( $rota->assignments, static fn ( array $a ): bool => $a[2] > 0 ), 'everything can be filled here' );
	}

	public function test_load_is_shared_by_what_people_offered(): void {
		$people = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$people[] = new Volunteer( $i, 'W' . $i, array( 'welcome' ), 4 );
		}
		$rota  = ( new Scheduler() )->plan( $this->rota( $people, array( 'welcome' => 3 ) ) );
		$loads = array_map( static fn ( Volunteer $v ): int => $rota->count( $v->id ), $people );
		$this->assertSame( 12, array_sum( $loads ) );
		$this->assertSame( array( 2, 2, 2, 2, 2, 2 ), $loads, 'twelve places over six people: two each' );
	}

	public function test_scarce_roles_are_filled_first(): void {
		// Ann is the only sound person; if welcome went first she might be used up there.
		$people = array(
			new Volunteer( 1, 'Ann', array( 'sound', 'welcome' ), 4 ),
			new Volunteer( 2, 'Bo', array( 'welcome' ), 4 ),
			new Volunteer( 3, 'Cy', array( 'welcome' ), 4 ),
		);
		$rota   = ( new Scheduler() )->plan( $this->rota( $people ) );
		foreach ( $rota->assignments as [ , $role, $v ] ) {
			if ( 'sound' === $role ) {
				$this->assertSame( 1, $v );
			}
		}
	}

	public function test_repair_moves_someone_to_fill_an_empty_place(): void {
		// Cy does sound and welcome, once a month. Greedy puts Cy on the first place (welcome, 7 June) and then cannot
		// fill sound on 14 June (Fy is trained but offers no Sundays). Repair hands welcome to Ed and moves Cy to sound.
		$people = array(
			1 => new Volunteer( 1, 'Cy', array( 'sound', 'welcome' ), 1 ),
			2 => new Volunteer( 2, 'Ed', array( 'welcome' ), 1 ),
			3 => new Volunteer( 3, 'Fy', array( 'sound' ), 0 ),
		);
		$s      = array(
			1 => new Service( 1, '2026-06-07', '10:30', array( 'welcome' => 1 ) ),
			2 => new Service( 2, '2026-06-14', '10:30', array( 'sound' => 1 ) ),
		);
		$plan   = ( new Scheduler() )->plan( new Rota( $s, $people ) );
		$this->assertSame( array( 1, 'welcome', 2 ), $plan->assignments[0] );
		$this->assertSame( array( 2, 'sound', 1 ), $plan->assignments[1] );
		$none = ( new Scheduler( 1.0, 1.0, 0.3, 0.6, false ) )->plan( new Rota( $s, $people ) );
		$this->assertSame( 0, $none->assignments[1][2], 'without repair, sound stays empty' );
	}

	public function test_places_that_already_exist_are_never_moved(): void {
		$people = array(
			1 => new Volunteer( 1, 'Cy', array( 'sound', 'welcome' ), 1 ),
			2 => new Volunteer( 2, 'Ed', array( 'welcome' ), 1 ),
		);
		$s      = array(
			1 => new Service( 1, '2026-06-07', '10:30', array( 'welcome' => 1 ) ),
			2 => new Service( 2, '2026-06-14', '10:30', array( 'sound' => 1 ) ),
		);
		$r      = new Rota( $s, $people );
		$r->add( 1, 'welcome', 1 ); // Cy has already confirmed welcome on 7 June.
		$plan = ( new Scheduler() )->plan( $r );
		$this->assertSame( array( 1, 'welcome', 1 ), $plan->assignments[0], 'the confirmed place is kept' );
		$this->assertSame( array( 2, 'sound', 0 ), $plan->assignments[1], 'so sound is left for a person to sort out' );
	}

	public function test_spacing_household_and_preference(): void {
		// Six people, eight places (one welcome at 09:00 and 11:00 on four Sundays): room for the soft wishes.
		$people = array(
			new Volunteer( 1, 'Ann', array( 'welcome' ), 2, array(), 7, true ),
			new Volunteer( 2, 'Al', array( 'welcome' ), 2, array(), 7, true ),
			new Volunteer( 3, 'Bo', array( 'welcome' ), 2, array(), 0, false, '09:00' ),
			new Volunteer( 4, 'Cy', array( 'welcome' ), 2, array(), 0, false, '11:00' ),
			new Volunteer( 5, 'Di', array( 'welcome' ), 2 ),
			new Volunteer( 6, 'Ed', array( 'welcome' ), 2 ),
		);
		$rota   = ( new Scheduler() )->plan( $this->rota( $people, array( 'welcome' => 1 ), array( '09:00', '11:00' ) ) );
		$times  = static function ( Rota $r, int $v ): array {
			$out = array();
			foreach ( $r->assignments as [ $s, , $x ] ) {
				if ( $x === $v ) {
					$out[ $r->services[ $s ]->date ] = $r->services[ $s ]->time;
				}
			}
			return $out;
		};
		foreach ( $times( $rota, 1 ) as $date => $t ) {
			$al = $times( $rota, 2 )[ $date ] ?? null;
			$this->assertTrue( null === $al || $al === $t, 'Ann and Al serve at the same service when they serve the same day' );
		}
		$this->assertNotContains( '11:00', array_values( $times( $rota, 3 ) ), 'Bo prefers 09:00' );
		$this->assertNotContains( '09:00', array_values( $times( $rota, 4 ) ), 'Cy prefers 11:00' );
		$back_to_back = 0;
		foreach ( $people as $p ) {
			$dates = array_keys( $times( $rota, $p->id ) );
			sort( $dates );
			$n_dates = count( $dates );
			for ( $i = 1; $i < $n_dates; $i++ ) {
				$back_to_back += 7 === ( new \DateTimeImmutable( $dates[ $i - 1 ] ) )->diff( new \DateTimeImmutable( $dates[ $i ] ) )->days ? 1 : 0;
			}
		}
		$this->assertSame( 0, $back_to_back, 'with six people for eight places, nobody serves two Sundays in a row' );
	}

	public function test_replacement_after_a_decline(): void {
		$people = array(
			new Volunteer( 1, 'Ann', array( 'sound' ), 4 ),
			new Volunteer( 2, 'Bo', array( 'sound' ), 4 ),
		);
		$rota   = ( new Scheduler() )->plan( $this->rota( $people, array( 'sound' => 1 ) ) );
		$first  = $rota->assignments[0][2];
		$s      = new Scheduler();
		$this->assertSame( 1 === $first ? 2 : 1, $s->replace( $rota, 0 ), 'the other person' );
		$solo = ( new Scheduler() )->plan( $this->rota( array( $people[0] ), array( 'sound' => 1 ) ) );
		$this->assertSame( 0, $s->replace( $solo, 0 ), 'nobody else: left empty' );
	}

	public function test_statement_totals_skip_refunds_and_other_years(): void {
		$s = new Statement(
			2026,
			array(
				array(
					'id'           => 3,
					'given_on'     => '2026-03-01',
					'fund'         => 'General',
					'method'       => 'card',
					'amount_cents' => 2550,
					'refunded'     => 0,
				),
				array(
					'id'           => 1,
					'given_on'     => '2026-01-04',
					'fund'         => 'Building',
					'method'       => 'bank',
					'amount_cents' => 10000,
					'refunded'     => 0,
				),
				array(
					'id'           => 2,
					'given_on'     => '2026-02-01',
					'fund'         => 'General',
					'method'       => 'card',
					'amount_cents' => 5000,
					'refunded'     => 1,
				),
				array(
					'id'           => 4,
					'given_on'     => '2025-12-28',
					'fund'         => 'General',
					'method'       => 'cash',
					'amount_cents' => 999,
					'refunded'     => 0,
				),
			)
		);
		$this->assertSame( 12550, $s->total );
		$this->assertSame(
			array(
				'Building' => 10000,
				'General'  => 2550,
			),
			$s->by_fund
		);
		$this->assertSame( array( '2026-01-04', '2026-02-01', '2026-03-01' ), array_column( $s->lines, 'date' ) );
		$this->assertTrue( $s->lines[1]['refunded'] );
	}

	public function test_money_parsing_and_formatting(): void {
		$this->assertSame( 123450, Statement::cents( '1,234.5' ) );
		$this->assertSame( 2000, Statement::cents( '$20' ) );
		$this->assertSame( 1, Statement::cents( '0.01' ) );
		$this->assertNull( Statement::cents( '12.345' ) );
		$this->assertNull( Statement::cents( 'ten' ) );
		$this->assertSame( '1,234.50', Statement::money( 123450 ) );
		$this->assertSame( '0.05', Statement::money( 5 ) );
	}

	public function test_tokens_follow_the_person_holding_the_place(): void {
		$t     = new Token( 'secret' );
		$token = $t->issue( 9, 4 );
		$this->assertSame( 9, $t->verify( $token, static fn (): int => 4 ) );
		$this->assertNull( $t->verify( $token, static fn (): int => 5 ), 'the place was given to someone else' );
		$this->assertNull( $t->verify( $token, static fn (): ?int => null ), 'no such place' );
		$this->assertNull( ( new Token( 'other' ) )->verify( $token, static fn (): int => 4 ) );
	}
}
