<?php
/**
 * Wires the plugin into WordPress.
 *
 * @package Congregation
 */

namespace Congregation;

use Congregation\Admin\Pages;
use Congregation\Data\Repo;
use Congregation\Data\Schema;
use Congregation\Privacy\Privacy;

/**
 * Hooks.
 */
final class Plugin {

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		if ( get_option( 'congregation_db_version' ) !== Schema::DB_VERSION ) {
			Schema::install();
		}
		Sermons::register();
		Privacy::register();
		add_action( 'admin_post_nopriv_congregation_answer', array( self::class, 'answer' ) );
		add_action( 'admin_post_congregation_answer', array( self::class, 'answer' ) );
		add_shortcode( 'congregation_my_rota', array( self::class, 'my_rota' ) );
		if ( is_admin() ) {
			Pages::register();
		}
	}

	/**
	 * Tables, and rewrite rules for sermons and the podcast feed.
	 */
	public static function activate(): void {
		Schema::install();
		Sermons::types();
		add_feed( 'sermons', array( Sermons::class, 'feed' ) );
		flush_rewrite_rules();
	}

	/**
	 * A volunteer answers from the link in their email (no login needed: the signed link is the credential).
	 */
	public static function answer(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- The signed token is the credential.
		$token  = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		$answer = isset( $_GET['answer'] ) && 'accept' === $_GET['answer'] ? 'accept' : 'decline';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$message = RotaService::answer( $token, $answer );
		wp_die( '<h1>' . esc_html( Settings::get()['church'] ) . '</h1><p>' . esc_html( $message ) . '</p>', esc_html__( 'Rota', 'congregation' ), array( 'response' => 200 ) );
	}

	/**
	 * [congregation_my_rota]: the logged-in member's upcoming places.
	 */
	public static function my_rota(): string {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return '<p>' . esc_html__( 'Log in to see when you are serving.', 'congregation' ) . '</p>';
		}
		$ids = array_map( static fn ( array $p ): int => (int) $p['id'], Repo::by_email( $user->user_email ) );
		$out = '';
		foreach ( Repo::assignments( gmdate( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+3 months' ) ) ) as $a ) {
			if ( in_array( (int) $a['person_id'], $ids, true ) && 'declined' !== $a['status'] ) {
				$out .= '<li>' . esc_html( RotaService::when( $a ) . ': ' . RotaService::role_label( $a['role'] ) ) . ( 'confirmed' === $a['status'] ? '' : ' (' . esc_html__( 'please reply to your email', 'congregation' ) . ')' ) . '</li>';
			}
		}
		return '' === $out ? '<p>' . esc_html__( 'You are not on the rota in the next three months.', 'congregation' ) . '</p>' : '<ul class="congregation-my-rota">' . $out . '</ul>';
	}
}
