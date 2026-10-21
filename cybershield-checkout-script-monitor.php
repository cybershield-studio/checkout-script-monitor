<?php
/**
 * Plugin Name:       CyberShield Checkout Script Monitor for WooCommerce
 * Plugin URI:        https://github.com/cybershield-studio/checkout-script-monitor
 * Description:       See every script on your checkout and where you stand on PCI DSS 6.4.3, in plain English. A readiness and visibility tool, not a compliance guarantee.
 * Version:           1.0.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            CyberShield Studio (Dennis Wu, CISSP, PCIP)
 * Author URI:        https://cybershieldstudio.com
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cybershield-checkout-script-monitor
 * Requires Plugins:  woocommerce
 *
 * CyberShield Checkout Script Monitor for WooCommerce is free software: you can redistribute it and/or
 * modify it under the terms of the GNU General Public License, version 2, as
 * published by the Free Software Foundation.
 *
 * This plugin is not affiliated with, endorsed by, or certified by the PCI
 * Security Standards Council. It is a readiness and visibility aid only.
 *
 * @package CyberShield_Checkout_Script_Monitor
 */

defined( 'ABSPATH' ) || exit;

define( 'CSCSM_VERSION', '1.0.1' );
define( 'CSCSM_FILE', __FILE__ );
define( 'CSCSM_DIR', plugin_dir_path( __FILE__ ) );
define( 'CSCSM_URL', plugin_dir_url( __FILE__ ) );

// Option keys.
define( 'CSCSM_OPT_SETTINGS', 'cscsm_settings' );
define( 'CSCSM_OPT_INVENTORY', 'cscsm_inventory' );
define( 'CSCSM_OPT_BASELINE', 'cscsm_baseline' );
define( 'CSCSM_OPT_NOTICE', 'cscsm_notice_dismissed' );
define( 'CSCSM_OPT_DB_VERSION', 'cscsm_db_version' );
define( 'CSCSM_DB_VERSION', '1' );

require_once CSCSM_DIR . 'includes/class-inventory.php';
require_once CSCSM_DIR . 'includes/class-csp.php';
require_once CSCSM_DIR . 'includes/class-admin.php';

/**
 * Boot the plugin.
 */
function cscsm_init() {
	$inventory = new CSCSM_Inventory();
	$csp       = new CSCSM_CSP( $inventory );
	$csp->hooks();

	if ( is_admin() ) {
		$admin = new CSCSM_Admin( $inventory, $csp );
		$admin->hooks();
	}
}
add_action( 'plugins_loaded', 'cscsm_init' );

/**
 * Declare compatibility with WooCommerce High-Performance Order Storage.
 * The plugin never reads or writes orders, so it is compatible by design;
 * declaring it stops WooCommerce listing the plugin as unknown when a store
 * turns HPOS on.
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

/**
 * Activation: create the violations table and default settings.
 */
function cscsm_activate() {
	CSCSM_CSP::install_table();

	if ( false === get_option( CSCSM_OPT_SETTINGS, false ) ) {
		add_option( CSCSM_OPT_SETTINGS, array( 'csp_report_only' => 0 ) );
	}
	update_option( CSCSM_OPT_DB_VERSION, CSCSM_DB_VERSION );
}
register_activation_hook( __FILE__, 'cscsm_activate' );
