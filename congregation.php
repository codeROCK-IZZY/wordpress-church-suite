<?php
/**
 * Plugin Name:       Congregation: Church Management
 * Plugin URI:        https://github.com/codeROCK-IZZY/wordpress-church-suite
 * Description:       Volunteer rotas that fill every place without overloading anyone, people and households, giving records with year-end statements, and sermons with a podcast feed.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Israel Rocklyn
 * Author URI:        https://israelrocklyn.info
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       congregation
 *
 * @package Congregation
 */

defined( 'ABSPATH' ) || exit;

define( 'CONGREGATION_VERSION', '1.0.0' );
define( 'CONGREGATION_FILE', __FILE__ );
define( 'CONGREGATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'CONGREGATION_URL', plugin_dir_url( __FILE__ ) );

require_once CONGREGATION_DIR . 'src/autoload.php';

register_activation_hook( __FILE__, array( \Congregation\Plugin::class, 'activate' ) );

add_action( 'plugins_loaded', array( \Congregation\Plugin::class, 'boot' ) );
