<?php
/**
 * Planning rotas, asking people, and handling their answers.
 *
 *   plan ─► ask (email with Accept / Decline links) ─► accept: confirmed
 *                                                    └► decline: the best other person who can is asked instead
 *
 * @package Congregation
 */

namespace Congregation;

use Congregation\Core\Scheduler;
use Congregation\Data\Repo;

/**
 * Rota workflow.
 */
final class RotaService {

	/**
	 * Plan every unfilled place in a date range.
	 *
	 * @param string $from Y-m-d.
	 * @param string $to   Y-m-d.
	 * @return array{planned: int, unfilled: int}
	 */
	public static function plan( string $from, string $to ): array {
		$rota   = Repo::rota( Repo::services( $from, $to ) );
		$before = count( $rota->assignments );
		( new Scheduler() )->plan( $rota );
		Repo::store_plan( $rota, $before );
		$new = array_slice( $rota->assignments, $before );
		return array(
			'planned'  => count( array_filter( $new, static fn ( array $a ): bool => $a[2] > 0 ) ),
			'unfilled' => count( array_filter( $new, static fn ( array $a ): bool => 0 === $a[2] ) ),
		);
	}

	/**
	 * Email everyone with a planned place in the range, once.
	 *
	 * @param string $from From.
	 * @param string $to   To.
	 */
	public static function ask( string $from, string $to ): int {
		$n = 0;
		foreach ( Repo::assignments( $from, $to ) as $a ) {
			if ( 'planned' === $a['status'] && (int) $a['person_id'] && '' !== (string) $a['email'] ) {
				self::send_request( $a );
				Repo::update_assignment(
					(int) $a['id'],
					array(
						'status'   => 'asked',
						'asked_at' => gmdate( 'Y-m-d H:i:s' ),
					)
				);
				++$n;
			}
		}
		return $n;
	}

	/**
	 * Link to answer a request.
	 *
	 * @param array<string, string> $a      Assignment row.
	 * @param string                $answer accept or decline.
	 */
	public static function link( array $a, string $answer ): string {
		return add_query_arg(
			array(
				'action' => 'congregation_answer',
				'answer' => $answer,
				't'      => Settings::tokens()->issue( (int) $a['id'], (int) $a['person_id'] ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	/**
	 * Role name for people.
	 *
	 * @param string $role Role id.
	 */
	public static function role_label( string $role ): string {
		return ucwords( str_replace( '-', ' ', $role ) );
	}

	/**
	 * When, in words.
	 *
	 * @param array<string, string> $a Assignment row.
	 */
	public static function when( array $a ): string {
		$t = strtotime( $a['date'] . ' ' . $a['time'] );
		return (string) wp_date( 'l j F', (int) $t, new \DateTimeZone( 'UTC' ) ) . ' at ' . $a['time'];
	}

	/**
	 * Send a request.
	 *
	 * @param array<string, string> $a Assignment row.
	 */
	private static function send_request( array $a ): void {
		$church = Settings::get()['church'];
		/* translators: 1: role, 2: date and time. */
		$subject = sprintf( __( 'Can you serve? %1$s, %2$s', 'congregation' ), self::role_label( $a['role'] ), self::when( $a ) );
		$body    = sprintf(
			/* translators: 1: first name, 2: church, 3: role, 4: date and time, 5: accept link, 6: decline link. */
			__( "Hi %1\$s,\n\nCould you serve at %2\$s as %3\$s on %4\$s?\n\nYes, I can: %5\$s\nSorry, I can't: %6\$s\n\nIf you can't, we will ask someone else straight away. Thank you!\n", 'congregation' ),
			strtok( $a['name'], ' ' ),
			$church,
			self::role_label( $a['role'] ),
			self::when( $a ),
			self::link( $a, 'accept' ),
			self::link( $a, 'decline' )
		);
		wp_mail( $a['email'], $subject, $body );
	}

	/**
	 * Handle an answer. Returns a message for the person.
	 *
	 * @param string $token  Token.
	 * @param string $answer accept or decline.
	 */
	public static function answer( string $token, string $answer ): string {
		$id = Settings::tokens()->verify(
			$token,
			static function ( int $id ): ?int {
				$a = Repo::assignment( $id );
				return null === $a ? null : (int) $a['person_id'];
			}
		);
		$a  = null === $id ? null : Repo::assignment( $id );
		if ( null === $a ) {
			return __( 'This link is not valid any more. Please contact the church office.', 'congregation' );
		}
		if ( strtotime( $a['date'] ) < strtotime( gmdate( 'Y-m-d' ) ) ) {
			return __( 'This date has passed.', 'congregation' );
		}
		if ( 'accept' === $answer ) {
			if ( 'declined' === $a['status'] ) {
				return __( 'You already said you can\'t make it, and someone else has been asked. Please contact the church office if things have changed.', 'congregation' );
			}
			Repo::update_assignment(
				(int) $a['id'],
				array(
					'status'      => 'confirmed',
					'answered_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
			/* translators: 1: role, 2: date and time. */
			return sprintf( __( 'Thank you! You are confirmed as %1$s on %2$s.', 'congregation' ), self::role_label( $a['role'] ), self::when( $a ) );
		}
		if ( 'declined' === $a['status'] ) {
			return __( 'Thanks, we already have your answer.', 'congregation' );
		}
		Repo::update_assignment(
			(int) $a['id'],
			array(
				'status'      => 'declined',
				'answered_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		self::replace( $a );
		return __( 'Thanks for letting us know. We will ask someone else.', 'congregation' );
	}

	/**
	 * Ask the best other person who can take a declined place (or record it as unfilled).
	 *
	 * @param array<string, string> $a Declined assignment row.
	 * @return int The new person's id, or 0.
	 */
	public static function replace( array $a ): int {
		$month  = substr( $a['date'], 0, 7 );
		$from   = $month . '-01';
		$to     = gmdate( 'Y-m-t', (int) strtotime( $from ) );
		$rota   = Repo::rota( Repo::services( $from, $to ) );
		$sid    = (int) $a['service_id'];
		$months = 1;
		$who    = isset( $rota->services[ $sid ] ) ? ( new Scheduler() )->best( $rota, $rota->services[ $sid ], $a['role'], $months, (int) $a['person_id'] ) : 0;
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table.
			Data\Schema::t( 'assignments' ),
			array(
				'service_id' => $sid,
				'role'       => $a['role'],
				'person_id'  => $who,
				'status'     => $who ? 'planned' : 'unfilled',
			)
		);
		$new = Repo::assignment( (int) $wpdb->insert_id );
		if ( $who && null !== $new && '' !== (string) $new['email'] ) {
			self::send_request( $new );
			Repo::update_assignment(
				(int) $new['id'],
				array(
					'status'   => 'asked',
					'asked_at' => gmdate( 'Y-m-d H:i:s' ),
				)
			);
		} elseif ( ! $who ) {
			/* translators: 1: role, 2: date and time. */
			wp_mail( (string) get_option( 'admin_email' ), sprintf( __( 'Nobody available: %1$s, %2$s', 'congregation' ), self::role_label( $a['role'] ), self::when( $a ) ), __( "Someone declined and nobody else who can do this role is free that day within their limits. Please find someone by hand.\n", 'congregation' ) );
		}
		return $who;
	}
}
