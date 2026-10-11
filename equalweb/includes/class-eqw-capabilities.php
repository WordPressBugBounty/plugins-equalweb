<?php
/**
 * Custom capabilities: who may view, check, remediate and replace PDF files.
 *
 * Real WordPress capabilities written to roles (visible to role-manager
 * plugins, grantable per-user, super-admin bypass for free). The Permissions
 * tab is a thin UI over them; every AJAX endpoint re-checks server-side.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EQW_Capabilities {

	// Open the plugin page; see the files list and analytics (scope-filtered);
	// view stored reports and download remediated copies.
	const CAP_VIEW = 'eqw_pdf_view';

	// Run the free accessibility check; poll any running process.
	const CAP_CHECK = 'eqw_pdf_check';

	// Run paid remediation (spends the site's shared EqualWeb credits).
	const CAP_REMEDIATE = 'eqw_pdf_remediate';

	// Apply results to the live site: replace original, save-as-new, restore.
	const CAP_REPLACE = 'eqw_pdf_replace';

	// Scope modifier: see/act on ALL PDFs instead of only own uploads.
	const CAP_VIEW_ALL = 'eqw_pdf_view_all';

	// Seeding guard: bump VERSION when the default grants change.
	const OPT_VERSION = 'eqw_pdf_caps_version';
	const VERSION     = '1';

	/** Every plugin capability, in matrix column order. */
	const ALL_CAPS = array( self::CAP_VIEW, self::CAP_CHECK, self::CAP_REMEDIATE, self::CAP_REPLACE, self::CAP_VIEW_ALL );

	public static function init() {
		// Seed right here, at plugins_loaded time (not on a later hook): after
		// a silent auto-update the first wp-admin request snapshots the current
		// user's caps in WP::init() — BEFORE the 'init' and 'admin_init' hooks —
		// so any later seed would 403/hide the page for that request. Roles are
		// already loadable at plugins_loaded.
		if ( is_admin() ) {
			self::maybe_seed();
		}
	}

	/**
	 * Cheap self-heal: activation hooks do not run on plugin UPDATES, so the
	 * defaults are seeded on the first wp-admin load after an update (same
	 * pattern as the registration self-heal). At the current version it only
	 * re-asserts the administrator grants (anti-lockout) — it never re-adds
	 * caps an admin deliberately removed from other roles.
	 */
	public static function maybe_seed() {
		if ( self::VERSION !== get_option( self::OPT_VERSION ) ) {
			self::seed_defaults();
			return;
		}
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( self::ALL_CAPS as $cap ) {
				if ( ! $admin->has_cap( $cap ) ) {
					$admin->add_cap( $cap );
				}
			}
		}
	}

	/**
	 * First-run default grants: administrator gets everything, editor gets
	 * view + check (own files only). Every other role gets nothing — an admin
	 * grants more from the Permissions tab.
	 */
	public static function seed_defaults() {
		$defaults = array(
			'administrator' => self::ALL_CAPS,
			'editor'        => array( self::CAP_VIEW, self::CAP_CHECK ),
		);
		foreach ( $defaults as $slug => $caps ) {
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			foreach ( $caps as $cap ) {
				if ( ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
		update_option( self::OPT_VERSION, self::VERSION, false );
	}

	/**
	 * Capability matrix for the Permissions tab.
	 *
	 * @return array role slug => array( 'name' => display name,
	 *                                   'caps' => array( cap => bool ) ).
	 */
	public static function get_matrix() {
		$matrix = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$caps = array();
			foreach ( self::ALL_CAPS as $cap ) {
				$caps[ $cap ] = ! empty( $role['capabilities'][ $cap ] );
			}
			$matrix[ $slug ] = array(
				'name' => translate_user_role( $role['name'] ),
				'caps' => $caps,
			);
		}
		return $matrix;
	}

	/**
	 * Apply a submitted matrix. Unknown roles and caps are ignored, the
	 * administrator row is never modified (anti-lockout), and granting any
	 * action capability or the all-files scope auto-grants view. Roles
	 * absent from the input lose
	 * every capability — an unticked row means "revoke".
	 *
	 * @param array $input role slug => array( cap => anything truthy ).
	 */
	public static function save_matrix( $input ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		foreach ( array_keys( wp_roles()->roles ) as $slug ) {
			if ( 'administrator' === $slug ) {
				continue; // Locked: administrators always keep every capability.
			}
			$role = get_role( $slug );
			if ( ! $role ) {
				continue;
			}
			$wanted = isset( $input[ $slug ] ) && is_array( $input[ $slug ] ) ? $input[ $slug ] : array();
			$grant  = array();
			foreach ( self::ALL_CAPS as $cap ) {
				$grant[ $cap ] = ! empty( $wanted[ $cap ] );
			}
			// An action capability is useless without view — imply it. The
			// all-files scope is a view modifier, so it implies view too.
			if ( $grant[ self::CAP_CHECK ] || $grant[ self::CAP_REMEDIATE ] || $grant[ self::CAP_REPLACE ] || $grant[ self::CAP_VIEW_ALL ] ) {
				$grant[ self::CAP_VIEW ] = true;
			}
			foreach ( $grant as $cap => $on ) {
				if ( $on && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				} elseif ( ! $on && $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}
	}

	/**
	 * Whether the current user may act on this attachment: either they hold
	 * the all-files scope, or they own the file. Attachments with no author
	 * (post_author = 0, programmatic uploads) fail closed for scoped users.
	 *
	 * @param int $attachment_id
	 * @return bool
	 */
	public static function current_user_can_scope( $attachment_id ) {
		if ( current_user_can( self::CAP_VIEW_ALL ) ) {
			return true;
		}
		$author = (int) get_post_field( 'post_author', $attachment_id );
		return $author > 0 && $author === get_current_user_id();
	}
}
