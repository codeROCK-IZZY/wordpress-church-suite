<?php
/**
 * Removes the tables and settings when the plugin is deleted. Sermons are ordinary posts and stay.
 *
 * @package Congregation
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/autoload.php';

\Congregation\Data\Schema::drop();
delete_option( 'congregation_settings' );
delete_option( 'congregation_db_version' );
