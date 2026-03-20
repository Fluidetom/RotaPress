<?php
declare(strict_types=1);
/**
 * Plugin Name:       RotaPress
 * Plugin URI:        https://github.com/Fluidetom/RotaPress
 * Description:       Editorial rota calendar with role-based access, recurring events, email reminders and personal iCal feed.
 * Version:           1.2.0
 * Requires at least: 6.3
 * Requires PHP:      8.0
 * Author:            Thomas Mallié
 * Author URI:        https://github.com/Fluidetom
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       rotapress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ROTAPRESS_VERSION', '1.2.0' );
define( 'ROTAPRESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'ROTAPRESS_URL', plugin_dir_url( __FILE__ ) );

require_once ROTAPRESS_DIR . 'includes/class-rotapress-roles.php';
require_once ROTAPRESS_DIR . 'includes/class-rotapress-cpt.php';
require_once ROTAPRESS_DIR . 'includes/class-rotapress-recurrence.php';
require_once ROTAPRESS_DIR . 'includes/class-rotapress-calendar.php';
require_once ROTAPRESS_DIR . 'includes/class-rotapress-reminders.php';
require_once ROTAPRESS_DIR . 'includes/class-rotapress-ical.php';
require_once ROTAPRESS_DIR . 'admin/class-rotapress-admin.php';

register_activation_hook( __FILE__, array( 'RotaPress_Roles', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'RotaPress_Roles', 'deactivate' ) );

/**
 * Initialise all plugin components on plugins_loaded.
 */
function rotapress_init(): void {
	load_plugin_textdomain(
		'rotapress',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);

	new RotaPress_Roles();
	new RotaPress_CPT();
	new RotaPress_Calendar();
	new RotaPress_Reminders();
	new RotaPress_iCal();
	new RotaPress_Admin();
}
add_action( 'plugins_loaded', 'rotapress_init' );
