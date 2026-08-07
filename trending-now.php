<?php
/**
 * Plugin Name:       Trending Now
 * Plugin URI:        https://github.com/advision-development/trending-now-plugin
 * Description:       Server-rendered "Trending Now" link block aggregated from owned WordPress sites and Google News, plus a paginated archive. Built for crawl discovery.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Advision Development
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/advision-development/trending-now-plugin
 * Text Domain:       trending-now
 * Domain Path:       /languages
 *
 * @package Advision\TrendingNow
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ADVTN_VERSION', '1.1.0' );
define( 'ADVTN_DB_VERSION', '2' );
define( 'ADVTN_FILE', __FILE__ );
define( 'ADVTN_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADVTN_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ADVTN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-0-ish autoloader for the ADVTN_ class prefix.
 *
 * ADVTN_Source_WP_REST -> includes/sources/class-advtn-source-wp-rest.php
 *
 * @param string $class_name Fully qualified class name.
 * @return void
 */
function advtn_autoload( string $class_name ): void {
	if ( 0 !== strpos( $class_name, 'ADVTN_' ) ) {
		return;
	}

	if ( 'ADVTN_Source_Interface' === $class_name ) {
		require_once ADVTN_PATH . 'includes/sources/interface-advtn-source.php';
		return;
	}

	$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';

	foreach ( array( 'includes/', 'includes/sources/', 'admin/' ) as $dir ) {
		$path = ADVTN_PATH . $dir . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
			return;
		}
	}
}
spl_autoload_register( 'advtn_autoload' );

// Action Scheduler ships via Composer. Absence is survivable: the scheduler
// falls back to WP-Cron and diagnostics flags it.
$advtn_action_scheduler = ADVTN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( is_readable( $advtn_action_scheduler ) ) {
	require_once $advtn_action_scheduler;
}
unset( $advtn_action_scheduler );

require_once ADVTN_PATH . 'includes/template-tags.php';

register_activation_hook( __FILE__, array( 'ADVTN_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'ADVTN_Activator', 'deactivate' ) );

/**
 * Plugin singleton accessor.
 *
 * @return ADVTN_Plugin
 */
function advtn(): ADVTN_Plugin {
	return ADVTN_Plugin::instance();
}

advtn()->boot();
