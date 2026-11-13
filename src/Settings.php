<?php
/**
 * Settings: church details, service times, the roles each service needs, and giving funds.
 *
 * @package Congregation
 */

namespace Congregation;

use Congregation\Core\Token;

/**
 * Settings access.
 */
final class Settings {

	public const OPTION = 'congregation_settings';

	private const DAYS = array(
		'mon' => 1,
		'tue' => 2,
		'wed' => 3,
		'thu' => 4,
		'fri' => 5,
		'sat' => 6,
		'sun' => 7,
	);

	/**
	 * Defaults.
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'church'    => get_bloginfo( 'name' ),
			'times'     => 'Sun 10:30',
			'needs'     => "worship-leader: 1\nvocals: 2\nband: 2\nsound: 1\nslides: 1\nkids-leader: 1\nkids-helper: 2\nwelcome: 2\ncoffee: 2",
			'funds'     => "General\nBuilding\nMissions",
			'statement' => 'Thank you for your generosity. No goods or services were provided in exchange for these gifts.',
		);
	}

	/**
	 * Stored settings over defaults.
	 *
	 * @return array<string, string>
	 */
	public static function get(): array {
		$s = get_option( self::OPTION, array() );
		return array_merge( self::defaults(), is_array( $s ) ? array_map( 'strval', $s ) : array() );
	}

	/**
	 * Service times as [ISO weekday, H:i].
	 *
	 * @return list<array{0: int, 1: string}>
	 */
	public static function times(): array {
		$out   = array();
		$parts = preg_split( '/[,\n]+/', strtolower( self::get()['times'] ) );
		foreach ( false === $parts ? array() : $parts as $part ) {
			if ( preg_match( '/^\s*([a-z]{3})\w*\s+(\d{1,2}):(\d{2})\s*$/', $part, $m ) && isset( self::DAYS[ $m[1] ] ) ) {
				$out[] = array( self::DAYS[ $m[1] ], sprintf( '%02d:%s', (int) $m[2], $m[3] ) );
			}
		}
		return $out;
	}

	/**
	 * Roles each service needs.
	 *
	 * @return array<string, int>
	 */
	public static function needs(): array {
		$out   = array();
		$lines = preg_split( '/\n+/', self::get()['needs'] );
		foreach ( false === $lines ? array() : $lines as $line ) {
			if ( preg_match( '/^\s*([a-z0-9][a-z0-9 _-]*?)\s*:\s*(\d+)\s*$/i', $line, $m ) ) {
				$out[ sanitize_title( $m[1] ) ] = (int) $m[2];
			}
		}
		return $out;
	}

	/**
	 * Giving funds.
	 *
	 * @return list<string>
	 */
	public static function funds(): array {
		return array_values( array_filter( array_map( 'trim', explode( "\n", self::get()['funds'] ) ) ) );
	}

	/**
	 * Signed links for answering rota requests.
	 */
	public static function tokens(): Token {
		return new Token( wp_salt( 'auth' ) . 'congregation' );
	}
}
