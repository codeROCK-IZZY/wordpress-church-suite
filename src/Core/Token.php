<?php
/**
 * Signed links that let a volunteer accept or decline a place without logging in.
 *
 * @package Congregation
 */

namespace Congregation\Core;

/**
 * Token: "<assignment id>.<HMAC of the id and the person it was sent to>".
 */
final class Token {

	/**
	 * Constructor.
	 *
	 * @param string $secret Site secret.
	 */
	public function __construct( private string $secret ) {}

	/**
	 * Token for an assignment sent to a person.
	 *
	 * @param int $assignment Assignment id.
	 * @param int $person     Person id.
	 */
	public function issue( int $assignment, int $person ): string {
		return $assignment . '.' . hash_hmac( 'sha256', 'rota:' . $assignment . ':' . $person, $this->secret );
	}

	/**
	 * The assignment id, if the token is genuine for the person now holding it.
	 *
	 * @param string   $token  Token.
	 * @param callable $holder Assignment id => person id holding it, or null.
	 */
	public function verify( string $token, callable $holder ): ?int {
		if ( ! preg_match( '/^(\d+)\.([a-f0-9]{64})$/', $token, $m ) ) {
			return null;
		}
		$id     = (int) $m[1];
		$person = $holder( $id );
		return is_int( $person ) && hash_equals( $this->issue( $id, $person ), $token ) ? $id : null;
	}
}
