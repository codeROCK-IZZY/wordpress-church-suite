<?php
/**
 * Test-only must-use plugin: records outgoing mail (and calendar attachments) in an option instead of sending it.
 *
 * @package Congregation
 */

add_filter(
	'pre_wp_mail',
	static function ( $short, array $atts ) {
		$log   = (array) get_option( 'congregation_test_mail', array() );
		$files = array();
		foreach ( (array) $atts['attachments'] as $file ) {
			$files[] = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		}
		$log[] = array(
			'to'          => $atts['to'],
			'subject'     => $atts['subject'],
			'message'     => $atts['message'],
			'headers'     => $atts['headers'],
			'attachments' => $files,
		);
		update_option( 'congregation_test_mail', array_slice( $log, -30 ), false );
		return true;
	},
	10,
	2
);
