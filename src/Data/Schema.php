<?php
/**
 * Database tables: people, services, rota assignments and gifts.
 *
 * @package Congregation
 */

namespace Congregation\Data;

/**
 * Schema.
 */
final class Schema {

	public const DB_VERSION = '1';

	/**
	 * Table name.
	 *
	 * @param string $t people, services, assignments or gifts.
	 */
	public static function t( string $t ): string {
		global $wpdb;
		return $wpdb->prefix . 'congregation_' . $t;
	}

	/**
	 * Create or update the tables.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::t( 'people' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(120) NOT NULL,
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(40) NOT NULL DEFAULT '',
				household bigint(20) unsigned NOT NULL DEFAULT 0,
				roles text NOT NULL,
				per_month tinyint(3) unsigned NOT NULL DEFAULT 2,
				together tinyint(1) NOT NULL DEFAULT 0,
				prefers varchar(5) NOT NULL DEFAULT '',
				away text NOT NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email (email),
				KEY household (household)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'services' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				date date NOT NULL,
				time varchar(5) NOT NULL,
				label varchar(120) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY date_time (date, time)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'assignments' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				service_id bigint(20) unsigned NOT NULL,
				role varchar(60) NOT NULL,
				person_id bigint(20) unsigned NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'planned',
				asked_at datetime NULL,
				answered_at datetime NULL,
				PRIMARY KEY  (id),
				KEY service (service_id),
				KEY person (person_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::t( 'gifts' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				person_id bigint(20) unsigned NOT NULL,
				given_on date NOT NULL,
				amount_cents bigint(20) NOT NULL,
				fund varchar(80) NOT NULL DEFAULT 'General',
				method varchar(30) NOT NULL DEFAULT 'bank',
				note varchar(255) NOT NULL DEFAULT '',
				refunded tinyint(1) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY person_date (person_id, given_on)
			) {$c};"
		);
		update_option( 'congregation_db_version', self::DB_VERSION );
	}

	/**
	 * Drop the tables (uninstall).
	 */
	public static function drop(): void {
		global $wpdb;
		foreach ( array( 'gifts', 'assignments', 'services', 'people' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::t( $t ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Uninstall.
		}
	}
}
