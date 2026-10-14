<?php
/**
 * Cleanup on uninstall: options, custom capabilities, attachment meta and any
 * legacy remediated-cache sidecars.
 *
 * Backup files (.eqw-backup) on disk are intentionally kept so originals are
 * never lost by removing the plugin. The remediated-cache sidecars are NOT:
 * nothing writes them any more (the audited file is fetched from EqualWeb on
 * demand), but an install upgraded from an older version can still hold them,
 * and they are unreachable without the plugin's meta (which records the random
 * token in their filename) — so leaving them would orphan paid remediated PDFs
 * in the uploads directory forever.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Reused for the sidecar sweep: one source of truth for how those files are
// named. The class only needs ABSPATH, which uninstall runs under.
if ( ! class_exists( 'EQW_File_Manager' ) && file_exists( __DIR__ . '/includes/class-eqw-file-manager.php' ) ) {
	require_once __DIR__ . '/includes/class-eqw-file-manager.php';
}

/**
 * Options, roles/capabilities and postmeta are all per-site, so on
 * multisite this runs once per site.
 */
function eqw_pdf_uninstall_site() {
	// The accessibility widget (3.0) and the options of the 1.0 widget plugin it replaced.
	delete_option( 'eqw_widget' );
	delete_option( 'equalweb_code' );
	delete_option( 'equalweb_enable' );
	delete_option( 'equalweb_activation_redirect' );
	delete_transient( 'eqw_widget_langs' );
	delete_transient( 'eqw_widget_update' );
	delete_option( 'eqw_pdf_api_key' );
	delete_option( 'eqw_pdf_site_token' );
	delete_option( 'eqw_pdf_register_error' );
	delete_option( 'eqw_pdf_audit_defaults' );
	delete_option( 'eqw_pdf_site_host' );
	delete_option( 'eqw_pdf_caps_version' );
	// Free-check registration state (EQWPDF_Registration).
	delete_option( 'eqw_pdf_register_class' );
	delete_option( 'eqw_pdf_register_at' );
	delete_option( 'eqw_pdf_register_detail' );
	delete_option( 'eqw_pdf_register_ok_at' );
	delete_option( 'eqw_pdf_register_attempted' );
	delete_option( 'eqw_pdf_token_rejected_at' );
	delete_transient( 'eqw_pdf_register_nonce' );
	delete_transient( 'eqw_pdf_rereg_throttle' );
	delete_transient( 'eqw_pdf_reg_attempted' );

	// Remove the plugin's capabilities from every role.
	$caps = array( 'eqw_pdf_view', 'eqw_pdf_check', 'eqw_pdf_remediate', 'eqw_pdf_replace', 'eqw_pdf_view_all' );
	foreach ( array_keys( wp_roles()->roles ) as $slug ) {
		$role = get_role( $slug );
		if ( ! $role ) {
			continue;
		}
		foreach ( $caps as $cap ) {
			$role->remove_cap( $cap );
		}
	}

	global $wpdb;

	// Sweep the legacy "{file}.{token}.eqw-remediated" cache sidecars BEFORE the
	// meta that points at them is deleted: _eqw_rem_cache is what marks an
	// attachment as having one. sweep_sidecars() scans by prefix, so an orphan
	// left by an interrupted write goes too.
	if ( class_exists( 'EQW_File_Manager' ) ) {
		$eqw_cached = $wpdb->get_col( "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_eqw_rem_cache'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup, caching not applicable.
		foreach ( $eqw_cached as $eqw_post_id ) {
			$eqw_path = get_attached_file( (int) $eqw_post_id );
			if ( $eqw_path ) {
				EQW_File_Manager::sweep_sidecars( $eqw_path );
			}
		}
	}

	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '\_eqw\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup, caching not applicable.
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $eqw_pdf_blog_id ) {
		switch_to_blog( $eqw_pdf_blog_id );
		eqw_pdf_uninstall_site();
		restore_current_blog();
	}
} else {
	eqw_pdf_uninstall_site();
}
