<?php
/**
 * Plugin Name:       EqualWeb Accessibility
 * Plugin URI:        https://wordpress.org/plugins/equalweb/
 * Description:       The EqualWeb accessibility widget on your site, with the button designed from WordPress, plus a free PDF accessibility checker and AI remediation for your documents.
 * Version:           3.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            EqualWeb
 * Author URI:        https://www.equalweb.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       equalweb
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'EQW_PDF_VERSION', '3.0.0' );
define( 'EQW_PDF_FILE', __FILE__ );
define( 'EQW_PDF_DIR', plugin_dir_path( __FILE__ ) );
define( 'EQW_PDF_URL', plugin_dir_url( __FILE__ ) );

/**
 * Base URL of the EqualWeb free-check proxy (Cloudflare Worker).
 * Can be overridden with the EQW_PDF_WORKER_URL constant in wp-config.php.
 */
if ( ! defined( 'EQW_PDF_WORKER_URL' ) ) {
	define( 'EQW_PDF_WORKER_URL', 'https://eqw-pdf-plugins-free-check.wcag2worker.workers.dev' );
}

/**
 * Platform identifier sent to the shared proxy on registration so it can
 * report free-check usage per platform. Every port sets its own value; the
 * proxy defaults a missing value to "wordpress".
 */
if ( ! defined( 'EQW_PDF_PLATFORM' ) ) {
	define( 'EQW_PDF_PLATFORM', 'wordpress' );
}

/**
 * The separate "EqualWeb PDF Accessibility" plugin (slug equalweb-pdf-accessibility)
 * is this plugin's PDF half under its old name. If it is still active, its classes
 * are already declared and loading ours would be a fatal error; so this plugin
 * stays dormant and says what to deactivate instead.
 */
if ( class_exists( 'EQW_Admin_Page', false ) || class_exists( 'EQWPDF_Registration', false ) ) {
	add_action( 'admin_notices', function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>'
			. esc_html__( 'EqualWeb Accessibility includes everything the separate "EqualWeb PDF Accessibility" plugin does. Deactivate EqualWeb PDF Accessibility under Plugins; your settings and documents stay. Until then this plugin stays inactive.', 'equalweb' )
			. '</p></div>';
	} );
	return;
}

require_once EQW_PDF_DIR . 'includes/class-eqwpdf-registration.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-api-client.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-capabilities.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-classification.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-file-manager.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-admin-page.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-pdf-report.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-ajax.php';
require_once EQW_PDF_DIR . 'includes/class-eqw-widget.php';

add_action( 'plugins_loaded', function () {
	EQW_Capabilities::init();
	EQW_Admin_Page::init();
	EQW_Ajax::init();
	EQW_Widget::init();
} );

// Deleting an attachment must also remove our sidecar files (remediated
// cache + backup) so they do not orphan on disk.
add_action( 'delete_attachment', array( 'EQW_File_Manager', 'on_delete_attachment' ) );

/**
 * On activation: seed the default role capabilities so admins/editors can use
 * the plugin immediately.
 *
 * Registration for free checks does NOT run here. WordPress fires this hook
 * before it adds the plugin to active_plugins, so when the check proxy fetches
 * the verify route below (a separate request) the route does not exist yet and
 * the attempt can only ever fail with a 404. The site registers from the plugin
 * page instead: automatically after the page renders, from "Try again", and
 * before a check (EQW_Ajax::handle_register(), EQW_API_Client::for_check()).
 */
register_activation_hook( __FILE__, function () {
	EQW_Capabilities::seed_defaults();
} );

/**
 * REST route used by the check proxy to verify domain ownership during
 * registration. Returns the one-time nonce created by register_site().
 *
 * Both answers carry no-cache headers: WordPress sends them on REST responses
 * only to logged-in users, and the proxy's GET is anonymous, so a page cache
 * or CDN could otherwise serve an old nonce or an old 404. The 404 keeps the
 * code eqw_no_pending_registration, which the proxy recognises as "the plugin
 * answered, but the one-time code was not there" (a cache or a multi-server
 * setup) rather than "the address does not exist".
 */
add_action( 'rest_api_init', function () {
	register_rest_route( 'eqw/v1', '/verify', array(
		'methods'             => 'GET',
		// Public by design: the proxy has no credentials for this site, and the
		// nonce is a random one-time value that exists only while registering.
		'permission_callback' => '__return_true',
		'callback'            => function () {
			$nonce = get_transient( EQWPDF_Registration::TRANSIENT_NONCE );
			if ( $nonce ) {
				$response = new WP_REST_Response( array( 'nonce' => $nonce ), 200 );
			} else {
				$response = new WP_REST_Response( array(
					'code'    => 'eqw_no_pending_registration',
					'message' => 'No registration is pending. EqualWeb uses this address to verify the site; if you can see this message, the address is reachable.',
					'data'    => array( 'status' => 404 ),
				), 404 );
			}
			foreach ( wp_get_nocache_headers() as $name => $value ) {
				// A false value (Last-Modified) means "send none", not a header.
				if ( empty( $value ) ) {
					continue;
				}
				$response->header( $name, $value );
			}
			$response->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			return $response;
		},
	) );
} );
