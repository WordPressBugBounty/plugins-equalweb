<?php
/**
 * Admin screen: PDF list + settings tab.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EQW_Admin_Page {

	const SLUG = 'equalweb';

	/** EqualWeb account registration page (shared by JS and the Start tab). */
	const SIGNUP_URL = 'https://login.equalweb.com/registration';

	/**
	 * EqualWeb PDF Tools support page: the header link and the registration
	 * notice. Support routing is for administrators only (nir, 2026-08-05);
	 * everyone else is told to ask their own administrator.
	 */
	const SUPPORT_URL = EQWPDF_Registration::SUPPORT_URL;

	/**
	 * Supported document mime types -> doctype key.
	 *
	 * The single source of truth for what the plugin manages: the file list,
	 * analytics and the AJAX guard all accept exactly these mimes. Whatever
	 * goes in, what comes back from remediation is ALWAYS a PDF.
	 */
	const DOC_MIMES = array(
		'application/pdf' => 'pdf',
		'application/msword' => 'word',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'word',
		'application/vnd.ms-powerpoint' => 'ppt',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'ppt',
		'application/vnd.ms-excel' => 'xls',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xls',
	);

	/** Doctype key ('pdf'|'word'|'ppt'|'xls') for a mime type. */
	public static function doctype( $mime ) {
		return isset( self::DOC_MIMES[ $mime ] ) ? self::DOC_MIMES[ $mime ] : 'pdf';
	}

	/**
	 * Inline SVG UI icon ('external' | 'download' | 'moon'), ~12px, decorative.
	 *
	 * stroke="currentColor" makes the glyph inherit the surrounding text color,
	 * so it recolors with links/buttons in both themes. assets/admin.js keeps an
	 * identical ICONS map — PHP-rendered and JS-rendered links MUST look the
	 * same, so any change here must be mirrored there.
	 */
	public static function icon( $name ) {
		$paths = array(
			// A small box with an arrow leaving its top-right corner.
			'external' => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M9 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3"/>',
			// An arrow pointing down into a tray.
			'download' => '<path d="M12 4v11"/><path d="M6 10l6 6 6-6"/><path d="M4 20h16"/>',
			// Crescent moon (theme toggle).
			'moon' => '<path d="M20 13A8 8 0 1 1 11 4a6.5 6.5 0 0 0 9 9z"/>',
		);
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		$stroke_width = 'moon' === $name ? '2' : '2.2';
		return '<svg class="eqw-i" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
			. ' stroke-width="' . $stroke_width . '" stroke-linecap="round" stroke-linejoin="round"'
			. ' aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	/**
	 * Inline SVG file-type icon: a document glyph (rounded rectangle with a
	 * folded top-right corner) filled with the type color, bold white type
	 * letters centered on it. Emitted inline per row/pill — no external assets,
	 * the page stays self-contained. The fills are fixed brand-ish colors and
	 * the letters stay white, so the icon is light+dark safe.
	 */
	private static function type_icon( $doctype, $decorative = false ) {
		$colors  = array( 'pdf' => '#c2372f', 'word' => '#2b5797', 'ppt' => '#c2510f', 'xls' => '#1e7145' );
		$letters = array( 'pdf' => 'PDF', 'word' => 'DOC', 'ppt' => 'PPT', 'xls' => 'XLS' );
		$color   = isset( $colors[ $doctype ] ) ? $colors[ $doctype ] : '#5b6472';
		$label   = isset( $letters[ $doctype ] ) ? $letters[ $doctype ] : 'DOC';
		$a11y    = $decorative ? ' aria-hidden="true"' : ' role="img" aria-label="' . esc_attr( $label ) . '"';
		return '<svg class="eqw-ficon eqw-ficon-' . esc_attr( $doctype ) . '" width="26" height="32" viewBox="0 0 26 32"' . $a11y . ' focusable="false">'
			. '<path d="M3 0h14l9 9v20a3 3 0 0 1-3 3H3a3 3 0 0 1-3-3V3a3 3 0 0 1 3-3z" fill="' . esc_attr( $color ) . '"/>'
			. '<path d="M17 0l9 9h-7a2 2 0 0 1-2-2V0z" fill="#fff" fill-opacity="0.35"/>'
			. '<text x="13" y="24.5" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="8" font-weight="700" letter-spacing="0.3" fill="#fff">' . esc_html( $label ) . '</text>'
			. '</svg>';
	}

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'EqualWeb Accessibility', 'equalweb' ),
			__( 'EqualWeb', 'equalweb' ),
			EQW_Capabilities::CAP_VIEW,
			self::SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-universal-access-alt',
			58
		);
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		// filemtime-based versions bust browser caches on every plugin update.
		$css_ver = EQW_PDF_VERSION . '.' . (int) @filemtime( EQW_PDF_DIR . 'assets/admin.css' );
		$js_ver  = EQW_PDF_VERSION . '.' . (int) @filemtime( EQW_PDF_DIR . 'assets/admin.js' );
		wp_enqueue_style( 'eqw-pdf-admin', EQW_PDF_URL . 'assets/admin.css', array(), $css_ver );
		wp_enqueue_script( 'eqw-pdf-admin', EQW_PDF_URL . 'assets/admin.js', array( 'jquery' ), $js_ver, true );
		wp_localize_script( 'eqw-pdf-admin', 'eqwPdf', array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( EQW_Ajax::NONCE ),
			'hasApiKey'   => (bool) EQW_API_Client::get_personal_key(),
			'settingsUrl' => admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ),
			'signupUrl'   => self::SIGNUP_URL,
			'supportUrl'  => self::SUPPORT_URL,
			// Free-check registration: whether this host holds a working
			// registration (the JS pre-flight before a check reads it), and
			// whether the page script should register automatically after the
			// page has rendered, never holding it up. The server applies the
			// retry policy (EQWPDF_Registration::auto_due()); admin-only, so a
			// viewer can never trigger outbound registration traffic.
			'registered'  => EQWPDF_Registration::state()['registered'],
			'regAuto'     => current_user_can( 'manage_options' ) && 'widget' !== self::current_tab() && EQWPDF_Registration::auto_due(),
			// Cosmetic only — every button's server counterpart re-checks.
			'caps'        => array(
				'check'     => current_user_can( EQW_Capabilities::CAP_CHECK ),
				'remediate' => current_user_can( EQW_Capabilities::CAP_REMEDIATE ),
				'replace'   => current_user_can( EQW_Capabilities::CAP_REPLACE ),
				'viewAll'   => current_user_can( EQW_Capabilities::CAP_VIEW_ALL ),
				// Classification actions (tag/ignore/reclassify) are admin-only,
				// server-enforced — this only gates the cosmetic controls.
				'admin'     => current_user_can( 'manage_options' ),
			),
			'isAdmin'     => current_user_can( 'manage_options' ),
			'i18n'    => array(
				'widgetPreviewTitle'  => __( 'Your page', 'equalweb' ),
				'widgetPreviewText'   => __( 'The accessibility button appears where your visitors will see it.', 'equalweb' ),
				/* translators: %s: the contrast ratio, e.g. 4.5:1 */
				'widgetContrast'      => __( 'Icon to button contrast: %s', 'equalweb' ),
				'widgetContrastOk'    => __( '(meets WCAG for graphics)', 'equalweb' ),
				'widgetContrastLow'   => __( '(below 3:1, hard to see)', 'equalweb' ),
				'widgetChecking'      => __( 'Checking…', 'equalweb' ),
				'widgetCheckOk'       => __( 'The widget is on your site.', 'equalweb' ),
				'widgetCheckPartial'  => __( 'Only part of the widget was found on your site (a cache or another plugin may be in the way).', 'equalweb' ),
				'widgetCheckMissing'  => __( 'The widget was not found on your site. A page cache may still serve old pages; purge it and check again.', 'equalweb' ),
				'widgetCheckError'    => __( 'Your site could not be fetched.', 'equalweb' ),
				'checking'        => __( 'Checking…', 'equalweb' ),
				'remediating'     => __( 'Remediating…', 'equalweb' ),
				'error'           => __( 'Error', 'equalweb' ),
				'check'           => __( 'PDF Checker', 'equalweb' ),
				'details'         => __( 'Details', 'equalweb' ),
				'remediate'       => __( 'Auto Remediation', 'equalweb' ),
				'needKeyTitle'    => __( 'EqualWeb account required', 'equalweb' ),
				'needKeyBody'     => __( 'Automatic remediation is a paid EqualWeb service. To remediate documents you need an EqualWeb account, a personal API key and credits (1 credit ≈ 1 page). Create an account, purchase credits, then paste your API key in the Settings tab.', 'equalweb' ),
				'needKeyBodyNoAdmin' => __( 'Remediation requires a site-wide EqualWeb API key. Ask a site administrator to configure it in the plugin settings.', 'equalweb' ),
				'inProgress'      => __( 'In progress…', 'equalweb' ),
				'buyCredits'      => __( 'Create account & purchase credits', 'equalweb' ),
				'openSettings'    => __( 'Open Settings', 'equalweb' ),
				'applyReplace'    => __( 'Replace original (backup kept)', 'equalweb' ),
				'applyNew'        => __( 'Save as a new file', 'equalweb' ),
				'applyDownload'   => __( 'Download only', 'equalweb' ),
				'restore'         => __( 'Restore original', 'equalweb' ),
				'confirmReplace'  => __( 'Replace the original file with the accessible version? A backup of the original will be kept and can be restored.', 'equalweb' ),
				'confirmRestore'  => __( 'Restore the original (non-accessible) file from backup?', 'equalweb' ),
				'remediateReady'  => __( 'Accessible version ready. Choose what to do:', 'equalweb' ),
				'passed'          => __( 'Passed', 'equalweb' ),
				'failed'          => __( 'Failed', 'equalweb' ),
				'warnings'        => __( 'Warnings', 'equalweb' ),
				'score'           => __( 'Accessibility score', 'equalweb' ),
				'reportTitle'     => __( 'Accessibility report', 'equalweb' ),
				'reportColStatus' => __( 'Status', 'equalweb' ),
				'reportColIssue'  => __( 'Issue description', 'equalweb' ),
				'externalLink'    => __( 'external link', 'equalweb' ),
				'noIssues'        => __( 'No failed checks. Great!', 'equalweb' ),
				'before'          => __( 'Before', 'equalweb' ),
				'after'           => __( 'After', 'equalweb' ),
				'removeKeyConfirm' => __( 'Remove the API key permanently? Remediation will stop working until a new key is saved.', 'equalweb' ),
				'viewReport'      => __( 'View report', 'equalweb' ),
				'checkLockTitle'  => __( 'A check is already running. Wait for it to finish.', 'equalweb' ),
				'notChecked'      => __( 'Not checked', 'equalweb' ),
				'notRemediated'   => __( 'Not remediated', 'equalweb' ),
				'accessible'      => __( 'Accessible', 'equalweb' ),
				'ready'           => __( 'Ready to apply', 'equalweb' ),
				'items'           => __( 'items', 'equalweb' ),
				'selected'        => __( 'selected', 'equalweb' ),
				'openCopy'        => __( 'Open accessible copy', 'equalweb' ),
				'sendRemediation' => __( 'Send to remediation', 'equalweb' ),
				'officeConfirm'   => __( 'This document will be converted to an accessible PDF. Continue?', 'equalweb' ),
				'saveAsPdf'       => __( 'Save as PDF in the media library', 'equalweb' ),
				'keyRequired'     => __( 'Requires an EqualWeb API key - add it in Settings.', 'equalweb' ),
				'officeReady'     => __( 'Accessible PDF ready - choose what to do:', 'equalweb' ),
				'download'        => __( 'Download', 'equalweb' ),
				'openAccessiblePdf' => __( 'Open accessible PDF', 'equalweb' ),
				'downloadPdf'     => __( 'Download PDF', 'equalweb' ),
				// Bulk outcome notice. The count is prefixed by the JS exactly as it is
				// for 'selected', so each string begins at the word after the number.
				'bulkSentOne'          => __( 'document sent.', 'equalweb' ),
				'bulkSentMany'         => __( 'documents sent.', 'equalweb' ),
				'bulkSkippedChecked'   => __( 'skipped (already checked).', 'equalweb' ),
				'bulkSkippedProcessed' => __( 'skipped (already processed).', 'equalweb' ),
				'bulkSkippedOffice'    => __( 'skipped (the checker is PDF only).', 'equalweb' ),
				'bulkNothingSent'      => __( 'Nothing sent.', 'equalweb' ),
				'bulkNothingToSend'    => __( 'Nothing to send for the selected documents.', 'equalweb' ),
				// Classification (triage).
				'clsTitle'         => __( 'Change classification', 'equalweb' ),
				'clsBody'          => __( 'Your tag overrides the automatic rule for the selected documents and is logged. There is no "Exempt" option — lower priority never removes a document from scope.', 'equalweb' ),
				'clsIgnoreConfirm' => __( "Ignore the selected documents?\n\nIgnored documents are hidden from the list but NOT deleted and NOT exempted. The action is logged and reversible from the category panel.", 'equalweb' ),
				'clsTagged'        => __( 'documents reclassified.', 'equalweb' ),
				'clsIgnored'       => __( 'documents ignored (reversible).', 'equalweb' ),
				'clsRestored'      => __( 'ignored documents restored.', 'equalweb' ),
				'clsAccessibility' => __( 'Accessibility score', 'equalweb' ),
				'clsLegal'         => __( 'Legal priority', 'equalweb' ),
				'clsUsage'         => __( 'Usage priority', 'equalweb' ),
				'clsLegalSub'      => __( 'fix-first guidance, not legal advice', 'equalweb' ),
				'clsUsageNA'       => __( 'Not tracked on this platform yet', 'equalweb' ),
				'clsWhy'           => __( 'Why this rank', 'equalweb' ),
				'clsMeasured'      => __( 'measured — WCAG 2.1 AA / PDF/UA', 'equalweb' ),
				'clsAuto'          => __( 'Back to automatic (location tag / rules apply)', 'equalweb' ),
				'clsChipHint'      => __( 'Click to change the classification', 'equalweb' ),
				'clsAutoDone'      => __( 'documents set back to automatic classification.', 'equalweb' ),
				'clsLocTagged'     => __( 'Location tagged — everything inside inherits it.', 'equalweb' ),
				'clsLocCleared'    => __( 'Location tag removed — automatic rules apply again.', 'equalweb' ),
				'clsYearsSaved'    => __( 'Auto-archive threshold updated and reapplied.', 'equalweb' ),
				// Free-check registration: the bulk notice when a check cannot
				// start because the site is not registered (role-aware).
				'regRowAdmin'      => __( 'Free checks are not set up on this site yet. The notice at the top of the page explains why and what to do.', 'equalweb' ),
				'regRowUser'       => __( 'Free checks are not set up on this site yet. Ask a site administrator to open the PDF accessibility page.', 'equalweb' ),
			),
		) );
	}

	/**
	 * The tab being shown; the documents list by default (nir, 2026-10-11: the
	 * widget tab is the last entry in the nav, after Permissions).
	 */
	public static function current_tab() {
		if ( isset( $_GET['tab'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, capability-checked.
			return sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		}
		return 'files';
	}

	public static function render() {
		// Free-check registration never runs while the page renders: it would
		// hold the page for up to 30 s. The page script starts it once the page
		// is up (eqwPdf.regAuto), the notice below shows the outcome, and
		// "Try again" re-runs it.
		$tab = self::current_tab();
		?>
		<div class="wrap eqw-wrap">
			<h1 class="eqw-h1">
				<img class="eqw-logo" src="<?php echo esc_url( EQW_PDF_URL . 'assets/logo.png' ); ?>" alt="EqualWeb" height="24">
				<span class="eqw-h1-rest"><?php esc_html_e( 'Accessibility widget, PDF checker & AI remediation', 'equalweb' ); ?></span>
			</h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=files' ) ); ?>"
				   class="nav-tab <?php echo 'files' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Documents', 'equalweb' ); ?></a>
				<?php // 'Compliance' groups Analytics + Classification (a sub-nav renders inside); its nav entry lands on Analytics and lights up for both. ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=analytics' ) ); ?>"
				   class="nav-tab <?php echo in_array( $tab, array( 'analytics', 'classification' ), true ) ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Compliance', 'equalweb' ); ?> &#9662;</a>
				<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=ai' ) ); ?>"
				   class="nav-tab <?php echo 'ai' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'PDF AI Remediation', 'equalweb' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=start' ) ); ?>"
				   class="nav-tab <?php echo 'start' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Start Auto Remediation', 'equalweb' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ); ?>"
				   class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'equalweb' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=permissions' ) ); ?>"
				   class="nav-tab <?php echo 'permissions' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Permissions', 'equalweb' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=widget' ) ); ?>"
				   class="nav-tab <?php echo 'widget' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Accessibility Widget', 'equalweb' ); ?></a>
				<?php endif; ?>
				<a href="https://www.equalweb.com/platform/pdf_tools/" target="_blank" rel="noopener"
				   class="eqw-tools-link button"><?php esc_html_e( 'EqualWeb PDF Tools', 'equalweb' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(external website)', 'equalweb' ); ?></span><?php echo self::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-built SVG. ?></a>
				<?php if ( current_user_can( 'manage_options' ) ) : // Support routing is admin-only; other users ask their administrator. ?>
				<a href="<?php echo esc_url( self::SUPPORT_URL ); ?>" target="_blank" rel="noopener"
				   class="eqw-tools-link button"><?php esc_html_e( 'Support', 'equalweb' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(external website)', 'equalweb' ); ?></span><?php echo self::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-built SVG. ?></a>
				<?php endif; ?>
				<button type="button" class="button eqw-theme-toggle" id="eqw-theme-toggle"
					aria-pressed="false"><?php echo self::icon( 'moon' ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-built SVG. ?><?php esc_html_e( 'Toggle dark theme', 'equalweb' ); ?></button>
			</nav>
			<?php
			// Free-check registration status, on every tab, whatever the role:
			// the Settings tab renders the full status in its own section.
			self::registration_notice( $tab );
			// Admin-only tabs fall back to the files list for everyone else —
			// the AJAX endpoints are the real boundary; a friendly fallback
			// beats a wp_die for a viewer following a stale link. The AI and
			// Start tabs are site-owner material (buying credits, configuring
			// the API key) that a non-admin cannot act on.
			if ( 'widget' === $tab && current_user_can( 'manage_options' ) ) {
				EQW_Widget::render_tab();
			} elseif ( 'settings' === $tab && current_user_can( 'manage_options' ) ) {
				self::render_settings();
			} elseif ( 'permissions' === $tab && current_user_can( 'manage_options' ) ) {
				self::render_permissions();
			} elseif ( 'analytics' === $tab ) {
				self::compliance_nav( 'analytics' );
				self::render_analytics();
			} elseif ( 'classification' === $tab && current_user_can( 'manage_options' ) ) {
				self::compliance_nav( 'classification' );
				self::render_classification();
			} elseif ( 'ai' === $tab && current_user_can( 'manage_options' ) ) {
				self::render_ai();
			} elseif ( 'start' === $tab && current_user_can( 'manage_options' ) ) {
				self::render_start();
			} else {
				self::render_files();
			}
			?>
			<p class="eqw-version-foot"><?php echo esc_html( 'EqualWeb Accessibility v' . EQW_PDF_VERSION ); ?></p>
		</div>
		<div id="eqw-modal" class="eqw-modal" hidden>
			<div class="eqw-modal-box">
				<button type="button" class="eqw-modal-close" aria-label="<?php esc_attr_e( 'Close', 'equalweb' ); ?>">&times;</button>
				<div class="eqw-modal-content"></div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Free-check registration notice                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * The free-check registration notice above the tab content, while this
	 * site is not registered. Administrators get the full status box: why,
	 * what to do, the exact verification address to allow, Try again, the
	 * support link and the technical details. Anyone else who can run checks
	 * gets one line with nothing to act on. When checks run on a personal key
	 * instead, the page stays quiet and the Settings tab carries the status.
	 *
	 * @param string $tab The tab being rendered.
	 */
	private static function registration_notice( $tab ) {
		// The widget tab is not about PDF checks: no notice there, and no automatic registration (see enqueue()).
		if ( 'settings' === $tab || 'widget' === $tab ) {
			return;
		}
		if ( '' !== EQW_API_Client::get_personal_key() || EQWPDF_Registration::state()['registered'] ) {
			return;
		}
		if ( current_user_can( 'manage_options' ) ) {
			echo self::registration_box(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by registration_notice_body().
			return;
		}
		if ( current_user_can( EQW_Capabilities::CAP_CHECK ) ) {
			echo '<p class="eqw-regnotice-line">' . esc_html( EQWPDF_Registration::not_registered_message() ) . '</p>';
		}
	}

	/**
	 * The registration status box: a stable region whose body Try again
	 * replaces in place, plus a polite live region that announces the result.
	 *
	 * @return string HTML, built from escaped parts.
	 */
	private static function registration_box() {
		return '<section id="eqw-regnotice" class="eqw-regnotice" aria-labelledby="eqw-regnotice-title">'
			. self::registration_notice_body()
			. '<p class="eqw-regnotice-live screen-reader-text" role="status" aria-live="polite" aria-atomic="true"></p>'
			. '</section>';
	}

	/**
	 * The body of the registration status box for this site's current state.
	 *
	 * The register AJAX action returns it too, so Try again updates the box
	 * without a reload. Meaning never rests on colour: every state has its own
	 * heading text. The inner div carries WordPress's notice classes WITH
	 * 'inline', or core's common.js would move it to the top of the page.
	 *
	 * @return string HTML, built from escaped parts.
	 */
	public static function registration_notice_body() {
		$state = EQWPDF_Registration::state();
		if ( $state['registered'] ) {
			return '<div class="eqw-regnotice-body notice notice-success inline eqw-regnotice-ok">'
				. self::registration_title( __( 'Free checks are set up', 'equalweb' ) )
				. '<p><span aria-hidden="true">&#10003;</span> ' . esc_html__( 'This site is registered for free accessibility checks.', 'equalweb' ) . '</p>'
				. '</div>';
		}
		$f = $state['failure'];
		// After a move, or on a copy of another site, the registration on record
		// belongs to the old address: say so, or a site that used to work just
		// looks broken.
		$moved = null === $state['movedfrom'] ? ''
			/* translators: %s: the host name the stored registration was issued for. */
			: '<p>' . esc_html( sprintf( __( 'This site was registered for free checks at %s. Its address has changed, so it has to register again.', 'equalweb' ), $state['movedfrom'] ) ) . '</p>';
		$h = '<div class="eqw-regnotice-body notice inline ' . ( null === $f ? 'notice-warning eqw-regnotice-pending' : 'notice-error eqw-regnotice-bad' ) . '">';
		if ( null === $f ) {
			$h .= self::registration_title( __( 'Free checks are being set up', 'equalweb' ) ) . $moved
				. '<p>' . esc_html__( 'This site registers with the EqualWeb check service automatically. It takes up to 30 seconds.', 'equalweb' ) . '</p>';
		} else {
			$h .= self::registration_title( __( 'Free checks are not set up yet', 'equalweb' ) ) . $moved
				. '<p>' . esc_html( EQWPDF_Registration::why( $f ) ) . '</p>'
				. '<p><strong>' . esc_html__( 'What to do:', 'equalweb' ) . '</strong> '
				. esc_html( EQWPDF_Registration::todo( $f ) ) . '</p>';
			if ( EQWPDF_Registration::names_verify_url( $f['code'] ) ) {
				$h .= '<p>' . esc_html__( 'Verification address:', 'equalweb' )
					. ' <code>' . esc_html( EQWPDF_Registration::verify_url() ) . '</code></p>';
			} elseif ( 'outbound_blocked' === $f['code'] ) {
				$h .= '<p>' . esc_html__( 'EqualWeb service address:', 'equalweb' )
					. ' <code>' . esc_html( EQWPDF_Registration::service_host() ) . '</code></p>';
			}
			// A refused token is no registration attempt, and its what-to-do line
			// already says to click Try again.
			if ( 'token_invalid' !== $f['code'] ) {
				$next = EQWPDF_Registration::next_auto_at( $f );
				$h   .= '<p class="description">'
					/* translators: %s: date and time of the last registration attempt. */
					. esc_html( sprintf( __( 'Last attempt: %s.', 'equalweb' ), ! empty( $f['at'] ) ? EQWPDF_Registration::format_time( (int) $f['at'] ) : '-' ) )
					. ' ' . esc_html( null === $next
						? __( 'There is no automatic attempt for this reason. Click Try again once it is fixed.', 'equalweb' )
						/* translators: %s: date and time after which the next automatic attempt runs. */
						: sprintf( __( 'The next automatic attempt runs when an administrator opens this page after %s.', 'equalweb' ), EQWPDF_Registration::format_time( max( $next, time() ) ) ) )
					. '</p>';
			}
		}
		$h .= '<p class="eqw-regnotice-actions">'
			. '<button type="button" class="button button-primary" id="eqw-register">'
			. esc_html__( 'Try again', 'equalweb' ) . '</button>'
			. ' <span class="eqw-regnotice-busy" hidden><span class="spinner is-active eqw-spinner" aria-hidden="true"></span>'
			. esc_html__( 'Registering this site with the EqualWeb check service. This can take up to 30 seconds.', 'equalweb' ) . '</span></p>'
			. '<p class="eqw-msg-error eqw-regnotice-error" hidden></p>';
		if ( null !== $f ) {
			$h .= '<p><a href="' . esc_url( self::SUPPORT_URL ) . '" target="_blank" rel="noopener">'
				. esc_html__( 'Contact EqualWeb support', 'equalweb' )
				. '<span class="screen-reader-text"> (' . esc_html__( 'opens in a new tab', 'equalweb' ) . ')</span>'
				. self::icon( 'external' ) . '</a></p>'
				. '<details class="eqw-regdetails"><summary>' . esc_html__( 'Technical details', 'equalweb' )
				. '</summary><p class="description">' . esc_html__( 'Include these lines when you contact EqualWeb support.', 'equalweb' ) . '</p>'
				. '<pre tabindex="0">' . esc_html( EQWPDF_Registration::details( $f ) ) . '</pre></details>';
		}
		return $h . '</div>';
	}

	/**
	 * The heading of the registration box. Try again moves focus to it.
	 *
	 * @param string $text Heading text (unescaped).
	 * @return string
	 */
	private static function registration_title( $text ) {
		return '<h2 class="eqw-regnotice-title" id="eqw-regnotice-title" tabindex="-1">' . esc_html( $text ) . '</h2>';
	}

	/* ------------------------------------------------------------------ */

	/** Whether the current render should include multi-select controls. */
	private static $bulk_enabled = false;

	/**
	 * The supported document attachments (PDF + Word/PowerPoint/Excel) the
	 * current user may see. Users without the all-files capability are scoped
	 * to their own uploads. The files list AND analytics share this query so
	 * both always agree (no count/score leak through the analytics tab).
	 */
	private static function query_pdfs() {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => array_keys( self::DOC_MIMES ),
			'post_status'    => 'inherit',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! current_user_can( EQW_Capabilities::CAP_VIEW_ALL ) ) {
			$args['author'] = get_current_user_id();
		}
		return get_posts( $args );
	}

	/**
	 * Same scope as query_pdfs(), callable from the AJAX layer (classification
	 * actions validate locations/ignores against the caller's real file set).
	 *
	 * @return WP_Post[]
	 */
	public static function all_doc_posts() {
		return self::query_pdfs();
	}

	/** Sub-nav inside the Compliance tab. Classification is admin-only. */
	private static function compliance_nav( $current ) {
		$analytics_url      = admin_url( 'admin.php?page=' . self::SLUG . '&tab=analytics' );
		$classification_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=classification' );
		echo '<div class="eqw-subtabs" role="navigation" aria-label="' . esc_attr__( 'Compliance', 'equalweb' ) . '">';
		echo '<a class="eqw-subtab' . ( 'analytics' === $current ? ' eqw-subtab-active' : '' ) . '" href="' . esc_url( $analytics_url ) . '">' . esc_html__( 'Analytics', 'equalweb' ) . '</a>';
		if ( current_user_can( 'manage_options' ) ) {
			echo '<a class="eqw-subtab' . ( 'classification' === $current ? ' eqw-subtab-active' : '' ) . '" href="' . esc_url( $classification_url ) . '">' . esc_html__( 'Classification', 'equalweb' ) . '</a>';
		}
		echo '</div>';
	}

	/**
	 * The category panel above the file table: one clickable bar per category
	 * (click filters the table), the never-a-legal-determination note, the
	 * ignored-count restore link and, for admins, the needs-input pointer to
	 * the Classification screen. Ignored files still count in analytics but are
	 * OUT of this visible inventory — the "Ignored: N — restore" line (and
	 * analytics) is what keeps them from disappearing without a trace.
	 *
	 * @param array $cls           classify_all() output.
	 * @param int   $ignored_count Number of ignored documents.
	 */
	private static function render_category_panel( $cls, $ignored_count ) {
		$cats   = EQW_Classification::cats();
		$counts = array_fill_keys( array_keys( $cats ), 0 );
		foreach ( $cls as $c ) {
			if ( ! empty( $c['ignored'] ) ) {
				continue;
			}
			$key = isset( $c['cat'] ) && isset( $counts[ $c['cat'] ] ) ? $c['cat'] : 'internal';
			$counts[ $key ]++;
		}
		$max      = max( 1, max( $counts ) );
		$is_admin = current_user_can( 'manage_options' );
		echo '<div class="eqw-catpanel"><h3>' . esc_html__( 'Inventory by exposure & use', 'equalweb' ) . '</h3>';
		foreach ( $cats as $key => $meta ) {
			$count = (int) $counts[ $key ];
			$width = max( 3, (int) round( 100 * $count / $max ) );
			echo '<button type="button" class="eqw-catrow" data-cat="' . esc_attr( $key ) . '" aria-pressed="false" title="'
				. esc_attr( sprintf( /* translators: %s: category label */ __( 'Filter the list to %s', 'equalweb' ), $meta['label'] ) ) . '">'
				. '<span class="eqw-catname"><span class="eqw-catsw" style="background:' . esc_attr( $meta['color'] ) . '"></span>'
				. esc_html( $meta['label'] ) . '</span>'
				. '<span class="eqw-cattrack"><i style="width:' . (int) $width . '%;background:' . esc_attr( $meta['color'] ) . '"></i>'
				. '<b>' . (int) $count . '</b></span>'
				. '<span class="eqw-catflag">' . esc_html( $meta['flag'] ) . '</span></button>';
		}
		echo '<p class="eqw-catnote"><span>' . esc_html__( 'Priority is guidance for what to fix first — never a legal determination.', 'equalweb' ) . '</span>';
		if ( $is_admin ) {
			echo '<span class="eqw-catnote-actions">';
			if ( $ignored_count ) {
				echo '<button type="button" class="eqw-linklike" id="eqw-cls-unignore">'
					. esc_html( sprintf( /* translators: %d: number of ignored documents */ __( 'Ignored: %d — restore all', 'equalweb' ), (int) $ignored_count ) )
					. '</button> · ';
			}
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=classification' ) ) . '">'
				. esc_html__( 'Tag locations in Compliance → Classification', 'equalweb' ) . '</a></span>';
		}
		echo '</p></div>';
	}

	/**
	 * Classification screen: explainer, coverage card, the opt-in auto-archive
	 * age threshold, then the location rows (untagged first, biggest first,
	 * with one-click suggestions). Tag a location once — every file under it
	 * inherits; a deeper tag or a per-file tag overrides it.
	 */
	private static function render_classification() {
		$pdfs = self::query_pdfs();
		if ( ! $pdfs ) {
			echo '<p>' . esc_html__( 'No supported documents were found in the media library.', 'equalweb' ) . '</p>';
			return;
		}
		$cls  = EQW_Classification::classify_all( $pdfs );
		$locs = EQW_Classification::locations( $pdfs, $cls );

		$active = array_filter( $cls, function ( $c ) {
			return empty( $c['ignored'] );
		} );
		$n_user = 0;
		foreach ( $active as $c ) {
			if ( 'user' === $c['src'] ) {
				$n_user++;
			}
		}
		$n_auto   = count( $active ) - $n_user;
		$tot      = max( 1, count( $active ) );
		$pc_auto  = (int) round( 100 * $n_auto / $tot );
		$pc_user  = (int) round( 100 * $n_user / $tot );
		$untagged = 0;
		foreach ( $locs as $l ) {
			if ( ! $l['tag'] ) {
				$untagged++;
			}
		}
		$years = EQW_Classification::archive_years();
		?>
		<div class="eqw-cls">
			<div class="eqw-explain">
				<h2><?php esc_html_e( 'Classification', 'equalweb' ); ?></h2>
				<p><strong><?php esc_html_e( 'What this does:', 'equalweb' ); ?></strong>
					<?php esc_html_e( 'tags every document by exposure and use — automatically where the site already knows (referenced by published content, untouched archives), and by asking you where only you know (which folders hold public forms or service documents). Tags set each document\'s remediation priority. Tag a folder once — everything inside inherits it.', 'equalweb' ); ?>
					<strong><?php esc_html_e( 'Nothing is ever excluded automatically:', 'equalweb' ); ?></strong>
					<?php esc_html_e( 'lower priority never means "exempt".', 'equalweb' ); ?></p>
			</div>

			<div class="eqw-covcard">
				<div><div class="eqw-covnum">100%</div><div class="eqw-tile-label"><?php esc_html_e( 'classified', 'equalweb' ); ?></div></div>
				<div class="eqw-covmain">
					<div class="eqw-covbar">
						<i style="width:<?php echo (int) $pc_auto; ?>%;background:#4a63c8" title="<?php esc_attr_e( 'Automatic rules', 'equalweb' ); ?>"></i>
						<i style="width:<?php echo (int) $pc_user; ?>%;background:#0d9668" title="<?php esc_attr_e( 'Tagged by an administrator', 'equalweb' ); ?>"></i>
					</div>
					<div class="eqw-covleg">
						<span><span class="eqw-catsw" style="background:#4a63c8"></span><?php echo esc_html( sprintf( /* translators: %d: percent */ __( '🤖 Automatic %d%%', 'equalweb' ), (int) $pc_auto ) ); ?></span>
						<span><span class="eqw-catsw" style="background:#0d9668"></span><?php echo esc_html( sprintf( /* translators: %d: percent */ __( '👤 Tagged by you %d%%', 'equalweb' ), (int) $pc_user ) ); ?></span>
						<span><?php echo esc_html( sprintf( /* translators: %d: number of locations */ __( '⚪ %d location(s) awaiting your input', 'equalweb' ), (int) $untagged ) ); ?></span>
					</div>
				</div>
				<div class="eqw-covhint"><?php esc_html_e( 'Tag a folder once — everything inside inherits. Untagged locations are listed first, biggest first.', 'equalweb' ); ?></div>
			</div>

			<?php // Ships ON at 2 years (0 = off); the customer can change it. Age never overrides active use. ?>
			<div class="eqw-cls-settings">
				<label for="eqw-cls-archive-years"><strong><?php esc_html_e( 'Auto-archive by age:', 'equalweb' ); ?></strong>
					<?php esc_html_e( 'treat files with no published links as Archive when uploaded more than', 'equalweb' ); ?></label>
				<select id="eqw-cls-archive-years">
					<option value="0" <?php selected( 0, $years ); ?>><?php esc_html_e( 'Off - no age rule', 'equalweb' ); ?></option>
					<?php foreach ( array( 1, 2, 3, 5, 7, 10, 15 ) as $y ) : ?>
						<option value="<?php echo (int) $y; ?>" <?php selected( $y, $years ); ?>><?php
							/* translators: %d: number of years */
							echo esc_html( sprintf( _n( '%d year ago', '%d years ago', $y, 'equalweb' ), $y ) . ( 2 === $y ? ' (default)' : '' ) );
						?></option>
					<?php endforeach; ?>
				</select>
				<span><?php esc_html_e( 'Off means age is never used — only folders named like archives (/archive/, /old/) and your own tags classify as Archive. Files referenced by published content are never auto-archived, at any age.', 'equalweb' ); ?></span>
			</div>

			<div class="eqw-cls-list">
				<?php foreach ( $locs as $loc ) : self::render_location_row( $loc ); endforeach; ?>
			</div>

			<p class="eqw-chart-note"><?php esc_html_e( 'Automatic tags come from the rules (referenced-by-published-content, archive paths, file age) and can always be overridden — your tag wins and is recorded with who and when. "Possibly exempt" locations stay in every list and stay checkable — confirm with counsel before treating anything as out of scope.', 'equalweb' ); ?></p>
		</div>
		<?php
	}

	/** One location row on the Classification screen. */
	private static function render_location_row( $loc ) {
		$cats = EQW_Classification::cats();
		$tag  = $loc['tag'];
		?>
		<div class="eqw-clsrow" data-prefix="<?php echo esc_attr( $loc['prefix'] ); ?>">
			<span class="eqw-clsloc">
				<b><?php echo esc_html( $loc['label'] ); ?></b>
				<small><?php
					echo esc_html( sprintf( /* translators: %d: document count */ _n( '%d document', '%d documents', (int) $loc['count'], 'equalweb' ), (int) $loc['count'] ) );
					if ( ! empty( $loc['ignored'] ) ) {
						echo ' · ' . esc_html( sprintf( /* translators: %d: ignored count */ __( '%d ignored', 'equalweb' ), (int) $loc['ignored'] ) );
					}
				?></small>
				<?php foreach ( ( isset( $loc['children'] ) ? $loc['children'] : array() ) as $child_prefix => $child_tag ) : ?>
					<span class="eqw-cls-child">↳ <?php echo esc_html( EQW_Classification::location_label( $child_prefix ) ); ?>
						— <?php echo esc_html( isset( $cats[ $child_tag['cat'] ] ) ? $cats[ $child_tag['cat'] ]['label'] : $child_tag['cat'] ); ?>
						<?php esc_html_e( '(deeper tag overrides)', 'equalweb' ); ?></span>
				<?php endforeach; ?>
			</span>
			<?php if ( $tag ) : ?>
				<span class="eqw-srcchip eqw-srcchip-user">👤 <?php echo esc_html( isset( $cats[ $tag['cat'] ] ) ? $cats[ $tag['cat'] ]['label'] : $tag['cat'] ); ?></span>
				<span class="eqw-clsact">
					<span class="eqw-sugg-line"><?php
						$by = isset( $tag['by'] ) && '' !== $tag['by'] ? $tag['by'] : __( 'an administrator', 'equalweb' );
						echo esc_html( sprintf( /* translators: %s: administrator name */ __( 'Tagged by %s', 'equalweb' ), $by ) );
						if ( ! empty( $tag['at'] ) ) {
							echo ' ' . esc_html( sprintf( /* translators: %s: date */ __( 'on %s', 'equalweb' ), date_i18n( 'M j, Y', (int) $tag['at'] ) ) );
						}
						echo ' ' . esc_html__( '— overrides auto rules', 'equalweb' );
					?></span>
					<button type="button" class="button eqw-cls-clear"><?php esc_html_e( 'Remove tag', 'equalweb' ); ?></button>
				</span>
			<?php else : ?>
				<span class="eqw-srcchip eqw-srcchip-open"><?php esc_html_e( '⚪ Untagged — automatic rules apply', 'equalweb' ); ?></span>
				<span class="eqw-clsact">
					<?php if ( ! empty( $loc['suggestion'] ) ) : $sc = $loc['suggestion']['cat']; ?>
						<span class="eqw-sugg-line"><?php esc_html_e( 'Suggestion:', 'equalweb' ); ?>
							<b><?php echo esc_html( $cats[ $sc ]['label'] ); ?></b> — <?php echo esc_html( $loc['suggestion']['why'] ); ?></span>
						<button type="button" class="button button-primary eqw-cls-accept" data-cat="<?php echo esc_attr( $sc ); ?>">✓ <?php esc_html_e( 'Accept', 'equalweb' ); ?></button>
					<?php endif; ?>
					<select class="eqw-cls-select" aria-label="<?php echo esc_attr( sprintf( /* translators: %s: location label */ __( 'Tag %s', 'equalweb' ), $loc['label'] ) ); ?>">
						<option value=""><?php esc_html_e( 'Choose a tag…', 'equalweb' ); ?></option>
						<?php foreach ( $cats as $key => $meta ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $meta['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_files() {
		// Multi-select controls appear only when the user can actually run a
		// bulk action: check needs the capability alone; remediation also
		// needs the site-wide personal API key.
		self::$bulk_enabled = current_user_can( EQW_Capabilities::CAP_CHECK )
			|| ( current_user_can( EQW_Capabilities::CAP_REMEDIATE ) && EQW_API_Client::get_personal_key() );

		$pdfs = self::query_pdfs();

		if ( ! $pdfs ) {
			echo '<p>' . esc_html__( 'No supported documents were found in the media library.', 'equalweb' ) . '</p>';
			return;
		}

		// Classify everything once (batched), then hide ignored rows from the
		// LIST only — the category panel still counts them and offers restore.
		$cls           = EQW_Classification::classify_all( $pdfs );
		$ignored_count = 0;
		foreach ( $cls as $c ) {
			if ( ! empty( $c['ignored'] ) ) {
				$ignored_count++;
			}
		}
		$pdfs = array_values( array_filter( $pdfs, function ( $pdf ) use ( $cls ) {
			return empty( $cls[ $pdf->ID ]['ignored'] );
		} ) );
		if ( ! $pdfs ) {
			self::render_category_panel( $cls, $ignored_count );
			echo '<p>' . esc_html__( 'All documents on this site are currently ignored. Restore them from the panel above.', 'equalweb' ) . '</p>';
			return;
		}

		$total_pages = 0;
		$known_pages = true;
		$type_counts = array( 'pdf' => 0, 'word' => 0, 'ppt' => 0, 'xls' => 0 );
		foreach ( $pdfs as $pdf ) {
			$type_counts[ self::doctype( get_post_mime_type( $pdf->ID ) ) ]++;
			// Page counts come from PDF metadata — office files have no local
			// page count (and running the PDF regexes on office binaries is
			// pointless).
			if ( 'pdf' !== self::doctype( get_post_mime_type( $pdf->ID ) ) ) {
				$known_pages = false;
				continue;
			}
			$pages = self::get_page_count( $pdf->ID );
			if ( null === $pages ) {
				$known_pages = false;
			} else {
				$total_pages += $pages;
			}
		}

		// All rows are rendered; search / filter / sort / pagination run
		// client-side in admin.js — instant, no page reload.
		?>
		<div class="eqw-typepills" role="group" aria-label="<?php esc_attr_e( 'Filter by document type', 'equalweb' ); ?>">
			<?php
			$pills = array( '' => __( 'All', 'equalweb' ), 'pdf' => __( 'PDF', 'equalweb' ), 'word' => __( 'Word', 'equalweb' ), 'ppt' => __( 'PowerPoint', 'equalweb' ), 'xls' => __( 'Excel', 'equalweb' ) );
			foreach ( $pills as $type => $label ) {
				$count = '' === $type ? count( $pdfs ) : $type_counts[ $type ];
				// The pill label already names the type — its icon is decorative.
				$icon = '' === $type ? '' : self::type_icon( $type, true );
				echo '<button type="button" class="eqw-pill' . ( '' === $type ? ' eqw-pill-active' : '' )
					. '" data-doctype="' . esc_attr( $type ) . '" aria-pressed="' . ( '' === $type ? 'true' : 'false' ) . '">'
					. $icon . esc_html( $label ) . ' <span class="eqw-pill-count">' . (int) $count . '</span></button>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $icon is plugin-built SVG.
			}
			?>
		</div>
		<?php self::render_category_panel( $cls, $ignored_count ); ?>
		<div class="eqw-toolbar">
			<p class="eqw-totals">
				<?php
				printf(
					/* translators: 1: number of files, 2: total pages */
					esc_html__( '%1$s documents · %2$s pages in total', 'equalweb' ),
					'<strong>' . count( $pdfs ) . '</strong>',
					'<strong>' . esc_html( $total_pages . ( $known_pages ? '' : '+' ) ) . '</strong>'
				);
				if ( current_user_can( EQW_Capabilities::CAP_REMEDIATE ) ) {
					echo ' <span class="description">' . esc_html__( '(1 credit ≈ 1 page when remediating)', 'equalweb' ) . '</span>';
				}
				if ( current_user_can( EQW_Capabilities::CAP_CHECK ) ) {
					echo ' <span class="description">' . esc_html__( 'Free checks share a site-wide daily quota.', 'equalweb' ) . '</span>';
				}
				?>
			</p>
			<div class="eqw-filters">
				<input type="search" id="eqw-q" placeholder="<?php esc_attr_e( 'Search documents…', 'equalweb' ); ?>"
					aria-label="<?php esc_attr_e( 'Search documents', 'equalweb' ); ?>">
				<select id="eqw-filter" aria-label="<?php esc_attr_e( 'Filter', 'equalweb' ); ?>">
					<option value=""><?php esc_html_e( 'All files', 'equalweb' ); ?></option>
					<optgroup label="<?php esc_attr_e( 'Status', 'equalweb' ); ?>">
						<option value="unchecked"><?php esc_html_e( 'Not checked', 'equalweb' ); ?></option>
						<option value="checked"><?php esc_html_e( 'Checked, not remediated', 'equalweb' ); ?></option>
						<option value="in_progress"><?php esc_html_e( 'In progress', 'equalweb' ); ?></option>
						<option value="ready"><?php esc_html_e( 'Ready to apply', 'equalweb' ); ?></option>
						<option value="remediated"><?php esc_html_e( 'Remediated', 'equalweb' ); ?></option>
						<option value="not_remediated"><?php esc_html_e( 'Not remediated', 'equalweb' ); ?></option>
					</optgroup>
					<optgroup label="<?php esc_attr_e( 'File replacement', 'equalweb' ); ?>">
						<option value="replaced"><?php esc_html_e( 'Replaced', 'equalweb' ); ?></option>
						<option value="not_replaced"><?php esc_html_e( 'Not replaced', 'equalweb' ); ?></option>
					</optgroup>
					<optgroup label="<?php esc_attr_e( 'Classification', 'equalweb' ); ?>">
						<?php foreach ( EQW_Classification::cats() as $cls_key => $cls_meta ) : ?>
							<option value="cls_<?php echo esc_attr( $cls_key ); ?>"><?php echo esc_html( $cls_meta['label'] ); ?></option>
						<?php endforeach; ?>
					</optgroup>
				</select>
				<select id="eqw-sort" aria-label="<?php esc_attr_e( 'Sort', 'equalweb' ); ?>">
					<option value="newest"><?php esc_html_e( 'Newest first', 'equalweb' ); ?></option>
					<option value="prio_desc"><?php esc_html_e( 'Priority: high → low', 'equalweb' ); ?></option>
					<option value="score_asc"><?php esc_html_e( 'Score: low → high', 'equalweb' ); ?></option>
					<option value="score_desc"><?php esc_html_e( 'Score: high → low', 'equalweb' ); ?></option>
				</select>
			</div>
		</div>
		<?php if ( self::$bulk_enabled ) : ?>
		<?php // Outcome of the last bulk click. Filled by the JS, above the table. 'inline' keeps core's common.js from moving it to the top of the page. ?>
		<div id="eqw-bulk-notice" class="eqw-bulk-notice notice notice-info inline" role="status" hidden></div>
		<div class="eqw-bulkbar">
			<span id="eqw-selected-count" class="eqw-selected-count"></span>
			<?php if ( current_user_can( EQW_Capabilities::CAP_CHECK ) ) : ?>
			<button type="button" class="button" id="eqw-bulk-check" disabled><?php esc_html_e( 'PDF Checker (selected)', 'equalweb' ); ?></button>
			<?php endif; ?>
			<?php if ( current_user_can( EQW_Capabilities::CAP_REMEDIATE ) ) : ?>
			<button type="button" class="button button-primary" id="eqw-bulk-remediate" disabled><?php esc_html_e( 'Auto Remediation (selected)', 'equalweb' ); ?></button>
			<?php endif; ?>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<button type="button" class="button" id="eqw-bulk-tag" disabled><?php esc_html_e( 'Change classification', 'equalweb' ); ?></button>
			<button type="button" class="button" id="eqw-bulk-ignore" disabled><?php esc_html_e( 'Ignore', 'equalweb' ); ?></button>
			<?php endif; ?>
		</div>
		<?php endif; ?>
		<?php // .eqw-files scopes the behavioural JS (renderRow, search, bulk,
		// data-action) to THIS table only — decorative tables elsewhere keep
		// .eqw-table for styling without being mangled by renderRow. ?>
		<table class="wp-list-table widefat fixed striped eqw-table eqw-files">
			<thead>
			<tr>
				<?php if ( self::$bulk_enabled ) : ?>
				<th class="eqw-col-cb"><input type="checkbox" id="eqw-check-all"
					aria-label="<?php esc_attr_e( 'Select all files', 'equalweb' ); ?>"></th>
				<?php endif; ?>
				<th class="eqw-col-file"><?php esc_html_e( 'File', 'equalweb' ); ?></th>
				<th class="eqw-col-size"><?php esc_html_e( 'Size', 'equalweb' ); ?></th>
				<th class="eqw-col-pages"><?php esc_html_e( 'Pages', 'equalweb' ); ?></th>
				<th class="eqw-col-lang"><?php esc_html_e( 'Language', 'equalweb' ); ?></th>
				<th class="eqw-col-date"><?php esc_html_e( 'Uploaded', 'equalweb' ); ?></th>
				<th class="eqw-col-cat"><?php esc_html_e( 'Category', 'equalweb' ); ?></th>
				<th class="eqw-col-prio"><?php esc_html_e( 'Priority', 'equalweb' ); ?></th>
				<th class="eqw-col-score"><?php esc_html_e( 'Score before', 'equalweb' ); ?></th>
				<th class="eqw-col-score"><?php esc_html_e( 'Score after', 'equalweb' ); ?></th>
				<th class="eqw-col-status"><?php esc_html_e( 'Status', 'equalweb' ); ?></th>
				<th class="eqw-col-actions"><?php esc_html_e( 'Actions', 'equalweb' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php foreach ( $pdfs as $pdf ) : self::render_row( $pdf, isset( $cls[ $pdf->ID ] ) ? $cls[ $pdf->ID ] : array() ); endforeach; ?>
			</tbody>
		</table>
		<p id="eqw-no-match" hidden><?php esc_html_e( 'No documents match the current search or filter.', 'equalweb' ); ?></p>
		<div class="eqw-pager" id="eqw-pager"></div>
		<?php
	}

	private static function render_row( $pdf, $cls = array() ) {
		$file    = get_attached_file( $pdf->ID );
		$size    = $file && file_exists( $file ) ? size_format( filesize( $file ) ) : '—';
		$state   = get_post_meta( $pdf->ID, EQW_Ajax::META_STATE, true );
		$summary = get_post_meta( $pdf->ID, EQW_Ajax::META_SUMMARY, true );
		$after   = get_post_meta( $pdf->ID, '_eqw_summary_after', true );
		$backup  = get_post_meta( $pdf->ID, EQW_File_Manager::META_BACKUP, true );
		$doctype = self::doctype( get_post_mime_type( $pdf->ID ) );
		// Title/language come from PDF metadata — never run the PDF extractors on
		// office binaries. (Pages are safe for every doctype: get_page_count()
		// makes the same distinction internally and returns the API-reported
		// count a poll cached for an office row.)
		$title   = 'pdf' === $doctype ? self::get_doc_title( $pdf->ID ) : '';
		$doc_id     = get_post_meta( $pdf->ID, EQW_Ajax::META_DOC_ID, true );
		$rem_doc_id = get_post_meta( $pdf->ID, EQW_Ajax::META_REM_DOC_ID, true );
		$new_att    = (int) get_post_meta( $pdf->ID, '_eqw_new_attachment', true );
		$new_url    = $new_att ? wp_get_attachment_url( $new_att ) : '';

		// Classification (category + priority). The chip's title carries the
		// matched rules — the "why" behind the rank.
		$cats     = EQW_Classification::cats();
		$cat      = isset( $cls['cat'] ) && isset( $cats[ $cls['cat'] ] ) ? $cls['cat'] : 'internal';
		$cat_meta = $cats[ $cat ];
		$prio     = (int) ( isset( $cls['prio'] ) ? $cls['prio'] : $cat_meta['prio'] );
		$cls_src  = isset( $cls['src'] ) ? $cls['src'] : 'auto';
		$cls_rules = implode( ' · ', array_map( 'strval', isset( $cls['rules'] ) && is_array( $cls['rules'] ) ? $cls['rules'] : array() ) );
		$src_mark  = 'user' === $cls_src ? '👤' : '🤖';
		$prio_color = $prio >= 85 ? '#a12727' : ( $prio >= 60 ? '#9a4b00' : '#787c82' );
		?>
		<tr data-id="<?php echo esc_attr( $pdf->ID ); ?>"
			data-doctype="<?php echo esc_attr( $doctype ); ?>"
			data-search="<?php echo esc_attr( mb_strtolower( ( $title ? $title : get_the_title( $pdf->ID ) ) . ' ' . basename( (string) $file ) ) ); ?>"
			data-state="<?php echo esc_attr( $state ? $state : 'none' ); ?>"
			data-score="<?php echo esc_attr( is_array( $summary ) && isset( $summary['score'] ) ? $summary['score'] : '' ); ?>"
			data-score-after="<?php echo esc_attr( is_array( $after ) && isset( $after['score'] ) ? $after['score'] : '' ); ?>"
			data-report-url="<?php echo esc_url( $doc_id ? EQW_API_Client::report_url( $doc_id ) : '' ); ?>"
			data-report-url-after="<?php echo esc_url( $rem_doc_id ? EQW_API_Client::report_url( $rem_doc_id ) : '' ); ?>"
			data-new-url="<?php echo esc_url( $new_url ); ?>"
			data-has-backup="<?php echo $backup ? '1' : '0'; ?>"
			data-cat="<?php echo esc_attr( $cat ); ?>"
			data-prio="<?php echo esc_attr( $prio ); ?>"
			data-cls-src="<?php echo esc_attr( $cls_src ); ?>"
			data-cls-rules="<?php echo esc_attr( $cls_rules ); ?>">
			<?php if ( self::$bulk_enabled ) : ?>
			<td class="eqw-cb-cell"><input type="checkbox" class="eqw-row-cb"
				aria-label="<?php esc_attr_e( 'Select file', 'equalweb' ); ?>"></td>
			<?php endif; ?>
			<td class="eqw-col-file">
				<?php
				// The icon must stay a SIBLING of the link — js updateFileMeta()
				// targets '.eqw-col-file > a' and rewrites the link's text nodes
				// in place.
				echo self::type_icon( $doctype ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-built SVG.
				?>
				<a href="<?php echo esc_url( wp_get_attachment_url( $pdf->ID ) ); ?>" target="_blank" rel="noopener">
					<?php echo esc_html( $title ? $title : get_the_title( $pdf->ID ) ); ?>
				</a>
				<div class="eqw-filename"><?php echo esc_html( basename( (string) $file ) ); ?></div>
			</td>
			<td><?php echo esc_html( $size ); ?></td>
			<td class="eqw-num"><?php
				$pages = self::get_page_count( $pdf->ID );
				echo esc_html( null === $pages ? '—' : $pages );
			?></td>
			<td class="eqw-num eqw-lang-cell"><?php
				$lang = 'pdf' === $doctype ? self::get_doc_lang( $pdf->ID ) : '';
				echo esc_html( $lang ? $lang : '—' );
			?></td>
			<td class="eqw-num"><?php
				// English-only UI: fixed format, no locale translation.
				echo esc_html( mysql2date( 'M j, Y', $pdf->post_date, false ) );
			?></td>
			<td class="eqw-cat-cell">
				<span class="eqw-tagchip" title="<?php echo esc_attr( $cls_rules ); ?>">
					<span class="eqw-catsw" style="background:<?php echo esc_attr( $cat_meta['color'] ); ?>"></span>
					<?php echo esc_html( $cat_meta['label'] ); ?> <span aria-hidden="true"><?php echo esc_html( $src_mark ); ?></span>
				</span>
			</td>
			<td class="eqw-prio-cell">
				<span class="eqw-prio">
					<b style="color:<?php echo esc_attr( $prio_color ); ?>"><?php echo (int) $prio; ?></b>
					<span class="eqw-prio-meter"><i style="width:<?php echo (int) $prio; ?>%;background:<?php echo esc_attr( $prio_color ); ?>"></i></span>
				</span>
			</td>
			<td class="eqw-before-cell"></td>
			<td class="eqw-after-cell"></td>
			<td class="eqw-status-cell"></td>
			<td class="eqw-actions-cell"></td>
		</tr>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Analytics                                                           */
	/* ------------------------------------------------------------------ */

	private static function render_analytics() {
		// Same scoped query as the files list — an own-files user must not be
		// able to infer counts/scores of other users' PDFs from the KPIs.
		$pdfs = self::query_pdfs();

		if ( ! $pdfs ) {
			echo '<p>' . esc_html__( 'No supported documents were found in the media library.', 'equalweb' ) . '</p>';
			return;
		}

		// ---- Aggregate ----
		$total       = count( $pdfs );
		$pages_total = 0;
		$status      = array( 'none' => 0, 'checked' => 0, 'in_progress' => 0, 'ready' => 0, 'remediated' => 0 );
		$replaced    = 0;
		$buckets     = array( '0-49' => 0, '50-69' => 0, '70-89' => 0, '90-100' => 0 );
		$scored      = 0;
		$before_sum  = 0;
		$before_n    = 0;
		$pair_before = 0;
		$pair_after  = 0;
		$pair_n      = 0;
		$pages_rem   = 0;
		$fail_before = 0;
		$fail_after  = 0;
		$by_user     = array();

		foreach ( $pdfs as $pdf ) {
			// get_page_count() never parses office binaries — for office rows it
			// only returns the API-reported count cached by a poll (or null).
			// Local page totals count PDFs only, mirroring the files tab; office
			// rows without scores naturally fall out of every score aggregate
			// below (both summaries stay null until remediation backfills them).
			$pages = self::get_page_count( $pdf->ID );
			if ( 'pdf' === self::doctype( get_post_mime_type( $pdf->ID ) ) ) {
				$pages_total += (int) $pages;
			}

			$state = get_post_meta( $pdf->ID, EQW_Ajax::META_STATE, true );
			$key   = 'none';
			if ( 'checked' === $state ) {
				$key = 'checked';
			} elseif ( 'checking' === $state || 'remediating' === $state ) {
				$key = 'in_progress';
			} elseif ( 'remediated_ready' === $state ) {
				$key = 'ready';
			} elseif ( 'remediated' === $state ) {
				$key = 'remediated';
			}
			$status[ $key ]++;

			if ( get_post_meta( $pdf->ID, EQW_File_Manager::META_BACKUP, true ) ) {
				$replaced++;
			}

			$summary = get_post_meta( $pdf->ID, EQW_Ajax::META_SUMMARY, true );
			$after   = get_post_meta( $pdf->ID, '_eqw_summary_after', true );
			$before  = is_array( $summary ) && isset( $summary['score'] ) ? (int) $summary['score'] : null;
			$post    = is_array( $after ) && isset( $after['score'] ) ? (int) $after['score'] : null;

			if ( null !== $before ) {
				$before_sum += $before;
				$before_n++;
			}
			$current = null !== $post ? $post : $before;
			if ( null !== $current ) {
				$scored++;
				if ( $current < 50 ) {
					$buckets['0-49']++;
				} elseif ( $current < 70 ) {
					$buckets['50-69']++;
				} elseif ( $current < 90 ) {
					$buckets['70-89']++;
				} else {
					$buckets['90-100']++;
				}
			}
			if ( null !== $before && null !== $post ) {
				$pair_before += $before;
				$pair_after  += $post;
				$pair_n++;
				$pages_rem   += (int) $pages;
				$fail_before += is_array( $summary['issues'] ) && isset( $summary['issues']['failed'] ) ? (int) $summary['issues']['failed'] : 0;
				$fail_after  += is_array( $after['issues'] ) && isset( $after['issues']['failed'] ) ? (int) $after['issues']['failed'] : 0;
			}

			// Activity by user: attribute checks/remediations to the initiator
			// recorded at start time (checked_by / remediated_by). Rows processed
			// before attribution shipped have no initiator and are skipped here.
			$checked_by = get_post_meta( $pdf->ID, EQW_Ajax::META_CHECKED_BY, true );
			if ( '' !== $checked_by ) {
				$uid_by  = (int) $checked_by;
				$by_name = get_post_meta( $pdf->ID, EQW_Ajax::META_CHECKED_BY_NAME, true );
				$by_user[ $uid_by ]['name']   = '' !== $by_name ? $by_name : 'User ' . $uid_by;
				$by_user[ $uid_by ]['checks'] = ( isset( $by_user[ $uid_by ]['checks'] ) ? $by_user[ $uid_by ]['checks'] : 0 ) + 1;
			}
			$remediated_by = get_post_meta( $pdf->ID, EQW_Ajax::META_REMEDIATED_BY, true );
			if ( '' !== $remediated_by ) {
				$uid_by  = (int) $remediated_by;
				$by_name = get_post_meta( $pdf->ID, EQW_Ajax::META_REMEDIATED_BY_NAME, true );
				$by_user[ $uid_by ]['name'] = '' !== $by_name ? $by_name : 'User ' . $uid_by;
				$by_user[ $uid_by ]['rems'] = ( isset( $by_user[ $uid_by ]['rems'] ) ? $by_user[ $uid_by ]['rems'] : 0 ) + 1;
			}
		}

		$checked_any   = $total - $status['none'];
		$avg_before    = $before_n ? round( $before_sum / $before_n ) : null;
		$avg_pair_b    = $pair_n ? round( $pair_before / $pair_n ) : null;
		$avg_pair_a    = $pair_n ? round( $pair_after / $pair_n ) : null;
		$fixed         = max( 0, $fail_before - $fail_after );
		$fix_rate      = $fail_before ? round( 100 * $fixed / $fail_before ) : null;
		$status_labels = array(
			'none'        => array( __( 'Not checked', 'equalweb' ), '#a7aaad' ),
			'checked'     => array( __( 'Checked, not remediated', 'equalweb' ), '#9a4b00' ),
			'in_progress' => array( __( 'In progress', 'equalweb' ), '#4a63c8' ),
			'ready'       => array( __( 'Ready to apply', 'equalweb' ), '#7a52c7' ),
			'remediated'  => array( __( 'Remediated', 'equalweb' ), '#0d9668' ),
		);
		$bucket_colors = array( '0-49' => '#a12727', '50-69' => '#a12727', '70-89' => '#9a4b00', '90-100' => '#00701c' );

		$tile = function ( $value, $label, $sub = '' ) {
			echo '<div class="eqw-tile"><div class="eqw-tile-value">' . wp_kses_post( $value ) . '</div>';
			echo '<div class="eqw-tile-label">' . esc_html( $label ) . '</div>';
			if ( $sub ) {
				echo '<div class="eqw-tile-sub">' . wp_kses_post( $sub ) . '</div>';
			}
			echo '</div>';
		};
		$pct = function ( $part, $whole ) {
			return $whole ? round( 100 * $part / $whole ) : 0;
		};
		?>
		<div class="eqw-analytics">
			<div class="eqw-kpis">
				<?php
				$tile( number_format_i18n( $total ), __( 'Documents', 'equalweb' ),
					/* translators: %s: total number of pages across all documents. */ sprintf( esc_html__( '%s pages in total', 'equalweb' ), number_format_i18n( $pages_total ) ) );
				$tile( number_format_i18n( $checked_any ), __( 'Checked', 'equalweb' ),
					$pct( $checked_any, $total ) . esc_html__( '% of all files', 'equalweb' ) );
				$tile( number_format_i18n( $status['remediated'] ), __( 'Remediated', 'equalweb' ),
					/* translators: %s: number of remediated files replaced on the site. */ sprintf( esc_html__( '%s replaced on the site', 'equalweb' ), number_format_i18n( $replaced ) ) );
				if ( null !== $avg_pair_b ) {
					$tile(
						'<span class="eqw-before-ink">' . $avg_pair_b . '</span> <span class="eqw-arrow">→</span> <span class="eqw-after-ink">' . $avg_pair_a . '</span>',
						__( 'Avg. score: before to after', 'equalweb' ),
						/* translators: %s: average number of points improved after remediation. */ sprintf( esc_html__( '+%s points on average', 'equalweb' ), max( 0, $avg_pair_a - $avg_pair_b ) )
					);
				} else {
					$tile( null !== $avg_before ? $avg_before . '<small>/100</small>' : '—', __( 'Avg. accessibility score', 'equalweb' ),
						esc_html__( 'Remediate files to track improvement', 'equalweb' ) );
				}
				if ( null !== $fix_rate ) {
					$tile( '<span class="eqw-after-ink">' . $fix_rate . '%</span>', __( 'Issues resolved by remediation', 'equalweb' ),
						/* translators: 1: number of failed checks fixed, 2: number of failed checks before remediation. */ sprintf( esc_html__( '%1$s of %2$s failed checks fixed', 'equalweb' ), number_format_i18n( $fixed ), number_format_i18n( $fail_before ) ) );
				} else {
					$tile( '—', __( 'Issues resolved by remediation', 'equalweb' ), esc_html__( 'No remediated files yet', 'equalweb' ) );
				}
				// Classification aggregates (ignored files excluded — they are
				// hidden from the inventory, and this KPI is the fix-first queue).
				$cls_a      = EQW_Classification::classify_all( $pdfs );
				$active_cls = array_filter( $cls_a, function ( $c ) {
					return empty( $c['ignored'] );
				} );
				$high_open = 0;
				foreach ( $pdfs as $p ) {
					$c = isset( $cls_a[ $p->ID ] ) ? $cls_a[ $p->ID ] : null;
					if ( $c && empty( $c['ignored'] ) && $c['prio'] >= 85
						&& 'remediated' !== get_post_meta( $p->ID, EQW_Ajax::META_STATE, true ) ) {
						$high_open++;
					}
				}
				$tile(
					'<span style="color:' . ( $high_open ? '#a12727' : '#00701c' ) . '">' . (int) $high_open . '</span>',
					__( 'High-priority not yet remediated', 'equalweb' ),
					esc_html__( 'Priority ≥ 85 — the fix-first queue', 'equalweb' )
				);
				?>
			</div>

			<div class="eqw-charts">
				<div class="eqw-chart">
					<h3><?php esc_html_e( 'Documents by category', 'equalweb' ); ?></h3>
					<?php
					$cat_total = max( 1, count( $active_cls ) );
					foreach ( EQW_Classification::cats() as $ckey => $cmeta ) :
						$ccount = 0;
						foreach ( $active_cls as $c ) {
							if ( $c['cat'] === $ckey ) {
								$ccount++;
							}
						}
						?>
						<div class="eqw-hbar" title="<?php echo esc_attr( $cmeta['label'] . ': ' . $ccount ); ?>">
							<span class="eqw-hbar-label"><?php echo esc_html( $cmeta['label'] ); ?></span>
							<span class="eqw-hbar-track"><i style="width:<?php echo (int) round( 100 * $ccount / $cat_total ); ?>%;background:<?php echo esc_attr( $cmeta['color'] ); ?>"></i></span>
							<span class="eqw-hbar-value"><?php echo esc_html( number_format_i18n( $ccount ) ); ?></span>
						</div>
					<?php endforeach; ?>
					<p class="eqw-chart-note"><?php esc_html_e( 'Category and priority come from Compliance → Classification — guidance for what to fix first, never a legal determination.', 'equalweb' ); ?></p>
				</div>

				<div class="eqw-chart">
					<h3><?php esc_html_e( 'Files by status', 'equalweb' ); ?></h3>
					<?php foreach ( $status_labels as $key => $meta ) : ?>
						<div class="eqw-hbar" title="<?php echo esc_attr( $meta[0] . ': ' . $status[ $key ] ); ?>">
							<span class="eqw-hbar-label"><?php echo esc_html( $meta[0] ); ?></span>
							<span class="eqw-hbar-track"><i style="width:<?php echo esc_attr( $pct( $status[ $key ], $total ) ); ?>%;background:<?php echo esc_attr( $meta[1] ); ?>"></i></span>
							<span class="eqw-hbar-value"><?php echo esc_html( number_format_i18n( $status[ $key ] ) ); ?></span>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="eqw-chart">
					<h3><?php esc_html_e( 'Score distribution (current scores)', 'equalweb' ); ?></h3>
					<?php if ( $scored ) : ?>
						<?php foreach ( $buckets as $range => $count ) : ?>
							<div class="eqw-hbar" title="<?php echo esc_attr( $range . ': ' . $count ); ?>">
								<span class="eqw-hbar-label"><?php echo esc_html( $range ); ?></span>
								<span class="eqw-hbar-track"><i style="width:<?php echo esc_attr( $pct( $count, $scored ) ); ?>%;background:<?php echo esc_attr( $bucket_colors[ $range ] ); ?>"></i></span>
								<span class="eqw-hbar-value"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
							</div>
						<?php endforeach; ?>
						<p class="eqw-chart-note"><?php esc_html_e( 'Latest known score per file (after remediation when available).', 'equalweb' ); ?></p>
					<?php else : ?>
						<p class="eqw-chart-note"><?php esc_html_e( 'Run checks to populate this chart.', 'equalweb' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="eqw-chart">
					<h3><?php esc_html_e( 'Remediation impact: average score', 'equalweb' ); ?></h3>
					<?php if ( $pair_n ) : ?>
						<div class="eqw-hbar" title="<?php echo esc_attr( 'Before: ' . $avg_pair_b . '/100' ); ?>">
							<span class="eqw-hbar-label"><?php esc_html_e( 'Before', 'equalweb' ); ?></span>
							<span class="eqw-hbar-track"><i style="width:<?php echo esc_attr( $avg_pair_b ); ?>%;background:#4a63c8"></i></span>
							<span class="eqw-hbar-value"><?php echo esc_html( $avg_pair_b ); ?>/100</span>
						</div>
						<div class="eqw-hbar" title="<?php echo esc_attr( 'After: ' . $avg_pair_a . '/100' ); ?>">
							<span class="eqw-hbar-label"><?php esc_html_e( 'After', 'equalweb' ); ?></span>
							<span class="eqw-hbar-track"><i style="width:<?php echo esc_attr( $avg_pair_a ); ?>%;background:#0d9668"></i></span>
							<span class="eqw-hbar-value"><?php echo esc_html( $avg_pair_a ); ?>/100</span>
						</div>
						<p class="eqw-chart-note">
							<?php /* translators: 1: number of remediated files, 2: number of pages across those files. */ printf( esc_html__( 'Based on %1$s remediated files (%2$s pages).', 'equalweb' ), esc_html( number_format_i18n( $pair_n ) ), esc_html( number_format_i18n( $pages_rem ) ) ); ?>
						</p>
					<?php else : ?>
						<p class="eqw-chart-note"><?php esc_html_e( 'Remediate at least one file to see before/after impact.', 'equalweb' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="eqw-chart">
					<h3><?php esc_html_e( 'Failed checks: before vs. after remediation', 'equalweb' ); ?></h3>
					<?php if ( $pair_n && $fail_before ) : ?>
						<div class="eqw-hbar" title="<?php echo esc_attr( 'Failed before: ' . $fail_before ); ?>">
							<span class="eqw-hbar-label"><?php esc_html_e( 'Before', 'equalweb' ); ?></span>
							<span class="eqw-hbar-track"><i style="width:100%;background:#4a63c8"></i></span>
							<span class="eqw-hbar-value"><?php echo esc_html( number_format_i18n( $fail_before ) ); ?></span>
						</div>
						<div class="eqw-hbar" title="<?php echo esc_attr( 'Failed after: ' . $fail_after ); ?>">
							<span class="eqw-hbar-label"><?php esc_html_e( 'After', 'equalweb' ); ?></span>
							<span class="eqw-hbar-track"><i style="width:<?php echo esc_attr( $pct( $fail_after, $fail_before ) ); ?>%;background:#0d9668"></i></span>
							<span class="eqw-hbar-value"><?php echo esc_html( number_format_i18n( $fail_after ) ); ?></span>
						</div>
						<p class="eqw-chart-note">
							<?php /* translators: 1: number of failed checks resolved, 2: percentage success rate. */ printf( esc_html__( '%1$s failed checks resolved (%2$s%% success rate).', 'equalweb' ), esc_html( number_format_i18n( $fixed ) ), (int) $fix_rate ); ?>
						</p>
					<?php else : ?>
						<p class="eqw-chart-note"><?php esc_html_e( 'No before/after issue data yet.', 'equalweb' ); ?></p>
					<?php endif; ?>
				</div>
			</div>

			<?php
			// Activity by user: management view (view-all / admin scope only) —
			// own-scope users would only ever see themselves here. Attribution is
			// recorded from this release onward; older events have no initiator.
			if ( current_user_can( EQW_Capabilities::CAP_VIEW_ALL ) && $by_user ) :
				uasort( $by_user, function ( $a, $b ) {
					$ta = ( isset( $a['checks'] ) ? $a['checks'] : 0 ) + ( isset( $a['rems'] ) ? $a['rems'] : 0 );
					$tb = ( isset( $b['checks'] ) ? $b['checks'] : 0 ) + ( isset( $b['rems'] ) ? $b['rems'] : 0 );
					return $tb - $ta;
				} );
				?>
			<div class="eqw-reports-card">
				<h3><?php esc_html_e( 'Activity by user', 'equalweb' ); ?></h3>
				<p class="eqw-chart-note"><?php esc_html_e( 'Who ran checks and paid remediations (attributed from the moment each action was started; activity from before this release is not attributed).', 'equalweb' ); ?></p>
				<table class="widefat striped eqw-table eqw-byuser">
					<thead>
					<tr>
						<th><?php esc_html_e( 'User', 'equalweb' ); ?></th>
						<th><?php esc_html_e( 'Checks', 'equalweb' ); ?></th>
						<th><?php esc_html_e( 'Remediations', 'equalweb' ); ?></th>
					</tr>
					</thead>
					<tbody>
					<?php foreach ( $by_user as $row ) : ?>
						<tr>
							<td><?php echo esc_html( isset( $row['name'] ) ? $row['name'] : '?' ); ?></td>
							<td><?php echo (int) ( isset( $row['checks'] ) ? $row['checks'] : 0 ); ?></td>
							<td><?php echo (int) ( isset( $row['rems'] ) ? $row['rems'] : 0 ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endif; ?>

			<?php
			// Executive reports: a branded, downloadable PDF summary per year
			// with at least one check/remediation event. Same query_pdfs()
			// scope as every aggregate above — own-scope users report on their
			// files only.
			$years = self::yearly_stats();
			?>
			<div class="eqw-reports-card">
				<h3><?php esc_html_e( 'Executive reports', 'equalweb' ); ?></h3>
				<p class="eqw-chart-note"><?php esc_html_e( 'Download a branded yearly summary of your PDF accessibility program.', 'equalweb' ); ?></p>
				<?php if ( $years ) : ?>
					<p class="eqw-report-btns">
						<?php foreach ( array_keys( $years ) as $year ) :
							$url = admin_url( 'admin-ajax.php?action=eqw_yearly_report&year=' . $year . '&_ajax_nonce=' . wp_create_nonce( EQW_Ajax::NONCE ) );
							?>
							<a class="button" href="<?php echo esc_url( $url ); ?>"><?php
								echo self::icon( 'download' ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-built SVG.
								echo esc_html( sprintf( /* translators: %s: year */ __( '%s report (PDF)', 'equalweb' ), $year ) );
							?></a>
						<?php endforeach; ?>
					</p>
				<?php else : ?>
					<p class="eqw-chart-note eqw-muted"><?php esc_html_e( 'No activity to report yet.', 'equalweb' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Per-year aggregates for the Executive report, newest year first.
	 *
	 * Derived from the plugin's own records: query_pdfs() (the single scope
	 * choke point — own-scope users aggregate THEIR files only) joined with
	 * attachment meta. A year exists only if it saw at least one event.
	 *
	 * A check counts in the year/month of summary.checked_at.
	 *
	 * A document counts as REMEDIATED in year Y iff its state is 'remediated'
	 * (the accessible version was actually applied, by replace or save-as-new)
	 * AND its applied-timestamp falls in Y. Counting summary_after instead — a
	 * record written when the remediation FINISHES — reported every finished but
	 * never-applied run as a remediation, so the report and the Analytics
	 * "Remediated" tile disagreed. They now share one definition by construction.
	 */
	public static function yearly_stats() {
		$years = array();
		$blank = array(
			'checked'           => 0,
			'remediated'        => 0,
			'pages'             => 0,
			'fixed'             => 0,
			'score_before_sum'  => 0,
			'score_after_sum'   => 0,
			'score_n'           => 0,
			'months_checked'    => array_fill( 0, 12, 0 ),
			'months_remediated' => array_fill( 0, 12, 0 ),
		);
		foreach ( self::query_pdfs() as $pdf ) {
			$summary = get_post_meta( $pdf->ID, EQW_Ajax::META_SUMMARY, true );
			$after   = get_post_meta( $pdf->ID, '_eqw_summary_after', true );

			$checked_at = is_array( $summary ) && ! empty( $summary['checked_at'] ) ? (int) $summary['checked_at'] : 0;
			if ( $checked_at > 0 ) {
				$y = (int) gmdate( 'Y', $checked_at );
				if ( ! isset( $years[ $y ] ) ) {
					$years[ $y ] = $blank;
				}
				$years[ $y ]['checked']++;
				$years[ $y ]['months_checked'][ (int) gmdate( 'n', $checked_at ) - 1 ]++;
			}

			// Applied, and when: state proves the accessible version actually
			// replaced the file (or was saved as a new one), META_REMEDIATED
			// timestamps that moment. Rows applied via save-as-new before 2.6.3
			// never got the stamp, so date those by the run that produced them
			// instead of dropping a year of real history from the report.
			$rem_at = 0;
			if ( 'remediated' === get_post_meta( $pdf->ID, EQW_Ajax::META_STATE, true ) ) {
				$rem_at = (int) get_post_meta( $pdf->ID, EQW_File_Manager::META_REMEDIATED, true );
				if ( $rem_at <= 0 ) {
					$rem_at = is_array( $after ) && ! empty( $after['checked_at'] ) ? (int) $after['checked_at'] : 0;
				}
			}
			if ( $rem_at > 0 ) {
				$y = (int) gmdate( 'Y', $rem_at );
				if ( ! isset( $years[ $y ] ) ) {
					$years[ $y ] = $blank;
				}
				$years[ $y ]['remediated']++;
				$years[ $y ]['months_remediated'][ (int) gmdate( 'n', $rem_at ) - 1 ]++;
				// '_eqw_pages' may hold the 'none' sentinel — (int) makes it 0.
				$years[ $y ]['pages'] += (int) get_post_meta( $pdf->ID, '_eqw_pages', true );
				$before_score = is_array( $summary ) && isset( $summary['score'] ) ? $summary['score'] : null;
				$after_score  = isset( $after['score'] ) ? $after['score'] : null;
				if ( null !== $before_score && null !== $after_score ) {
					$years[ $y ]['score_before_sum'] += (int) $before_score;
					$years[ $y ]['score_after_sum']  += (int) $after_score;
					$years[ $y ]['score_n']++;
				}
				$fail_b = is_array( $summary ) && isset( $summary['issues']['failed'] ) ? (int) $summary['issues']['failed'] : 0;
				$fail_a = isset( $after['issues']['failed'] ) ? (int) $after['issues']['failed'] : 0;
				$years[ $y ]['fixed'] += max( 0, $fail_b - $fail_a );
			}
		}
		foreach ( $years as &$s ) {
			$s['avg_before'] = $s['score_n'] ? (int) round( $s['score_before_sum'] / $s['score_n'] ) : null;
			$s['avg_after']  = $s['score_n'] ? (int) round( $s['score_after_sum'] / $s['score_n'] ) : null;
		}
		unset( $s );
		krsort( $years );
		return $years;
	}

	/**
	 * Read a bounded slice of a PDF — the first and last chunks — instead of
	 * the whole file. PDF metadata (Info dictionary, XMP, /Lang, the /Pages
	 * root and its /Count) lives in the header or trailer, so this is enough
	 * for extraction while a single multi-hundred-MB PDF can no longer exhaust
	 * PHP's memory limit and take down the whole list screen.
	 *
	 * @return string Head + tail bytes (the whole file when it is small).
	 */
	private static function read_pdf_slice( $file ) {
		$max_head = 1048576; // 1 MB from the start (Root/Pages/catalog).
		$max_tail = 1048576; // 1 MB from the end (xref, trailer, Info, XMP).
		$size = @filesize( $file );
		if ( ! $size ) {
			return '';
		}
		$fh = @fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded partial read; WP_Filesystem has no seek/partial-read API and loading a multi-hundred-MB PDF whole would exhaust memory (see docblock).
		if ( ! $fh ) {
			return '';
		}
		$head = (string) fread( $fh, (int) min( $size, $max_head ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded partial read; WP_Filesystem has no seek/partial-read API and loading a multi-hundred-MB PDF whole would exhaust memory (see docblock).
		$tail = '';
		if ( $size > $max_head ) {
			$tail_len = (int) min( $size - $max_head, $max_tail );
			if ( 0 === fseek( $fh, -$tail_len, SEEK_END ) ) {
				$tail = (string) fread( $fh, $tail_len ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- bounded partial read; WP_Filesystem has no seek/partial-read API and loading a multi-hundred-MB PDF whole would exhaust memory (see docblock).
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- see partial-read note above.
		return $head . $tail;
	}

	/**
	 * Document title extracted from the PDF's own metadata (Info /Title or
	 * XMP dc:title), cached in meta. Empty string when the PDF has none.
	 *
	 * @return string
	 */
	public static function get_doc_title( $attachment_id ) {
		// PDF-only extraction — the regexes below must never run on office
		// binaries (Word/PowerPoint/Excel carry no PDF Info/XMP metadata).
		if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return '';
		}
		$cached = get_post_meta( $attachment_id, '_eqw_doc_title', true );
		if ( '' !== $cached ) {
			return 'none' === $cached ? '' : $cached;
		}
		$file  = get_attached_file( $attachment_id );
		$title = '';
		if ( $file && file_exists( $file ) ) {
			$data = self::read_pdf_slice( $file );
			// XMP dc:title first (usually cleaner).
			if ( preg_match( '#<dc:title>.*?<rdf:li[^>]*>(.*?)</rdf:li>#s', $data, $m ) ) {
				$title = trim( wp_strip_all_tags( html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) ) );
			}
			// Fallback: Info dictionary /Title (literal string).
			if ( '' === $title && preg_match( '#/Title\s*\(((?:[^()\\\\]|\\\\.)*)\)#s', $data, $m ) ) {
				$raw = stripcslashes( $m[1] );
				if ( "\xFE\xFF" === substr( $raw, 0, 2 ) ) { // UTF-16BE BOM.
					$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
				}
				$title = trim( $raw );
			}
			// Hex string variant: /Title <FEFF...>.
			if ( '' === $title && preg_match( '#/Title\s*<([0-9A-Fa-f\s]+)>#', $data, $m ) ) {
				$raw = pack( 'H*', preg_replace( '/\s+/', '', $m[1] ) );
				if ( "\xFE\xFF" === substr( $raw, 0, 2 ) ) {
					$raw = mb_convert_encoding( substr( $raw, 2 ), 'UTF-8', 'UTF-16BE' );
				}
				$title = trim( $raw );
			}
		}
		$title = sanitize_text_field( $title );
		update_post_meta( $attachment_id, '_eqw_doc_title', '' === $title ? 'none' : $title );
		return $title;
	}

	/**
	 * Document language from the PDF catalog (/Lang) or XMP dc:language,
	 * cached in meta. Empty string when not declared.
	 *
	 * @return string e.g. "en-US", "he-IL".
	 */
	public static function get_doc_lang( $attachment_id ) {
		// PDF-only extraction — never run the /Lang regexes on office binaries.
		if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return '';
		}
		$cached = get_post_meta( $attachment_id, '_eqw_doc_lang', true );
		if ( '' !== $cached ) {
			return 'none' === $cached ? '' : $cached;
		}
		$file = get_attached_file( $attachment_id );
		$lang = '';
		if ( $file && file_exists( $file ) ) {
			$data = self::read_pdf_slice( $file );
			if ( preg_match( '#/Lang\s*\(([A-Za-z0-9\-]{2,10})\)#', $data, $m ) ) {
				$lang = $m[1];
			} elseif ( preg_match( '#<dc:language>.*?<rdf:li[^>]*>([A-Za-z0-9\-]{2,10})</rdf:li>#s', $data, $m ) ) {
				$lang = $m[1];
			}
		}
		$lang = sanitize_text_field( $lang );
		update_post_meta( $attachment_id, '_eqw_doc_lang', '' === $lang ? 'none' : $lang );
		return $lang;
	}

	/**
	 * Page count for a PDF attachment, cached in meta. Counted locally by
	 * scanning the PDF for page objects; EqualWeb's authoritative
	 * numberOfPages overwrites it after a check.
	 *
	 * @return int|null Null when the count cannot be determined.
	 */
	public static function get_page_count( $attachment_id ) {
		$cached = get_post_meta( $attachment_id, '_eqw_pages', true );
		if ( '' !== $cached ) {
			// 'none' is the "couldn't determine" sentinel; cache it so a PDF
			// whose count we can't read is not re-scanned on every page load.
			return 'none' === $cached ? null : (int) $cached;
		}
		// The local scan is PDF-only: office binaries must never be run through
		// the PDF regexes (and a garbage count must never be cached for them).
		// Office rows still get an authoritative count when a poll stores
		// EqualWeb's numberOfPages in the cache read above.
		if ( 'application/pdf' !== get_post_mime_type( $attachment_id ) ) {
			return null;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}
		$data = self::read_pdf_slice( $file );
		// The /Pages tree /Count is authoritative and lives near the root, so
		// it survives the bounded read even when individual page objects don't;
		// fall back to counting /Type /Page objects only when it is absent.
		$count = 0;
		if ( preg_match_all( '#/Count\s+(\d+)#', $data, $m ) ) {
			$count = (int) max( $m[1] );
		}
		if ( ! $count ) {
			$count = (int) preg_match_all( '#/Type\s*/Page\b(?!s)#', $data, $m );
		}
		update_post_meta( $attachment_id, '_eqw_pages', $count ? (int) $count : 'none' );
		return $count ? (int) $count : null;
	}

	/* ------------------------------------------------------------------ */
	/* PDF AI Remediation                                                  */
	/* ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------ */
	/* Welcome offer (coupon for the first EqualWeb credits)               */
	/* ------------------------------------------------------------------ */

	/**
	 * The coupon code for the first EqualWeb credits, exclusive to WordPress
	 * sites (nir, 2026-10-09). The code itself is defined on the EqualWeb side;
	 * this plugin only shows it. Shown as a banner at the top of the PDF AI
	 * Remediation and Start Auto Remediation tabs, and as a one-line note next
	 * to the API-key field in Settings.
	 */
	const OFFER_CODE = 'WORDPRESS50';

	/**
	 * The welcome-offer markup.
	 *
	 * @param string $variant 'banner' (the AI and Start tabs) or 'compact' (Settings).
	 * @return string HTML, built from escaped parts.
	 */
	public static function render_offer( $variant = 'banner' ) {
		$settings_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' );
		$copy_button  = '<button type="button" class="button eqw-promo-copy" data-code="' . esc_attr( self::OFFER_CODE ) . '"'
			. ' data-copied="' . esc_attr__( 'Code copied.', 'equalweb' ) . '"'
			. ' data-selected="' . esc_attr__( 'The code is selected. Press Ctrl+C (Cmd+C on a Mac) to copy it.', 'equalweb' ) . '">';
		$account_link = '<a class="button" href="' . esc_url( self::SIGNUP_URL ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'Get your EqualWeb account', 'equalweb' )
			. '<span class="screen-reader-text"> (' . esc_html__( 'opens in a new tab', 'equalweb' ) . ')</span>'
			. self::icon( 'external' ) . '</a>';
		if ( 'compact' === $variant ) {
			return '<div class="eqw-offer">'
				. '<p class="eqw-offer-text">' . esc_html__( 'WordPress exclusive: use code', 'equalweb' )
				. ' <code class="eqw-offer-code">' . esc_html( self::OFFER_CODE ) . '</code> '
				. esc_html__( 'for 50% off your first EqualWeb credits.', 'equalweb' ) . '</p>'
				. '<span class="eqw-offer-actions">' . $copy_button . esc_html__( 'Copy code', 'equalweb' ) . '</button> ' . $account_link . '</span>'
				. '<span class="eqw-offer-status" role="status"></span>'
				. '</div>';
		}
		return '<section class="eqw-promo" aria-labelledby="eqw-promo-h">'
			. '<div class="eqw-promo-text">'
			. '<span class="eqw-promo-eyebrow">' . esc_html__( 'WordPress exclusive', 'equalweb' ) . '</span>'
			. '<h2 id="eqw-promo-h" class="eqw-promo-h">' . esc_html__( '50% off your first EqualWeb credits', 'equalweb' ) . '</h2>'
			. '<p class="eqw-promo-sub">' . esc_html__( 'Enter the code when you buy your first credits at EqualWeb, then paste your API key in Settings.', 'equalweb' ) . '</p>'
			. '</div>'
			. '<div class="eqw-promo-code-row"><code class="eqw-promo-code">' . esc_html( self::OFFER_CODE ) . '</code> '
			. $copy_button . esc_html__( 'Copy code', 'equalweb' ) . '</button></div>'
			. '<p class="eqw-promo-actions">'
			. '<a class="button button-primary" href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Set up your API key', 'equalweb' ) . '</a> '
			. $account_link
			. '</p>'
			. '<p class="eqw-promo-status" role="status"></p>'
			. '</section>';
	}

	private static function render_ai() {
		// Static marketing/explainer content ported from the approved design
		// sketch. Pure server-rendered HTML — no JS behaviour on this tab.
		?>
		<div class="eqw-ai">
			<?php echo self::render_offer( 'banner' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
			<div class="eqw-explain">
				<div class="eqw-explain-grid">
					<div class="eqw-exp-copy">
						<h2><?php esc_html_e( 'PDF AI Remediation', 'equalweb' ); ?> <span class="eqw-badge eqw-ok"><?php esc_html_e( 'New', 'equalweb' ); ?></span></h2>
						<p><strong><?php esc_html_e( 'What this does:', 'equalweb' ); ?></strong> <?php esc_html_e( 'AI Remediation reads the document the way a human editor would and fixes the issues that need judgement - it optimizes heading levels, reading order, complex lists, links, references and tables, and writes real alternate text for every image. Scanned documents are OCR\'d first, so even a scan of a printed page comes back as searchable, accessible text. The result: a fully tagged PDF, navigable and readable with screen readers, ready in minutes.', 'equalweb' ); ?></p>
						<p><?php esc_html_e( 'Under the hood it combines OCR, advanced image processing and EqualWeb\'s proprietary AI models. The original structure, layout and visual design are', 'equalweb' ); ?> <strong><?php esc_html_e( 'preserved exactly', 'equalweb' ); ?></strong> <?php esc_html_e( '- accessibility is applied to the source file without altering its appearance.', 'equalweb' ); ?></p>
						<p><?php esc_html_e( 'Every remediated document comes back', 'equalweb' ); ?> <strong><?php esc_html_e( 'accessibility-stamped, with a detailed checker report', 'equalweb' ); ?></strong> <?php esc_html_e( 'of the fixes applied and anything that still needs manual attention. Replace the original at the same URL (a backup is kept), save it as a new file, or download it.', 'equalweb' ); ?></p>
						<p><strong><?php esc_html_e( 'One-time payment per document', 'equalweb' ); ?></strong> <?php esc_html_e( '- 1 credit ≈ 1 page, no ongoing fees. Processing is secured and ISO 27001-certified, and works for public, internal and password-protected documents alike.', 'equalweb' ); ?></p>
					</div>
					<div class="eqw-convpanel">
						<h3><?php esc_html_e( 'What you can send', 'equalweb' ); ?></h3>
						<div class="eqw-conv-in">
							<span class="eqw-ftok eqw-f-pdf"><b><?php esc_html_e( 'PDF', 'equalweb' ); ?></b><small><?php esc_html_e( 'incl. scanned', 'equalweb' ); ?></small></span>
							<span class="eqw-ftok eqw-f-doc"><b><?php esc_html_e( 'DOC / DOCX', 'equalweb' ); ?></b><small><?php esc_html_e( 'Word', 'equalweb' ); ?></small></span>
							<span class="eqw-ftok eqw-f-ppt"><b><?php esc_html_e( 'PPT / PPTX', 'equalweb' ); ?></b><small><?php esc_html_e( 'PowerPoint', 'equalweb' ); ?></small></span>
							<span class="eqw-ftok eqw-f-xls"><b><?php esc_html_e( 'XLS / XLSX', 'equalweb' ); ?></b><small><?php esc_html_e( 'Excel', 'equalweb' ); ?></small></span>
						</div>
						<div class="eqw-conv-arrow">↓<small><?php esc_html_e( 'Whatever you send, you always get back', 'equalweb' ); ?></small></div>
						<div class="eqw-conv-out"><img class="eqw-conv-logo" src="<?php echo esc_url( EQW_PDF_URL . 'assets/logowb.jpg' ); ?>" alt=""><span><b><?php esc_html_e( 'An accessible PDF', 'equalweb' ); ?></b><small><?php esc_html_e( 'tagged · PDF/UA conformant · ready to publish', 'equalweb' ); ?></small></span></div>
						<div class="eqw-limits">
							<div class="eqw-limit"><b><?php esc_html_e( '100 MB', 'equalweb' ); ?></b><span><?php esc_html_e( 'max file size', 'equalweb' ); ?></span></div>
							<div class="eqw-limit"><b><?php esc_html_e( '200 pages', 'equalweb' ); ?></b><span><?php esc_html_e( 'max per document', 'equalweb' ); ?></span></div>
						</div>
						<h3 class="eqw-stds-h"><?php esc_html_e( 'Meets accessibility standards', 'equalweb' ); ?></h3>
						<div class="eqw-stds">
							<span class="eqw-std">PDF/UA</span><span class="eqw-std">WCAG 2.2</span><span class="eqw-std">Section 508</span><span class="eqw-std">ADA</span><span class="eqw-std">EN 301 549</span>
						</div>
						<p class="eqw-conv-foot"><?php esc_html_e( 'Documents over 100 MB or 200 pages can\'t be sent - split them first. One-time payment per document (1 credit ≈ 1 page), no ongoing fees. The original file is never changed without your approval.', 'equalweb' ); ?></p>
					</div>
				</div>
				<div class="eqw-steps">
					<div class="eqw-step"><b><span class="eqw-step-n">1</span><?php esc_html_e( 'Send', 'equalweb' ); ?></b><small><?php esc_html_e( 'Any supported document up to 100 MB and 200 pages.', 'equalweb' ); ?></small></div>
					<div class="eqw-step"><b><span class="eqw-step-n">2</span><?php esc_html_e( 'AI analyzes & fixes', 'equalweb' ); ?></b><small><?php esc_html_e( 'OCR, tagging, alt text, reading order, tables, language & title.', 'equalweb' ); ?></small></div>
					<div class="eqw-step"><b><span class="eqw-step-n">3</span><?php esc_html_e( 'You review', 'equalweb' ); ?></b><small><?php esc_html_e( 'A checker report lists every fix applied and anything left to verify.', 'equalweb' ); ?></small></div>
					<div class="eqw-step"><b><span class="eqw-step-n">4</span><?php esc_html_e( 'Get a PDF back', 'equalweb' ); ?></b><small><?php esc_html_e( 'Always a PDF, stamped accessible - replace the original, save as new, or download.', 'equalweb' ); ?></small></div>
				</div>
				<div class="eqw-handles"><span class="eqw-h-l"><?php esc_html_e( 'Built for real-world documents:', 'equalweb' ); ?></span>
					<span class="eqw-hchip"><?php esc_html_e( 'Multi-column layouts', 'equalweb' ); ?></span><span class="eqw-hchip"><?php esc_html_e( 'Scanned pages (OCR)', 'equalweb' ); ?></span><span class="eqw-hchip"><?php esc_html_e( 'Interactive fillable forms', 'equalweb' ); ?></span><span class="eqw-hchip"><?php esc_html_e( 'Data-dense tables & charts', 'equalweb' ); ?></span><span class="eqw-hchip"><?php esc_html_e( 'Financial & legal reports', 'equalweb' ); ?></span><span class="eqw-hchip"><?php esc_html_e( 'Engineering plans', 'equalweb' ); ?></span><span class="eqw-hchip"><?php esc_html_e( 'Image-heavy brochures', 'equalweb' ); ?></span><span class="eqw-hchip"><?php esc_html_e( 'Password-protected files', 'equalweb' ); ?></span>
				</div>
			</div>

			<div class="eqw-ai-card">
				<div class="eqw-sugg-head"><strong><?php esc_html_e( 'What the AI fixes - examples', 'equalweb' ); ?></strong>
					<span class="eqw-sugg-sub"><?php esc_html_e( 'Real fixes need human judgement - this is what the AI writes for you.', 'equalweb' ); ?></span></div>
				<div class="eqw-sugg">
					<span class="eqw-kind eqw-k-fig"><span>▥</span><?php esc_html_e( 'Images', 'equalweb' ); ?></span>
					<div><h4><?php esc_html_e( 'Alternate text for every image', 'equalweb' ); ?></h4>
						<p class="eqw-why"><?php esc_html_e( 'Advanced image recognition identifies objects, colors, positions - even actions and emotions - and writes a concise, accurate description that screen readers announce instead of skipping the image.', 'equalweb' ); ?></p>
						<p class="eqw-alt-sample"><?php esc_html_e( '“Bar chart comparing fund performance 2021-2025; annual returns rise from 4.2% to 7.8%, peaking in 2024.”', 'equalweb' ); ?></p></div>
					<span class="eqw-badge eqw-ok"><?php esc_html_e( 'AI written', 'equalweb' ); ?></span>
				</div>
				<div class="eqw-sugg">
					<span class="eqw-kind eqw-k-str"><span>¶</span><?php esc_html_e( 'Structure', 'equalweb' ); ?></span>
					<div><h4><?php esc_html_e( 'Headings & reading order', 'equalweb' ); ?></h4>
						<p class="eqw-why"><?php esc_html_e( 'Rebuilds the heading hierarchy from the visual layout - a detected document title becomes the H1 - and restores a clean, logical reading order across pages, including multi-column layouts.', 'equalweb' ); ?></p></div>
					<span class="eqw-badge eqw-ok"><?php esc_html_e( 'AI built', 'equalweb' ); ?></span>
				</div>
				<div class="eqw-sugg">
					<span class="eqw-kind eqw-k-str"><span>≡</span><?php esc_html_e( 'Content', 'equalweb' ); ?></span>
					<div><h4><?php esc_html_e( 'Lists, links & references', 'equalweb' ); ?></h4>
						<p class="eqw-why"><?php esc_html_e( 'Complex and nested lists, links and cross-references are tagged so assistive-technology users can jump between them and understand how items relate.', 'equalweb' ); ?></p></div>
					<span class="eqw-badge eqw-ok"><?php esc_html_e( 'AI tagged', 'equalweb' ); ?></span>
				</div>
				<div class="eqw-sugg">
					<span class="eqw-kind eqw-k-tbl"><span>▦</span><?php esc_html_e( 'Tables', 'equalweb' ); ?></span>
					<div><h4><?php esc_html_e( 'Table structure', 'equalweb' ); ?></h4>
						<p class="eqw-why"><?php esc_html_e( 'Adds header rows and row headers and resolves merged cells, so each data cell is announced with its meaning - including data-dense tables and interactive forms.', 'equalweb' ); ?></p></div>
					<span class="eqw-badge eqw-warn"><?php esc_html_e( 'Flagged for review', 'equalweb' ); ?></span>
				</div>
				<div class="eqw-sugg">
					<span class="eqw-kind eqw-k-meta"><span>◐</span><?php esc_html_e( 'Metadata', 'equalweb' ); ?></span>
					<div><h4><?php esc_html_e( 'Language & title', 'equalweb' ); ?></h4>
						<p class="eqw-why"><?php esc_html_e( 'Detects the document language (including mixed Hebrew/English) and sets a real title from the first heading - announced instead of the filename.', 'equalweb' ); ?></p></div>
					<span class="eqw-badge eqw-ok"><?php esc_html_e( 'AI set', 'equalweb' ); ?></span>
				</div>
				<div class="eqw-sugg">
					<span class="eqw-kind eqw-k-rev"><span>◎</span><?php esc_html_e( 'Report', 'equalweb' ); ?></span>
					<div><h4><?php esc_html_e( 'Accessibility stamp & checker report', 'equalweb' ); ?></h4>
						<p class="eqw-why"><?php esc_html_e( 'Every remediated PDF is accessibility-stamped and ships with a detailed report: the tags added and removed, the fixes applied, and any remaining issues that need your attention.', 'equalweb' ); ?></p></div>
					<span class="eqw-badge eqw-ok"><?php esc_html_e( 'Every document', 'equalweb' ); ?></span>
				</div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Start Auto Remediation                                              */
	/* ------------------------------------------------------------------ */

	private static function render_start() {
		// Intro + a button to EqualWeb's hosted registration flow
		// (login.equalweb.com refuses framing, so the flow opens in a new tab).
		?>
		<div class="eqw-start">
			<div class="eqw-explain">
				<h2><?php esc_html_e( 'Start Auto Remediation', 'equalweb' ); ?></h2>
				<?php echo self::render_offer( 'banner' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
				<p><?php esc_html_e( 'Free accessibility checks work out of the box. To', 'equalweb' ); ?> <strong><?php esc_html_e( 'remediate', 'equalweb' ); ?></strong> <?php esc_html_e( 'documents, complete this one-time setup (about 2 minutes):', 'equalweb' ); ?></p>
				<ol class="eqw-start-steps">
					<li><?php esc_html_e( 'Create an EqualWeb account.', 'equalweb' ); ?></li>
					<li><?php esc_html_e( 'Go to', 'equalweb' ); ?> <strong><?php esc_html_e( 'Payment Methods', 'equalweb' ); ?></strong> <?php esc_html_e( 'and add a credit card.', 'equalweb' ); ?></li>
					<li><?php esc_html_e( 'Go to', 'equalweb' ); ?> <strong><?php esc_html_e( 'PDF Remediation → Buy Credits', 'equalweb' ); ?></strong> <?php esc_html_e( 'and purchase credits.', 'equalweb' ); ?></li>
					<li><?php esc_html_e( 'Open your', 'equalweb' ); ?> <strong><?php esc_html_e( 'Profile → API Keys', 'equalweb' ); ?></strong><?php esc_html_e( ', create a new API key, and copy it.', 'equalweb' ); ?></li>
					<li><?php esc_html_e( 'Return to the EqualWeb settings in your platform and paste the API key.', 'equalweb' ); ?></li>
				</ol>
				<p><?php esc_html_e( 'Once connected, you can start remediating documents directly from your platform.', 'equalweb' ); ?></p>
				<p class="eqw-start-cta">
					<a class="button button-primary eqw-start-btn" href="<?php echo esc_url( self::SIGNUP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Start Auto Remediation', 'equalweb' ); echo self::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-built SVG. ?></a>
					<a class="button eqw-start-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' ) ); ?>"><?php esc_html_e( 'Set up your API key', 'equalweb' ); ?></a>
				</p>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */

	private static function render_settings() {
		$has_key      = (bool) EQW_API_Client::get_personal_key();
		$key_broken   = EQW_API_Client::key_decrypt_failed();
		?>
		<div class="eqw-settings">
			<h2><?php esc_html_e( 'Free checks', 'equalweb' ); ?></h2>
			<?php
			// The full registration status (registered, pending or failed, with
			// why, what to do, Try again and the technical details), in place of
			// the banner the other tabs show.
			echo self::registration_box(); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts by registration_notice_body().
			if ( $has_key && ! EQWPDF_Registration::state()['registered'] ) {
				echo '<p class="description">' . esc_html__( 'Checks on this site currently run on the saved API key, so this registration does not block checking.', 'equalweb' ) . '</p>';
			}
			?>

			<h2><?php esc_html_e( 'Remediation (EqualWeb account)', 'equalweb' ); ?></h2>
			<p class="description">
				<?php
				printf(
					/* translators: %s: EqualWeb registration link */
					esc_html__( 'Remediating documents requires an EqualWeb account, a personal API key and credits. %s', 'equalweb' ),
					'<a href="' . esc_url( self::SIGNUP_URL ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create an account and purchase credits', 'equalweb' ) . self::icon( 'external' ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput -- URL via esc_url(), text via esc_html__(), icon is plugin-built SVG.
				);
				?>
			</p>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="eqw-api-key"><?php esc_html_e( 'API key', 'equalweb' ); ?></label></th>
					<td>
						<input type="text" id="eqw-api-key" class="regular-text" autocomplete="off"
							value="<?php echo esc_attr( $has_key ? '••••••••••••••••' : '' ); ?>"
							placeholder="<?php esc_attr_e( 'Paste your EqualWeb API key', 'equalweb' ); ?>">
						<?php echo self::render_offer( 'compact' ); // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts. ?>
						<?php if ( $key_broken ) : ?>
							<p class="description"><span class="eqw-badge eqw-bad"><?php esc_html_e( 'A saved key could not be decrypted (the site security keys may have changed). Please re-enter your API key.', 'equalweb' ); ?></span></p>
						<?php endif; ?>
						<p class="description">
							<?php
							// Without OpenSSL the key is now REFUSED rather than stored in
							// cleartext, so say that up front instead of after a failed save.
							echo function_exists( 'openssl_encrypt' )
								? esc_html__( 'Stored encrypted. Used only from the server. Never exposed to visitors.', 'equalweb' )
								: esc_html__( 'This site cannot store the API key securely (the PHP OpenSSL extension is missing), so saving a key will fail. Ask your host to enable OpenSSL.', 'equalweb' );
							?>
						</p>
					</td>
				</tr>
				<?php if ( $has_key ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Credit balance', 'equalweb' ); ?></th>
					<td><span id="eqw-balance">…</span></td>
				</tr>
				<?php endif; ?>
			</table>
			<?php $ad = EQW_API_Client::audit_defaults(); ?>
			<h2><?php esc_html_e( 'Audit defaults', 'equalweb' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Pre-fill these options whenever you remediate a PDF.', 'equalweb' ); ?></p>
			<table class="form-table eqw-audit-defaults">
				<tr>
					<th scope="row"><?php esc_html_e( 'Add signature', 'equalweb' ); ?></th>
					<td><label><input type="checkbox" id="eqw-ad-signature" <?php checked( $ad['add_signature'] ); ?>>
						<?php esc_html_e( 'Digitally sign the remediated document', 'equalweb' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Fix contrast issues', 'equalweb' ); ?></th>
					<td><label><input type="checkbox" id="eqw-ad-contrast" <?php checked( $ad['fix_contrast'] ); ?>>
						<?php esc_html_e( 'Automatically correct low-contrast text', 'equalweb' ); ?></label></td>
				</tr>
				<?php // The two removal controls sit together: the checkbox decides IF the document is ever removed, the dropdown decides AFTER HOW LONG. ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto remove after audit', 'equalweb' ); ?></th>
					<td>
						<label><input type="checkbox" id="eqw-ad-autoremove-enabled" <?php checked( $ad['delete_after_audit'] ); ?>>
							<?php esc_html_e( 'Remove the document from EqualWeb servers after', 'equalweb' ); ?>
							<select id="eqw-ad-autoremove" <?php disabled( ! $ad['delete_after_audit'] ); ?>>
								<?php foreach ( EQW_API_Client::AUTO_REMOVE_DAYS as $days ) : ?>
									<?php /* translators: %d: number of days until the document is auto-removed. */ $label = 365 === $days ? __( '1 year', 'equalweb' ) : sprintf( _n( '%d day', '%d days', $days, 'equalweb' ), $days ); ?>
									<option value="<?php echo esc_attr( $days ); ?>" <?php selected( $ad['auto_remove'], $days ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select></label>
						<p class="description"><?php esc_html_e( 'The remediated file is never deleted immediately: it must stay available long enough to download or apply. Leave this off to keep documents on EqualWeb servers indefinitely.', 'equalweb' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Auto replace original', 'equalweb' ); ?></th>
					<td><label><input type="checkbox" id="eqw-ad-autoreplace" <?php checked( $ad['auto_replace'] ); ?>>
						<?php esc_html_e( 'Automatically replace the original file when remediation completes (a backup is kept)', 'equalweb' ); ?></label></td>
				</tr>
			</table>
			<p>
				<button type="button" class="button button-primary" id="eqw-save-settings"><?php esc_html_e( 'Save', 'equalweb' ); ?></button>
				<?php if ( $has_key ) : ?>
					<button type="button" class="button eqw-danger" id="eqw-remove-key"><?php esc_html_e( 'Remove API key', 'equalweb' ); ?></button>
				<?php endif; ?>
				<span id="eqw-settings-msg" role="status"></span>
			</p>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Permissions                                                         */
	/* ------------------------------------------------------------------ */

	private static function render_permissions() {
		$matrix = EQW_Capabilities::get_matrix();
		// Administrator first — its locked row is the reference for the rest.
		if ( isset( $matrix['administrator'] ) ) {
			$matrix = array( 'administrator' => $matrix['administrator'] ) + $matrix;
		}
		$columns = array(
			EQW_Capabilities::CAP_VIEW      => array( __( 'View files', 'equalweb' ), '' ),
			EQW_Capabilities::CAP_CHECK     => array( __( 'Free check', 'equalweb' ),
				__( 'Free checks share a limited daily quota for this site. Granting Check to more roles consumes the quota faster.', 'equalweb' ) ),
			EQW_Capabilities::CAP_REMEDIATE => array( __( 'Paid remediation', 'equalweb' ),
				__( 'Remediation spends this site\'s shared EqualWeb credits.', 'equalweb' ) ),
			EQW_Capabilities::CAP_REPLACE   => array( __( 'Replace / restore', 'equalweb' ), '' ),
		);
		// The guide's links are built dynamically from THIS site — never
		// hardcoded — so staging/production and subdirectory installs all
		// point at their own screens.
		$dash_url      = admin_url( 'admin.php?page=' . self::SLUG . '&tab=files' );
		$users_url     = admin_url( 'users.php' );
		$new_user_url  = admin_url( 'user-new.php' );
		$settings_url  = admin_url( 'admin.php?page=' . self::SLUG . '&tab=settings' );
		$start_url     = admin_url( 'admin.php?page=' . self::SLUG . '&tab=start' );
		$analytics_url = admin_url( 'admin.php?page=' . self::SLUG . '&tab=analytics' );
		?>
		<div class="eqw-permissions eqw-perm-guide">
			<p class="description"><?php esc_html_e( 'This page explains how to open the PDF dashboard to your team: which permission does what, exactly how to set up a role and a user, what each kind of user will see, and how paid credits are protected. Permissions are edited right here - the matrix below writes real WordPress capabilities, so role manager plugins see the same grants.', 'equalweb' ); ?></p>

			<h2><?php esc_html_e( 'Where is the dashboard?', 'equalweb' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Users with access find it under the', 'equalweb' ); ?> <strong><?php esc_html_e( 'PDF Accessibility', 'equalweb' ); ?></strong> <?php esc_html_e( 'entry in the WordPress admin menu, or directly at', 'equalweb' ); ?>
				<code><?php echo esc_html( $dash_url ); ?></code>.
				<?php esc_html_e( 'The plugin version is shown at the bottom of every tab.', 'equalweb' ); ?>
			</p>

			<h2><?php esc_html_e( 'Permissions', 'equalweb' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Choose what each role may do with PDF accessibility. Granting an action automatically grants "View files". "Visibility" limits a role to the PDFs its users uploaded themselves.', 'equalweb' ); ?></p>
			<table class="widefat fixed striped eqw-perm-table">
				<thead>
				<tr>
					<th class="eqw-perm-role"><?php esc_html_e( 'Role', 'equalweb' ); ?></th>
					<?php foreach ( $columns as $cap => $col ) : ?>
						<th><?php echo esc_html( $col[0] ); ?>
							<?php if ( $col[1] ) : ?>
								<small class="eqw-perm-note"><?php echo esc_html( $col[1] ); ?></small>
							<?php endif; ?>
						</th>
					<?php endforeach; ?>
					<th><?php esc_html_e( 'Visibility', 'equalweb' ); ?></th>
				</tr>
				</thead>
				<tbody>
				<?php foreach ( $matrix as $slug => $role ) : $locked = ( 'administrator' === $slug ); ?>
					<tr data-role="<?php echo esc_attr( $slug ); ?>">
						<td class="eqw-perm-role">
							<strong><?php echo esc_html( $role['name'] ); ?></strong>
							<?php if ( $locked ) : ?>
								<small class="eqw-perm-note"><?php esc_html_e( 'Always has every permission.', 'equalweb' ); ?></small>
							<?php endif; ?>
						</td>
						<?php foreach ( $columns as $cap => $col ) : ?>
							<td>
								<input type="checkbox" class="eqw-perm-cb" data-cap="<?php echo esc_attr( $cap ); ?>"
									<?php checked( $locked || $role['caps'][ $cap ] ); ?> <?php disabled( $locked ); ?>
									aria-label="<?php echo esc_attr( $role['name'] . ': ' . $col[0] ); ?>">
							</td>
						<?php endforeach; ?>
						<td>
							<select class="eqw-perm-scope" <?php disabled( $locked ); ?>
								aria-label="<?php /* translators: %s: role name. */ echo esc_attr( sprintf( __( '%s: visibility', 'equalweb' ), $role['name'] ) ); ?>">
								<option value="" <?php selected( ! $locked && ! $role['caps'][ EQW_Capabilities::CAP_VIEW_ALL ] ); ?>><?php esc_html_e( 'Own files', 'equalweb' ); ?></option>
								<option value="1" <?php selected( $locked || $role['caps'][ EQW_Capabilities::CAP_VIEW_ALL ] ); ?>><?php esc_html_e( 'All files', 'equalweb' ); ?></option>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Files uploaded programmatically (with no owner) are visible only to roles with "All files" visibility.', 'equalweb' ); ?></p>
			<p>
				<button type="button" class="button button-primary" id="eqw-save-permissions"><?php esc_html_e( 'Save permissions', 'equalweb' ); ?></button>
				<span id="eqw-permissions-msg" role="status"></span>
			</p>

			<h2><?php esc_html_e( 'What each permission unlocks', 'equalweb' ); ?></h2>
			<table class="widefat eqw-table eqw-perm-table">
				<thead><tr><th><?php esc_html_e( 'Permission', 'equalweb' ); ?></th><th><?php esc_html_e( 'What it unlocks', 'equalweb' ); ?></th></tr></thead>
				<tbody>
				<tr><td><strong><?php esc_html_e( 'View files', 'equalweb' ); ?></strong></td><td><?php esc_html_e( 'Open the dashboard; see, search and download reports for files the user uploaded. Analytics is filtered to the same files.', 'equalweb' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Visibility: All files', 'equalweb' ); ?></strong></td><td><?php esc_html_e( 'Same, but for every document on the site. Also unlocks the "Activity by user" table in Analytics.', 'equalweb' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Free check', 'equalweb' ); ?></strong></td><td><?php esc_html_e( 'Run free accessibility checks (and follow running jobs). Free checks share a per-site daily quota, or are unlimited once a personal API key is set.', 'equalweb' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Paid remediation', 'equalweb' ); ?></strong></td><td><?php esc_html_e( 'Send documents to paid AI remediation.', 'equalweb' ); ?> <strong><?php esc_html_e( 'This spends credits from the site\'s EqualWeb account', 'equalweb' ); ?></strong><?php esc_html_e( ', so grant it deliberately.', 'equalweb' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Replace / restore', 'equalweb' ); ?></strong></td><td><?php esc_html_e( 'Replace the live file with the accessible version, save it as a new file, or restore the original backup. Touches live site content.', 'equalweb' ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Administrator role', 'equalweb' ); ?></strong></td><td><?php esc_html_e( 'Everything above on every file, plus the Settings, PDF AI Remediation, Start Auto Remediation and Permissions tabs, the API key and the update banner.', 'equalweb' ); ?></td></tr>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'The PDF AI Remediation, Start Auto Remediation, Settings and Permissions tabs are admin-only: they cover buying credits and configuring the API key, which other users cannot act on.', 'equalweb' ); ?></p>

			<h2><?php esc_html_e( 'Set up an editor in four steps', 'equalweb' ); ?></h2>
			<ol class="eqw-perm-steps">
				<li><strong><?php esc_html_e( 'Open this tab', 'equalweb' ); ?></strong> - <?php esc_html_e( 'the matrix above lists every role on the site with the plugin\'s permissions.', 'equalweb' ); ?></li>
				<li><strong><?php esc_html_e( 'Tick the capabilities each role should have', 'equalweb' ); ?></strong> <?php esc_html_e( 'and save. A typical editor gets', 'equalweb' ); ?> <em><?php esc_html_e( 'View files', 'equalweb' ); ?></em> + <em><?php esc_html_e( 'Free check', 'equalweb' ); ?></em>; <?php esc_html_e( 'a trusted content manager adds', 'equalweb' ); ?> <em><?php esc_html_e( 'Paid remediation', 'equalweb' ); ?></em> + <em><?php esc_html_e( 'Replace / restore', 'equalweb' ); ?></em> <?php esc_html_e( 'and, when they should manage everyone\'s documents, the "All files" visibility.', 'equalweb' ); ?></li>
				<li><strong><?php esc_html_e( 'Or use any role manager plugin', 'equalweb' ); ?></strong> - <?php esc_html_e( 'the matrix writes real WordPress capabilities', 'equalweb' ); ?> (<code>eqw_pdf_view</code>, <code>eqw_pdf_check</code>, <code>eqw_pdf_remediate</code>, <code>eqw_pdf_replace</code>, <code>eqw_pdf_view_all</code>), <?php esc_html_e( 'so external role editors see and change the same grants.', 'equalweb' ); ?></li>
				<li><strong><?php esc_html_e( 'Assign the role to the user', 'equalweb' ); ?></strong> <?php esc_html_e( 'under', 'equalweb' ); ?> <a href="<?php echo esc_url( $users_url ); ?>"><?php esc_html_e( 'Users', 'equalweb' ); ?></a>. <?php esc_html_e( 'The user logs in, opens PDF Accessibility, and sees exactly what the role allows - nothing needs to be configured in this plugin per user.', 'equalweb' ); ?></li>
			</ol>
			<p class="description"><?php esc_html_e( 'To take access away, untick the capabilities in the matrix or change the user\'s role. Changes apply on the user\'s next page load.', 'equalweb' ); ?></p>

			<h2><?php esc_html_e( 'Who sees what', 'equalweb' ); ?></h2>
			<table class="widefat eqw-table eqw-perm-table">
				<thead><tr><th></th><th><?php esc_html_e( 'Editor (view own + check)', 'equalweb' ); ?></th><th><?php esc_html_e( 'Manager (view all + remediate + replace)', 'equalweb' ); ?></th><th><?php esc_html_e( 'Administrator', 'equalweb' ); ?></th></tr></thead>
				<tbody>
				<tr><td><?php esc_html_e( 'Documents list', 'equalweb' ); ?></td><td><?php esc_html_e( 'Own files only', 'equalweb' ); ?></td><td><?php esc_html_e( 'All files', 'equalweb' ); ?></td><td><?php esc_html_e( 'All files', 'equalweb' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Analytics', 'equalweb' ); ?></td><td><?php esc_html_e( 'Filtered to own files', 'equalweb' ); ?></td><td><?php esc_html_e( 'All files + Activity by user', 'equalweb' ); ?></td><td><?php esc_html_e( 'All files + Activity by user', 'equalweb' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Free checks', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Paid remediation', 'equalweb' ); ?></td><td><?php esc_html_e( 'No', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Replace / restore live files', 'equalweb' ); ?></td><td><?php esc_html_e( 'No', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td></tr>
				<tr><td><?php esc_html_e( 'Settings, API key, AI and Start tabs', 'equalweb' ); ?></td><td><?php esc_html_e( 'No', 'equalweb' ); ?></td><td><?php esc_html_e( 'No', 'equalweb' ); ?></td><td><?php esc_html_e( 'Yes', 'equalweb' ); ?></td></tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Credits, spending and attribution', 'equalweb' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The API key belongs to the site: one installation works against one EqualWeb account and its credit pool (the key is entered once by an administrator in', 'equalweb' ); ?>
				<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'equalweb' ); ?></a>;
				<?php esc_html_e( 'see', 'equalweb' ); ?> <a href="<?php echo esc_url( $start_url ); ?>"><?php esc_html_e( 'Start Auto Remediation', 'equalweb' ); ?></a> <?php esc_html_e( 'for how to open an account and buy credits).', 'equalweb' ); ?>
				<?php esc_html_e( 'Free accessibility checks cost nothing: without a key they share a per-site daily quota, with a key they are unlimited.', 'equalweb' ); ?>
				<?php esc_html_e( 'Paid AI remediation spends credits - the', 'equalweb' ); ?> <em><?php esc_html_e( 'Paid remediation', 'equalweb' ); ?></em> <?php esc_html_e( 'capability decides who may spend from the pool, which is why it is not granted by default.', 'equalweb' ); ?>
				<?php esc_html_e( 'Every check and remediation is attributed to the user who started it, and users with "All files" visibility or admin access see the breakdown in', 'equalweb' ); ?>
				<a href="<?php echo esc_url( $analytics_url ); ?>"><?php esc_html_e( 'Analytics', 'equalweb' ); ?> &raquo; <?php esc_html_e( 'Activity by user', 'equalweb' ); ?></a> -
				<?php esc_html_e( 'useful for internal cost attribution when several departments share one site.', 'equalweb' ); ?>
			</p>

			<h2><?php esc_html_e( 'Troubleshooting', 'equalweb' ); ?></h2>
			<ul class="eqw-perm-faq">
				<li><strong><?php esc_html_e( '"The user cannot see the menu entry"', 'equalweb' ); ?></strong> - <?php esc_html_e( 'the role is missing the View files capability. Tick it in the matrix above (granting any action grants it automatically).', 'equalweb' ); ?></li>
				<li><strong><?php esc_html_e( '"Send to remediation does not appear"', 'equalweb' ); ?></strong> - <?php esc_html_e( 'the role is missing the Paid remediation capability, or no API key is set in Settings.', 'equalweb' ); ?></li>
				<li><strong><?php esc_html_e( '"The user sees files that are not theirs"', 'equalweb' ); ?></strong> - <?php esc_html_e( 'the role\'s Visibility is set to "All files". Switch it back to "Own files".', 'equalweb' ); ?></li>
				<li><strong><?php esc_html_e( '"Changes do not apply"', 'equalweb' ); ?></strong> - <?php esc_html_e( 'capability changes take effect on the user\'s next page load. Ask the user to reload the page or log in again.', 'equalweb' ); ?></li>
			</ul>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( $users_url ); ?>"><?php esc_html_e( 'Manage users', 'equalweb' ); ?></a>
				<a class="button" href="<?php echo esc_url( $new_user_url ); ?>"><?php esc_html_e( 'Add new user', 'equalweb' ); ?></a>
			</p>
		</div>
		<?php
	}
}
