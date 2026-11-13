<?php
/**
 * WordPress privacy tools for people and their giving.
 *
 * Erasing removes contact details and serving preferences and releases the person's upcoming rota places. Gift amounts
 * and dates stay, linked only to an anonymous record, because charities usually have to keep financial records for
 * several years; the eraser says so.
 *
 * @package Congregation
 */

namespace Congregation\Privacy;

use Congregation\Core\Statement;
use Congregation\Data\Repo;

/**
 * Exporter and eraser.
 */
final class Privacy {

	/**
	 * Register.
	 */
	public static function register(): void {
		add_filter(
			'wp_privacy_personal_data_exporters',
			static function ( array $e ): array {
				$e['congregation'] = array(
					'exporter_friendly_name' => __( 'Church records', 'congregation' ),
					'callback'               => array( self::class, 'export' ),
				);
				return $e;
			}
		);
		add_filter(
			'wp_privacy_personal_data_erasers',
			static function ( array $e ): array {
				$e['congregation'] = array(
					'eraser_friendly_name' => __( 'Church records', 'congregation' ),
					'callback'             => array( self::class, 'erase' ),
				);
				return $e;
			}
		);
	}

	/**
	 * Export.
	 *
	 * @param string $email Email.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export( string $email ): array {
		$data = array();
		foreach ( Repo::by_email( $email ) as $p ) {
			$data[] = array(
				'group_id'    => 'congregation-person',
				'group_label' => __( 'Church records', 'congregation' ),
				'item_id'     => 'person-' . $p['id'],
				'data'        => array(
					array(
						'name'  => __( 'Name', 'congregation' ),
						'value' => $p['name'],
					),
					array(
						'name'  => __( 'Phone', 'congregation' ),
						'value' => $p['phone'],
					),
					array(
						'name'  => __( 'Serves as', 'congregation' ),
						'value' => $p['roles'],
					),
					array(
						'name'  => __( 'Away', 'congregation' ),
						'value' => $p['away'],
					),
				),
			);
			foreach ( Repo::gifts( (int) $p['id'] ) as $g ) {
				$data[] = array(
					'group_id'    => 'congregation-gifts',
					'group_label' => __( 'Gifts', 'congregation' ),
					'item_id'     => 'gift-' . $g['id'],
					'data'        => array(
						array(
							'name'  => __( 'Date', 'congregation' ),
							'value' => $g['given_on'],
						),
						array(
							'name'  => __( 'Amount', 'congregation' ),
							'value' => Statement::money( (int) $g['amount_cents'] ),
						),
						array(
							'name'  => __( 'Fund', 'congregation' ),
							'value' => $g['fund'],
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Erase.
	 *
	 * @param string $email Email.
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( string $email ): array {
		$people   = Repo::by_email( $email );
		$retained = false;
		foreach ( $people as $p ) {
			Repo::anonymise( (int) $p['id'] );
			$retained = $retained || (bool) Repo::gifts( (int) $p['id'] );
		}
		return array(
			'items_removed'  => (bool) $people,
			'items_retained' => $retained,
			'messages'       => $retained ? array( __( 'Gift amounts and dates were kept, no longer linked to a name, because financial records must be kept for accounting.', 'congregation' ) ) : array(),
			'done'           => true,
		);
	}
}
