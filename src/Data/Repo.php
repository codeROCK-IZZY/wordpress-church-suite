<?php
/**
 * Reading and writing people, services, assignments and gifts.
 *
 * @package Congregation
 */

namespace Congregation\Data;

use Congregation\Core\Rota;
use Congregation\Core\Service;
use Congregation\Core\Volunteer;
use Congregation\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom tables; rota and giving data change constantly, nothing is cached.

/**
 * Repository.
 */
final class Repo {

	/**
	 * All people.
	 *
	 * @return list<array<string, string>>
	 */
	public static function people(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY name', Schema::t( 'people' ) ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * One person.
	 *
	 * @param int $id Id.
	 * @return array<string, string>|null
	 */
	public static function person( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', Schema::t( 'people' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Insert or update a person.
	 *
	 * @param array<string, mixed> $p  Fields: name, email, phone, household, roles (list), per_month, together, prefers, away (list).
	 * @param int                  $id Id to update, 0 to insert.
	 */
	public static function save_person( array $p, int $id = 0 ): int {
		global $wpdb;
		$data = array(
			'name'      => (string) $p['name'],
			'email'     => (string) ( $p['email'] ?? '' ),
			'phone'     => (string) ( $p['phone'] ?? '' ),
			'household' => (int) ( $p['household'] ?? 0 ),
			'roles'     => implode( ',', array_map( 'strval', (array) ( $p['roles'] ?? array() ) ) ),
			'per_month' => max( 0, min( 5, (int) ( $p['per_month'] ?? 2 ) ) ),
			'together'  => empty( $p['together'] ) ? 0 : 1,
			'prefers'   => (string) ( $p['prefers'] ?? '' ),
			'away'      => implode( ',', array_map( 'strval', (array) ( $p['away'] ?? array() ) ) ),
		);
		if ( $id ) {
			$wpdb->update( Schema::t( 'people' ), $data, array( 'id' => $id ) );
			return $id;
		}
		$wpdb->insert( Schema::t( 'people' ), $data + array( 'created_at' => gmdate( 'Y-m-d H:i:s' ) ) );
		return (int) $wpdb->insert_id;
	}

	/**
	 * A person as a volunteer for the scheduler.
	 *
	 * @param array<string, string> $r Row.
	 */
	public static function volunteer( array $r ): Volunteer {
		$split = static fn ( string $s ): array => array_values( array_filter( array_map( 'trim', explode( ',', $s ) ) ) );
		return new Volunteer( (int) $r['id'], $r['name'], $split( $r['roles'] ), (int) $r['per_month'], $split( $r['away'] ), (int) $r['household'], (bool) $r['together'], $r['prefers'] );
	}

	/**
	 * Make sure services exist for each service time on every date in a range, and return them.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array<int, Service>
	 */
	public static function services( string $from, string $to ): array {
		global $wpdb;
		$times = Settings::times();
		for ( $d = new \DateTimeImmutable( $from ); $d->format( 'Y-m-d' ) <= $to; $d = $d->modify( '+1 day' ) ) { // phpcs:ignore Generic.CodeAnalysis.ForLoopWithTestFunctionCall.NotAllowed -- Walking dates.
			foreach ( $times as [ $weekday, $time ] ) {
				if ( (int) $d->format( 'N' ) === $weekday ) {
					$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (date, time) VALUES (%s, %s)', Schema::t( 'services' ), $d->format( 'Y-m-d' ), $time ) );
				}
			}
		}
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE date BETWEEN %s AND %s ORDER BY date, time', Schema::t( 'services' ), $from, $to ), ARRAY_A );
		$needs = Settings::needs();
		$out   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $r ) {
			$out[ (int) $r['id'] ] = new Service( (int) $r['id'], (string) $r['date'], (string) $r['time'], $needs );
		}
		return $out;
	}

	/**
	 * The rota for a set of services, with everyone, from the database.
	 *
	 * @param array<int, Service> $services Services.
	 */
	public static function rota( array $services ): Rota {
		global $wpdb;
		$vols = array();
		foreach ( self::people() as $p ) {
			$vols[ (int) $p['id'] ] = self::volunteer( $p );
		}
		$rota = new Rota( $services, $vols );
		if ( $services ) {
			$ids  = implode( ',', array_map( 'intval', array_keys( $services ) ) );
			$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}congregation_assignments WHERE service_id IN ({$ids}) AND status != 'declined' ORDER BY id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $ids are integers.
			foreach ( is_array( $rows ) ? $rows : array() as $r ) {
				if ( (int) $r['person_id'] && isset( $vols[ (int) $r['person_id'] ] ) ) {
					$rota->add( (int) $r['service_id'], (string) $r['role'], (int) $r['person_id'] );
				}
			}
		}
		return $rota;
	}

	/**
	 * Store planned places from a rota (only new ones; existing assignments are untouched).
	 *
	 * @param Rota $rota   Rota after planning.
	 * @param int  $before How many assignments it held before planning.
	 * @return int Places planned.
	 */
	public static function store_plan( Rota $rota, int $before ): int {
		global $wpdb;
		$n = 0;
		foreach ( array_slice( $rota->assignments, $before ) as [ $sid, $role, $pid ] ) {
			$wpdb->insert(
				Schema::t( 'assignments' ),
				array(
					'service_id' => $sid,
					'role'       => $role,
					'person_id'  => $pid,
					'status'     => $pid ? 'planned' : 'unfilled',
				)
			);
			++$n;
		}
		return $n;
	}

	/**
	 * Assignments with their service, for a date range.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 * @return list<array<string, string>>
	 */
	public static function assignments( string $from, string $to ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT a.*, s.date, s.time, p.name, p.email FROM %i a JOIN %i s ON s.id = a.service_id LEFT JOIN %i p ON p.id = a.person_id WHERE s.date BETWEEN %s AND %s ORDER BY s.date, s.time, a.role, a.id', Schema::t( 'assignments' ), Schema::t( 'services' ), Schema::t( 'people' ), $from, $to ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * One assignment.
	 *
	 * @param int $id Id.
	 * @return array<string, string>|null
	 */
	public static function assignment( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT a.*, s.date, s.time, p.name, p.email FROM %i a JOIN %i s ON s.id = a.service_id LEFT JOIN %i p ON p.id = a.person_id WHERE a.id = %d', Schema::t( 'assignments' ), Schema::t( 'services' ), Schema::t( 'people' ), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Update an assignment.
	 *
	 * @param int                  $id   Id.
	 * @param array<string, mixed> $data Columns.
	 */
	public static function update_assignment( int $id, array $data ): void {
		global $wpdb;
		$wpdb->update( Schema::t( 'assignments' ), $data, array( 'id' => $id ) );
	}

	/**
	 * Record a gift.
	 *
	 * @param int    $person Person id.
	 * @param string $date   Y-m-d.
	 * @param int    $cents  Amount in cents.
	 * @param string $fund   Fund.
	 * @param string $method Method.
	 * @param string $note   Note.
	 */
	public static function add_gift( int $person, string $date, int $cents, string $fund, string $method, string $note = '' ): int {
		global $wpdb;
		$wpdb->insert(
			Schema::t( 'gifts' ),
			array(
				'person_id'    => $person,
				'given_on'     => $date,
				'amount_cents' => $cents,
				'fund'         => $fund,
				'method'       => $method,
				'note'         => $note,
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a gift refunded (it stays on record but leaves statements).
	 *
	 * @param int $id Gift id.
	 */
	public static function refund_gift( int $id ): void {
		global $wpdb;
		$wpdb->update( Schema::t( 'gifts' ), array( 'refunded' => 1 ), array( 'id' => $id ) );
	}

	/**
	 * Gifts for a person (optionally one year), oldest first.
	 *
	 * @param int $person Person id.
	 * @param int $year   Year, or 0 for all.
	 * @return list<array<string, string>>
	 */
	public static function gifts( int $person, int $year = 0 ): array {
		global $wpdb;
		$rows = $year
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE person_id = %d AND given_on BETWEEN %s AND %s ORDER BY given_on, id', Schema::t( 'gifts' ), $person, $year . '-01-01', $year . '-12-31' ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE person_id = %d ORDER BY given_on, id', Schema::t( 'gifts' ), $person ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Recent gifts with names.
	 *
	 * @param int $limit Rows.
	 * @return list<array<string, string>>
	 */
	public static function recent_gifts( int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT g.*, p.name FROM %i g LEFT JOIN %i p ON p.id = g.person_id ORDER BY g.given_on DESC, g.id DESC LIMIT %d', Schema::t( 'gifts' ), Schema::t( 'people' ), $limit ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * People with this email.
	 *
	 * @param string $email Email.
	 * @return list<array<string, string>>
	 */
	public static function by_email( string $email ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE email = %s', Schema::t( 'people' ), $email ), ARRAY_A );
		return is_array( $rows ) ? array_values( $rows ) : array();
	}

	/**
	 * Remove a person's contact details, keeping their id on gifts (financial records) and past rotas. Their places
	 * from today on are released, so the rota shows them as empty and they can be planned again.
	 *
	 * @param int $id Person id.
	 */
	public static function anonymise( int $id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE a FROM %i a JOIN %i s ON s.id = a.service_id WHERE a.person_id = %d AND s.date >= %s', Schema::t( 'assignments' ), Schema::t( 'services' ), $id, gmdate( 'Y-m-d' ) ) );
		$wpdb->update(
			Schema::t( 'people' ),
			array(
				'name'  => 'Former member #' . $id,
				'email' => '',
				'phone' => '',
				'roles' => '',
				'away'  => '',
			),
			array( 'id' => $id )
		);
	}
}
