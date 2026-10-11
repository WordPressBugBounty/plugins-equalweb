<?php
/**
 * HTTP client for the EqualWeb PDF API.
 *
 * Two backends share the same endpoint shape:
 *  - Free checks:  the EqualWeb check proxy (Cloudflare Worker). The proxy
 *    holds the shared API key and only allows check-type processing.
 *  - Remediation:  login.equalweb.com directly, using the site owner's
 *    personal API key (stored encrypted in wp_options).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EQW_API_Client {

	const API_BASE = 'https://login.equalweb.com/api/v2';

	const OPT_API_KEY        = 'eqw_pdf_api_key';
	const OPT_SITE_TOKEN     = EQWPDF_Registration::OPT_SITE_TOKEN;
	const OPT_REG_ERROR      = EQWPDF_Registration::OPT_REG_ERROR;
	const OPT_AUDIT_DEFAULTS = 'eqw_pdf_audit_defaults';

	/**
	 * Retention periods offered for autoRemove, in days. EqualWeb takes an
	 * INTEGER 1..365 here and has no "never" value: "never" is the removal
	 * checkbox being off, which sends no autoRemove at all. The settings
	 * dropdown and audit_defaults() are both built from this set, so the screen
	 * can never offer a day count the API would reject.
	 */
	const AUTO_REMOVE_DAYS = array( 1, 30, 60, 90, 365 );

	/** @var string Base URL, endpoints are appended to it. */
	private $base;

	/** @var array Auth headers added to every request. */
	private $headers;

	/** @var string Which EqualWeb backend this client talks to: 'key' | 'proxy'. */
	private $backend;

	private function __construct( $base, array $headers, $backend = 'key' ) {
		$this->base    = rtrim( $base, '/' );
		$this->backend = $backend;
		// '+' (not array_merge): the auth headers always win over attribution.
		$this->headers = $headers + self::attribution_headers();
	}

	/**
	 * Installation/tenant attribution on EVERY call (multi-tenant research,
	 * 2026-07): usage must be attributable server-side before per-tenant
	 * quotas, reports or reseller sub-keys can ever exist. Single-site
	 * WordPress has no tenancy, so the tenant field carries the site host;
	 * on multisite it is prefixed with the blog id so every network site
	 * stays distinguishable. Attribution must never break an API call.
	 *
	 * @return array
	 */
	private static function attribution_headers() {
		try {
			$host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$tenant = is_multisite() ? 'blog-' . get_current_blog_id() . ':' . $host : $host;
			return array(
				'X-Eqw-Site'     => $host,
				'X-Eqw-Platform' => defined( 'EQW_PDF_PLATFORM' ) ? EQW_PDF_PLATFORM : 'wordpress',
				'X-Eqw-Tenant'   => $tenant,
				'X-Eqw-Plugin'   => defined( 'EQW_PDF_VERSION' ) ? EQW_PDF_VERSION : '',
			);
		} catch ( \Throwable $e ) {
			return array( 'X-Eqw-Platform' => 'wordpress' );
		}
	}

	/**
	 * Which backend a NEW check would run on: 'key' (personal API key, straight
	 * to EqualWeb) or 'proxy' (the shared, rate-limited check proxy).
	 *
	 * Recorded on the row at check start and passed back to for_check() at poll
	 * time, so a run always keeps talking to the account that holds its doc id.
	 *
	 * @return string
	 */
	public static function check_backend() {
		return '' !== self::get_personal_key() ? 'key' : 'proxy';
	}

	/**
	 * Client for free accessibility checks.
	 *
	 * @param string|null $backend Bind the client to the backend a running check
	 *                             was STARTED on ('key' | 'proxy'). Null picks
	 *                             the backend for a new run (key first).
	 * @return EQW_API_Client|WP_Error
	 */
	public static function for_check( $backend = null ) {
		$key = self::get_personal_key();

		// A run is BOUND to the backend it started on: its doc id exists in that
		// ONE EqualWeb account. Re-deciding per request would send the poll of a
		// check that is already running to the other account the moment the site
		// key is saved or removed — the doc is not found there, so the row gets
		// reset and a healthy running check is discarded with a false "no longer
		// on the EqualWeb service" message.
		$use_key = ( null === $backend ) ? ( '' !== $key ) : ( 'key' === $backend );

		if ( $use_key ) {
			// A customer who bought a personal API key gets UNLIMITED free checks
			// straight from EqualWeb — checks cost no credits, so there is no reason
			// to route them through the shared proxy and its per-day rate limit.
			// The rate-limited proxy is only for sites that have no key of their own.
			if ( '' === $key ) {
				return new WP_Error(
					'eqw_no_api_key',
					__( 'This check was started with the site API key, which is no longer configured. Add the key back in the settings tab, or run the check again.', 'equalweb' )
				);
			}
			return new self( self::API_BASE, array( 'x-a11y-api-key' => $key ) );
		}

		// A token is bound to the host it was issued for. When the site moved
		// (staging to production is the common case), or is a copy of another
		// site, register this address again, on the same schedule as the
		// automatic attempts from the admin page, so a failing registration
		// does not hold up every check with a fresh 30 s POST.
		if ( null !== EQWPDF_Registration::moved_from() && EQWPDF_Registration::auto_due() ) {
			self::register_site( 'host_change' );
		}

		// Only a token issued for this host is a registration here: the service
		// refuses one issued for the previous address, so every check would fail
		// with a misleading reason. Without one, say what is missing, also to a
		// running check's poll, in the words for the user's role.
		$token = (string) get_option( self::OPT_SITE_TOKEN, '' );
		if ( '' !== $token && null === EQWPDF_Registration::moved_from() ) {
			return new self( EQW_PDF_WORKER_URL . '/check', array(
				'x-eqw-site-token' => $token,
				'x-eqw-site-host'  => EQWPDF_Registration::current_host(),
			), 'proxy' );
		}
		return new WP_Error( 'eqw_not_registered', EQWPDF_Registration::not_registered_message() );
	}

	/**
	 * Client for paid remediation. Requires the personal API key.
	 *
	 * @return EQW_API_Client|WP_Error
	 */
	public static function for_remediation() {
		$key = self::get_personal_key();
		if ( ! $key ) {
			// Distinguish "no key saved" from "a key is saved but can no longer
			// be decrypted" (the usual cause is a rotated AUTH_KEY salt), so the
			// user is told to re-enter it instead of seeing a misleading message.
			$msg = self::key_decrypt_failed()
				? __( 'Your saved EqualWeb API key could not be decrypted (the site security keys may have changed). Re-enter your API key in the settings tab.', 'equalweb' )
				: __( 'Remediation requires an EqualWeb API key. Add it in the settings tab.', 'equalweb' );
			return new WP_Error( 'eqw_no_api_key', $msg );
		}
		return new self( self::API_BASE, array( 'x-a11y-api-key' => $key ) );
	}

	/**
	 * True when a personal key is stored but cannot be decrypted (e.g. the
	 * AUTH_KEY salt used to derive the encryption key was rotated).
	 *
	 * @return bool
	 */
	public static function key_decrypt_failed() {
		$stored = get_option( self::OPT_API_KEY );
		return $stored && '' === self::decrypt( $stored );
	}

	/* ------------------------------------------------------------------ */
	/* Endpoints                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Upload a local file. Returns the document ID (also the report ID).
	 *
	 * @param string $path     Local file path.
	 * @param string $filename Filename sent to EqualWeb (defaults to basename).
	 * @param string $mime     The part's REAL mime type: office documents
	 *                         (Word/PowerPoint/Excel) must not be mislabeled as
	 *                         PDF or the conversion on EqualWeb's side goes wrong.
	 * @return string|WP_Error
	 */
	public function upload_file( $path, $filename = null, $mime = 'application/pdf' ) {
		if ( ! file_exists( $path ) ) {
			return new WP_Error( 'eqw_file_missing', __( 'File not found on disk.', 'equalweb' ) );
		}
		// Read BEFORE building the body: an unreadable file (permissions, a
		// vanished network mount) returns false, which concatenates into the
		// multipart body as '' — EqualWeb would happily accept a 0-byte document
		// and the caller would spend a credit remediating nothing.
		$content = @file_get_contents( $path );
		if ( false === $content || '' === $content ) {
			return new WP_Error( 'eqw_file_unreadable', __( 'The file could not be read from disk, or it is empty.', 'equalweb' ) );
		}
		$filename = $filename ? $filename : basename( $path );
		$mime     = $mime ? $mime : 'application/pdf';
		$boundary = '----eqwpdf' . wp_generate_password( 24, false );

		// Content-Disposition filename is a literal quoted string — it must NOT
		// be percent-encoded (that would send "my%20file.pdf" as the name).
		// Strip CR/LF and escape the quote/backslash for the quoted-string; add
		// an RFC 5987 filename* so servers still get the exact UTF-8 name when
		// it contains non-ASCII characters.
		$safe_name  = str_replace( array( "\r", "\n", '\\', '"' ), array( '', '', '', '' ), $filename );
		$ascii_name = preg_replace( '/[^\x20-\x7E]/', '_', $safe_name );
		$disposition = 'form-data; name="file"; filename="' . $ascii_name . '"';
		if ( $ascii_name !== $safe_name ) {
			$disposition .= "; filename*=UTF-8''" . rawurlencode( $filename );
		}

		$body  = '--' . $boundary . "\r\n";
		$body .= 'Content-Disposition: ' . $disposition . "\r\n";
		$body .= 'Content-Type: ' . str_replace( array( "\r", "\n" ), '', $mime ) . "\r\n\r\n";
		$body .= $content;
		$body .= "\r\n--" . $boundary . "--\r\n";

		$res = $this->request( 'PUT', '/docs/upload?mode=sync', array(
			'headers' => array( 'Content-Type' => 'multipart/form-data; boundary=' . $boundary ),
			'body'    => $body,
			'timeout' => 120,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['id'] ) ) {
			return new WP_Error( 'eqw_upload_failed', __( 'Upload failed: no document ID returned.', 'equalweb' ) );
		}
		return $res['id'];
	}

	/**
	 * Public URL of the browser-viewable report for a document.
	 */
	public static function report_url( $doc_id ) {
		return 'https://login.equalweb.com/reports/pdf/' . rawurlencode( $doc_id );
	}

	/**
	 * The stored audit defaults, normalised. The single reader: the settings
	 * screen and the remediation payload must never disagree about what is set.
	 *
	 * @return array
	 */
	public static function audit_defaults() {
		$stored = get_option( self::OPT_AUDIT_DEFAULTS, array() );
		$ad     = wp_parse_args( is_array( $stored ) ? $stored : array(), array(
			'add_signature'      => true,
			'fix_contrast'       => false,
			// Whether EqualWeb ever removes the document. The stored key is the
			// legacy name: it used to carry the immediate-delete flag, which is
			// never sent now (see audit_extra()).
			'delete_after_audit' => true,
			'auto_remove'        => 30,
			'auto_replace'       => false,
		) );
		// A day count the dropdown cannot offer (a value stored by an older
		// version, a crafted POST) would leave the screen showing one retention
		// while the API is told another, so it falls back to the default.
		$days = (int) $ad['auto_remove'];
		if ( ! in_array( $days, self::AUTO_REMOVE_DAYS, true ) ) {
			$days = 30;
		}

		return array(
			'add_signature'      => (bool) $ad['add_signature'],
			'fix_contrast'       => (bool) $ad['fix_contrast'],
			'delete_after_audit' => (bool) $ad['delete_after_audit'],
			'auto_remove'        => $days,
			'auto_replace'       => (bool) $ad['auto_replace'],
		);
	}

	/**
	 * The `extra` payload for start_process( 'tag' ), in EqualWeb's key names.
	 *
	 * @return array
	 */
	public static function audit_extra() {
		$ad = self::audit_defaults();

		// The booleans are sent EXPLICITLY, false included. An omitted false does
		// not read as "off" to EqualWeb, it reads as "unspecified", so EqualWeb
		// applies its OWN default and the user's choice never arrives.
		$extra = array(
			'addSignature' => $ad['add_signature'],
			'fixContrast'  => $ad['fix_contrast'],
			// NEVER true. deleteAfterAudit destroys the audited output the moment
			// the audit completes (the download 404s seconds later), erasing the
			// remediated file before it can be downloaded or applied. Retention is
			// governed by autoRemove (days); immediate deletion is not offered.
			'deleteAfterAudit' => false,
		);

		// The checkbox decides WHETHER the document is ever removed; autoRemove
		// only says AFTER HOW LONG. With the checkbox off, no removal instruction
		// is sent at all, whatever the day count holds.
		if ( $ad['delete_after_audit'] && $ad['auto_remove'] > 0 ) {
			$extra['autoRemove'] = min( 365, max( 1, $ad['auto_remove'] ) );
		}

		return $extra;
	}

	/**
	 * Start processing an uploaded document.
	 *
	 * @param string $doc_id
	 * @param string $type  'check' (free report) or 'tag' (remediation).
	 * @param array  $extra Optional audit options (addSignature, fixContrast,
	 *                      autoRemove, deleteAfterAudit).
	 * @return true|WP_Error
	 */
	public function start_process( $doc_id, $type, array $extra = array() ) {
		$res = $this->request( 'POST', '/docs/audit', array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array_merge( $extra, array(
				'files' => array( $doc_id ),
				'type'  => $type,
			) ) ),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// EqualWeb can accept the call (HTTP 202) yet not run the process —
		// e.g. {"results":"TEST MODE - no audit performed"} when the key's
		// account is in test mode. Treating that as success wedges the row
		// in 'checking'/'remediating' forever, so fail fast instead.
		if ( isset( $res['results'] ) && is_string( $res['results'] ) && preg_match( '/test\s*mode|no\s*audit/i', $res['results'] ) ) {
			return new WP_Error( 'eqw_audit_not_started', __( 'EqualWeb accepted the file but did not start processing (the API key account is in TEST MODE). Ask EqualWeb to activate the key and try again.', 'equalweb' ) );
		}
		return true;
	}

	/**
	 * Processing status of a document ('ready' → 'check'/processing → 'done').
	 *
	 * @return array|WP_Error  The document entry from GET /docs.
	 */
	public function get_status( $doc_id ) {
		$docs = $this->request( 'GET', '/docs' );
		if ( is_wp_error( $docs ) ) {
			return $docs;
		}
		foreach ( (array) $docs as $doc ) {
			if ( isset( $doc['reportID'] ) && $doc['reportID'] === $doc_id ) {
				return $doc;
			}
		}
		return new WP_Error( 'eqw_doc_not_found', __( 'Document not found on the EqualWeb service.', 'equalweb' ) );
	}

	/**
	 * Fetch a report. Check-type documents only have an 'original' report;
	 * remediated documents also have an 'audited' one.
	 *
	 * @return array|WP_Error
	 */
	public function get_report( $report_id, $type = 'original' ) {
		return $this->request( 'GET', '/docs/report/' . rawurlencode( $report_id ) . '?type=' . rawurlencode( $type ) );
	}

	/**
	 * Download a document (the audited/remediated version by default)
	 * to a local file.
	 *
	 * @return true|WP_Error
	 */
	public function download_to_file( $report_id, $dest, $original = false ) {
		$url = $this->base . '/docs/download/file/' . rawurlencode( $report_id ) . ( $original ? '?original=true' : '' );
		$res = wp_remote_get( $url, array(
			'headers'  => $this->headers,
			'timeout'  => 180,
			'stream'   => true,
			'filename' => $dest,
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== $code ) {
			wp_delete_file( $dest );
			/* translators: %d: HTTP status code returned by the failed download request. */
			return new WP_Error( 'eqw_download_failed', sprintf( __( 'Download failed (HTTP %d).', 'equalweb' ), $code ) );
		}
		return true;
	}

	/**
	 * Credit balance for the account behind this client's key.
	 *
	 * @return float|WP_Error
	 */
	public function balance() {
		$res = $this->request( 'GET', '/docs/info/balance' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return isset( $res['results'] ) ? (float) $res['results'] : new WP_Error( 'eqw_bad_response', __( 'Unexpected balance response.', 'equalweb' ) );
	}

	/**
	 * Validate an arbitrary API key against the balance endpoint without
	 * saving it. Returns the balance on success.
	 *
	 * @return float|WP_Error
	 */
	public static function validate_key( $key ) {
		$client = new self( self::API_BASE, array( 'x-a11y-api-key' => $key ) );
		return $client->balance();
	}

	/* ------------------------------------------------------------------ */
	/* Site registration with the check proxy                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Register this site with the check proxy to enable free checks.
	 * Publishes a one-time nonce at /wp-json/eqw/v1/verify which the proxy
	 * fetches to prove domain ownership, then stores the returned site token.
	 *
	 * The request always goes out, so the service sees every attempt; the one
	 * exception is WordPress itself refusing it (WP_HTTP_BLOCK_EXTERNAL), which
	 * the HTTP API reports without any network call. The classified outcome is
	 * stored for the registration notice (EQWPDF_Registration::record()).
	 *
	 * @param string $trigger What started it: 'page' (automatically, after the
	 *                        plugin page loaded), 'button' (Try again), 'check'
	 *                        (before the first check) or 'host_change' (the site moved).
	 * @return true|WP_Error 'eqw_register_failed' (data: the classified outcome)
	 *                       or 'eqw_register_busy'.
	 */
	public static function register_site( $trigger = 'page' ) {
		global $wpdb;
		// One registration at a time: two would overwrite each other's nonce and
		// fail with a code that wrongly blames the site. GET_LOCK names are
		// server-wide, so the name carries the database and table prefix: other
		// sites on the same MySQL server must not block this one.
		$lock = 'register|' . DB_NAME . '|' . $wpdb->prefix;
		if ( ! EQW_File_Manager::db_lock( $lock ) ) {
			return new WP_Error( 'eqw_register_busy', __( 'A registration for this site is already running. Wait a minute, then reload this page.', 'equalweb' ) );
		}
		try {
			// Stamped here, not by the callers, so a failed Try again is not
			// followed by an automatic attempt on the next page load.
			update_option( EQWPDF_Registration::OPT_REG_ATTEMPTED, time(), false );
			$context = array(
				'trigger'        => $trigger,
				'plugin'         => EQW_PDF_VERSION,
				'platform'       => EQWPDF_Registration::wp_version(),
				'verify_url'     => EQWPDF_Registration::verify_url(),
				'site_url'       => home_url(),
				'worker_version' => null,
			);
			$outcome = self::post_registration( EQW_PDF_WORKER_URL . '/register', $trigger, $context );
			EQWPDF_Registration::record( $outcome, $context );
			if ( $outcome['ok'] ) {
				return true;
			}
			return new WP_Error( 'eqw_register_failed', EQWPDF_Registration::why( $outcome ), $outcome );
		} finally {
			EQW_File_Manager::db_unlock( $lock );
		}
	}

	/**
	 * Send the registration request and classify the answer.
	 *
	 * @param string $workerurl The service's /register address.
	 * @param string $trigger   What started the registration.
	 * @param array  $context   The attempt's details; worker_version is filled in.
	 * @return array The outcome, from EQWPDF_Registration::classify().
	 */
	private static function post_registration( $workerurl, $trigger, array &$context ) {
		$nonce = wp_generate_password( 32, false );
		set_transient( EQWPDF_Registration::TRANSIENT_NONCE, $nonce, 5 * MINUTE_IN_SECONDS );
		// The HTTP API has one timeout for connecting and reading. A server
		// whose outbound traffic is silently dropped should give up on the
		// connection in about 10 s, not hold the page for the full 30 s.
		$connect = function ( $handle, $args, $url ) use ( $workerurl ) {
			if ( $url === $workerurl && function_exists( 'curl_setopt' ) ) {
				curl_setopt( $handle, CURLOPT_CONNECTTIMEOUT, 10 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- tunes the connect timeout of the HTTP API's own cURL handle; the request itself goes through wp_remote_post().
			}
		};
		add_action( 'http_api_curl', $connect, 10, 3 );
		try {
			$res = wp_remote_post( $workerurl, array(
				'headers' => array( 'Content-Type' => 'application/json' ) + self::attribution_headers(),
				'body'    => wp_json_encode( array(
					'site_url'         => $context['site_url'],
					'verify_url'       => $context['verify_url'],
					'nonce'            => $nonce,
					// Lets the shared proxy tag registrations per platform. Every
					// port defines its own EQW_PDF_PLATFORM; the proxy defaults a
					// missing value to "wordpress".
					'platform'         => defined( 'EQW_PDF_PLATFORM' ) ? EQW_PDF_PLATFORM : 'wordpress',
					'edition'          => 'wp',
					'plugin_version'   => $context['plugin'],
					'platform_version' => $context['platform'],
					'trigger'          => $trigger,
				) ),
				// The service fetches the verify route while this request waits and
				// gives up on that after 15 s, so 25 s covers a full round trip and
				// still ends inside PHP's default 30 s execution limit on hosts
				// (Windows) where the time spent waiting on the network counts.
				'timeout' => 25,
			) );
		} finally {
			remove_action( 'http_api_curl', $connect, 10 );
			delete_transient( EQWPDF_Registration::TRANSIENT_NONCE );
		}

		if ( is_wp_error( $res ) ) {
			// The message is stored and shown in the technical details that admins
			// send to support, so the proxy password must not be in it.
			$transport = array(
				'message' => self::scrub( $res->get_error_message() ),
				'proxy'   => defined( 'WP_PROXY_HOST' ) && WP_PROXY_HOST,
			);
			if ( 'http_request_not_executed' === $res->get_error_code() ) {
				// WP_HTTP_BLOCK_EXTERNAL: WordPress refused the request before any
				// network call, so only a configuration change can let it out.
				$transport['sub'] = 'policy';
			}
			return EQWPDF_Registration::classify( 0, '', array(), $transport );
		}
		$headers = array();
		$raw     = wp_remote_retrieve_headers( $res );
		$raw     = ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) ? $raw->getAll() : (array) $raw;
		foreach ( $raw as $name => $value ) {
			$headers[ strtolower( (string) $name ) ] = is_array( $value ) ? (string) reset( $value ) : (string) $value;
		}
		$context['worker_version'] = isset( $headers['x-eqw-worker-version'] ) ? $headers['x-eqw-worker-version'] : null;
		return EQWPDF_Registration::classify( (int) wp_remote_retrieve_response_code( $res ), (string) wp_remote_retrieve_body( $res ), $headers );
	}

	/**
	 * The explanation of a check-time refusal from the free-check service (the
	 * registration is no longer valid, or the daily limit is used up), or null
	 * for any other answer.
	 *
	 * The token is never cleared. A refused token is recorded instead
	 * (EQWPDF_Registration::note_rejection()), so the notice shows the
	 * registration as no longer valid, with Try again.
	 *
	 * @param int   $status HTTP status.
	 * @param mixed $body   Decoded JSON body.
	 * @return string|null
	 */
	private function proxy_refusal( $status, $body ) {
		if ( 'proxy' !== $this->backend ) {
			return null;
		}
		$refusal = EQWPDF_Registration::check_code( (int) $status, is_array( $body ) ? $body : null );
		if ( null === $refusal ) {
			return null;
		}
		if ( 'token_invalid' !== $refusal['code'] ) {
			return EQWPDF_Registration::why( $refusal ) . ' ' . EQWPDF_Registration::todo( $refusal );
		}
		EQWPDF_Registration::note_rejection( (string) ( isset( $this->headers['x-eqw-site-token'] ) ? $this->headers['x-eqw-site-token'] : '' ) );
		// Only admins can act on an invalid registration; everyone else gets one
		// line. Admins are sent to the notice, which shows from the next page load.
		if ( ! current_user_can( 'manage_options' ) ) {
			return EQWPDF_Registration::not_registered_message();
		}
		return EQWPDF_Registration::why( $refusal ) . ' ' . __( 'Reload this page: the notice at the top of the page explains what to do.', 'equalweb' );
	}

	/**
	 * Note a successful answer from the free-check service: it accepted this
	 * site's token, which ends any earlier refusal on record.
	 */
	private function proxy_accepted() {
		if ( 'proxy' === $this->backend ) {
			EQWPDF_Registration::note_acceptance( (string) ( isset( $this->headers['x-eqw-site-token'] ) ? $this->headers['x-eqw-site-token'] : '' ) );
		}
	}

	/**
	 * A transport error message without the proxy credentials WordPress sends
	 * (WP_PROXY_USERNAME / WP_PROXY_PASSWORD); see
	 * EQWPDF_Registration::redact_proxy(). Every registration transport message
	 * goes through here before it is stored or shown, since admins send the
	 * registration details to support.
	 *
	 * @param string $message The message.
	 * @return string
	 */
	public static function scrub( $message ) {
		return EQWPDF_Registration::redact_proxy(
			(string) $message,
			defined( 'WP_PROXY_USERNAME' ) ? (string) WP_PROXY_USERNAME : '',
			defined( 'WP_PROXY_PASSWORD' ) ? (string) WP_PROXY_PASSWORD : ''
		);
	}

	/* ------------------------------------------------------------------ */
	/* Personal API key storage (encrypted at rest)                        */
	/* ------------------------------------------------------------------ */

	public static function get_personal_key() {
		$stored = get_option( self::OPT_API_KEY );
		if ( ! $stored ) {
			return '';
		}
		return self::decrypt( $stored );
	}

	/**
	 * Store (or, for an empty value, remove) the personal API key.
	 *
	 * @param string $key
	 * @return true|WP_Error Error when the key cannot be stored SECURELY — it is
	 *                       then not stored at all.
	 */
	public static function set_personal_key( $key ) {
		$key = trim( $key );
		if ( '' === $key ) {
			delete_option( self::OPT_API_KEY );
			return true;
		}
		$stored = self::encrypt( $key );
		if ( is_wp_error( $stored ) ) {
			return $stored;
		}
		update_option( self::OPT_API_KEY, $stored, false );
		return true;
	}

	/** The site secret both storage keys are derived from. */
	private static function secret() {
		return defined( 'AUTH_KEY' ) ? AUTH_KEY : DB_PASSWORD . ABSPATH;
	}

	/**
	 * Legacy 'enc:' cipher key. Frozen: changing the derivation would make every
	 * key stored before 2.6.3 undecryptable.
	 */
	private static function encryption_key() {
		return hash( 'sha256', self::secret() . 'eqw-pdf', true );
	}

	/** 'enc2:' cipher key (separate from the MAC key — never reuse one key for both). */
	private static function cipher_key() {
		return hash( 'sha256', self::secret() . 'eqw-pdf-enc', true );
	}

	/** 'enc2:' authentication key. */
	private static function mac_key() {
		return hash( 'sha256', self::secret() . 'eqw-pdf-mac', true );
	}

	/**
	 * Encrypt-then-MAC ('enc2:'): base64( iv | ciphertext | HMAC-SHA256 ).
	 *
	 * Without OpenSSL the key is NOT stored: writing it in cleartext (what the
	 * old 'plain:' format did) leaves a live API key readable to anything that
	 * can read the options table or a database dump.
	 *
	 * @param string $plain
	 * @return string|WP_Error
	 */
	private static function encrypt( $plain ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new WP_Error(
				'eqw_no_openssl',
				__( 'This site cannot store the API key securely (the PHP OpenSSL extension is missing). Ask your host to enable OpenSSL.', 'equalweb' )
			);
		}
		$iv     = random_bytes( 16 );
		$cipher = openssl_encrypt( $plain, 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return new WP_Error( 'eqw_encrypt_failed', __( 'The API key could not be encrypted for storage.', 'equalweb' ) );
		}
		$mac = hash_hmac( 'sha256', $iv . $cipher, self::mac_key(), true );
		return 'enc2:' . base64_encode( $iv . $cipher . $mac );
	}

	/**
	 * Read any format this plugin has ever written. The legacy 'plain:' and
	 * 'enc:' readers MUST stay: an upgrade must never lose a stored key.
	 *
	 * @param string $stored
	 * @return string Empty string when the value cannot be recovered.
	 */
	private static function decrypt( $stored ) {
		if ( 0 === strpos( $stored, 'enc2:' ) ) {
			if ( ! function_exists( 'openssl_decrypt' ) ) {
				return '';
			}
			$raw = base64_decode( substr( $stored, 5 ), true );
			// 16-byte IV + at least one 16-byte AES block + 32-byte MAC.
			if ( ! is_string( $raw ) || strlen( $raw ) < 64 ) {
				return '';
			}
			$mac    = substr( $raw, -32 );
			$signed = substr( $raw, 0, -32 );
			// Verify BEFORE decrypting, in constant time: a modified ciphertext
			// must be rejected outright, never fed to the cipher.
			if ( ! hash_equals( hash_hmac( 'sha256', $signed, self::mac_key(), true ), $mac ) ) {
				return '';
			}
			$out = openssl_decrypt( substr( $signed, 16 ), 'aes-256-cbc', self::cipher_key(), OPENSSL_RAW_DATA, substr( $signed, 0, 16 ) );
			return false === $out ? '' : $out;
		}
		if ( 0 === strpos( $stored, 'plain:' ) ) {
			return base64_decode( substr( $stored, 6 ) );
		}
		if ( 0 === strpos( $stored, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 4 ) );
			$iv  = substr( $raw, 0, 16 );
			$out = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', self::encryption_key(), OPENSSL_RAW_DATA, $iv );
			return false === $out ? '' : $out;
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Low-level request helper                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * @return array|WP_Error Decoded JSON body.
	 */
	private function request( $method, $path, array $args = array() ) {
		$args = array_merge( array( 'timeout' => 60 ), $args );
		$args['method']  = $method;
		$args['headers'] = array_merge( $this->headers, isset( $args['headers'] ) ? $args['headers'] : array() );

		$res = wp_remote_request( $this->base . $path, $args );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 ) {
			// A refused registration or a used-up daily limit on the free-check
			// service is explained in the user's words (and the refusal is
			// recorded); every other error is passed through as answered.
			$refusal = $this->proxy_refusal( $code, $body );
			if ( null !== $refusal ) {
				return new WP_Error( 'eqw_api_error', $refusal, array( 'status' => $code ) );
			}
			/* translators: %d: HTTP status code returned by the EqualWeb API. */
			$msg = is_array( $body ) && isset( $body['error'] ) ? $body['error'] : sprintf( __( 'EqualWeb API error (HTTP %d).', 'equalweb' ), $code );
			return new WP_Error( 'eqw_api_error', $msg, array( 'status' => $code ) );
		}
		$this->proxy_accepted();
		return is_array( $body ) ? $body : array();
	}
}
