<?php
/**
 * Free-check registration: what the EqualWeb service answered, and what to tell the admin.
 *
 * The service answers every failed registration with a short `code` (for
 * example verify_blocked) next to its `error` text. classify() reads the code
 * first. An older service, or a message stored by an older version of this
 * plugin, has only the error text; LEGACY maps those texts to the same codes.
 *
 * The classification functions (classify(), classify_stored(), legacy_rule(),
 * transport_sub(), check_code(), next_auto_at(), redact_proxy()) are pure: they
 * read no WordPress state, so they can be tested without a WordPress site
 * (wordpress/tests-registration/run.php). The functions below them read the
 * plugin options and the translated strings.
 *
 * Shared contract: shared-worker/REGISTER-CONTRACT.md. The Moodle plugin holds
 * the same class (classes/local/registration.php); keep the two in step.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EQWPDF_Registration {

	/** Where admins are sent for help with a failed registration. */
	const SUPPORT_URL = 'https://www.equalweb.com/platform/pdf_tools/support.html';

	// Options (all autoload = no). The token, host and raw error names predate
	// this class; the others were added with it. uninstall.php deletes them all.
	const OPT_SITE_TOKEN        = 'eqw_pdf_site_token';
	const OPT_SITE_HOST         = 'eqw_pdf_site_host';
	const OPT_REG_ERROR         = 'eqw_pdf_register_error';
	const OPT_REG_CLASS         = 'eqw_pdf_register_class';
	const OPT_REG_AT            = 'eqw_pdf_register_at';
	const OPT_REG_DETAIL        = 'eqw_pdf_register_detail';
	const OPT_REG_OK_AT         = 'eqw_pdf_register_ok_at';
	const OPT_REG_ATTEMPTED     = 'eqw_pdf_register_attempted';
	const OPT_TOKEN_REJECTED_AT = 'eqw_pdf_token_rejected_at';

	/** The one-time code the verify route serves while a registration runs. */
	const TRANSIENT_NONCE = 'eqw_pdf_register_nonce';

	/** The message the WordPress HTTP API gives when WP_HTTP_BLOCK_EXTERNAL refuses a request. */
	const WP_BLOCKED_TEXT = 'User has blocked requests through HTTP.';

	/**
	 * Every answer code, mapped to its automatic retry policy:
	 * 'after_fix' (a person must change something first; only Try again runs),
	 * 'backoff' (retried automatically, spaced out by BACKOFF) or 'at_reset'
	 * (wait for the daily reset). null marks the codes whose policy is decided
	 * per case (outbound_blocked, unknown) or that never come from registration
	 * (token_invalid, daily_limit_reached come from a check).
	 */
	const CODES = array(
		'rate_limited'          => 'at_reset',
		'bad_request'           => 'after_fix',
		'not_https'             => 'after_fix',
		'host_mismatch'         => 'after_fix',
		'verify_redirect'       => 'after_fix',
		'verify_auth'           => 'after_fix',
		'verify_blocked'        => 'after_fix',
		'verify_not_found'      => 'after_fix',
		'verify_nonce_missing'  => 'backoff',
		'verify_site_error'     => 'backoff',
		'verify_tls'            => 'after_fix',
		'verify_unreachable'    => 'backoff',
		'verify_timeout'        => 'backoff',
		'verify_not_json'       => 'backoff',
		'verify_debug_output'   => 'after_fix',
		'verify_nonce_mismatch' => 'backoff',
		'verify_http'           => 'backoff',
		'service_error'         => 'backoff',
		'token_invalid'         => null,
		'daily_limit_reached'   => null,
		'outbound_blocked'      => null,
		'unknown'               => null,
	);

	/** Automatic retry spacing after the 1st, 2nd, 3rd and every later consecutive failure, in seconds. */
	const BACKOFF = array( 600, 3600, 21600, 86400 );

	/**
	 * How to read an answer that carries no code: ordered rules, first match
	 * wins. A rule matches the exact error text, or an error that starts with
	 * error_prefix and whose remainder matches error_regex (flags 'i' = ignore
	 * case). Each error text belongs to one HTTP status, so a stored message
	 * (which has no status) can be matched on the text alone. Any 'Proxy error:'
	 * text that does not look like a timeout, TLS or network failure is the
	 * service's own problem. Frozen in shared-worker/test/register-vectors.json.
	 */
	const LEGACY = array(
		array( 'status' => 429, 'error' => 'Too many registration attempts. Try again later.', 'code' => 'rate_limited' ),
		array( 'status' => 400, 'error' => 'site_url, verify_url and nonce are required', 'code' => 'bad_request' ),
		array( 'status' => 400, 'error' => 'site_url and verify_url must be https', 'code' => 'not_https' ),
		array( 'status' => 400, 'error' => 'verify_url must be on the same host as site_url', 'code' => 'host_mismatch' ),
		array(
			'status' => 400,
			'error'  => 'Domain verification failed: the verify URL must not redirect',
			'code'   => 'verify_redirect',
		),
		array( 'status' => 400, 'error' => 'Domain verification failed: nonce mismatch', 'code' => 'verify_nonce_mismatch' ),
		array(
			'status'       => 400,
			'error_prefix' => 'Domain verification failed (HTTP ',
			'error_regex'  => '^(401|407)\\)$',
			'code'         => 'verify_auth',
		),
		array(
			'status'       => 400,
			'error_prefix' => 'Domain verification failed (HTTP ',
			'error_regex'  => '^(403|406|429|451)\\)$',
			'code'         => 'verify_blocked',
		),
		array(
			'status'       => 400,
			'error_prefix' => 'Domain verification failed (HTTP ',
			'error_regex'  => '^(404|410)\\)$',
			'code'         => 'verify_not_found',
		),
		array(
			'status'       => 400,
			'error_prefix' => 'Domain verification failed (HTTP ',
			'error_regex'  => '^(525|526)\\)$',
			'code'         => 'verify_tls',
		),
		array(
			'status'       => 400,
			'error_prefix' => 'Domain verification failed (HTTP ',
			'error_regex'  => '^(52[0-4]|530)\\)$',
			'code'         => 'verify_unreachable',
		),
		array(
			'status'       => 400,
			'error_prefix' => 'Domain verification failed (HTTP ',
			'error_regex'  => '^50[0-4]\\)$',
			'code'         => 'verify_site_error',
		),
		array(
			'status'       => 400,
			'error_prefix' => 'Domain verification failed (HTTP ',
			'error_regex'  => '^[0-9]{3}\\)$',
			'code'         => 'verify_http',
		),
		array(
			'status'       => 502,
			'error_prefix' => 'Proxy error: ',
			'error_regex'  => 'aborted due to timeout|timed out',
			'flags'        => 'i',
			'code'         => 'verify_timeout',
		),
		array(
			'status'       => 502,
			'error_prefix' => 'Proxy error: ',
			'error_regex'  => '\\bTLS\\b|\\bSSL\\b|certificate|handshake',
			'flags'        => 'i',
			'code'         => 'verify_tls',
		),
		array(
			'status'       => 502,
			'error_prefix' => 'Proxy error: ',
			'error_regex'  => 'network connection lost|connection (refused|reset|closed)|Fetch API cannot load|fetch failed'
				. '|ECONNREFUSED|ECONNRESET|ENOTFOUND|EAI_AGAIN|getaddrinfo|socket hang up',
			'flags'        => 'i',
			'code'         => 'verify_unreachable',
		),
		array( 'status' => 502, 'error_prefix' => 'Proxy error: ', 'code' => 'service_error' ),
		array( 'status' => 401, 'error' => 'Site not registered or invalid token', 'code' => 'token_invalid' ),
		array( 'status' => 429, 'error' => 'Daily free-check limit reached for this site', 'code' => 'daily_limit_reached' ),
	);

	/** Sub-reasons of outbound_blocked, taken from the transport error. */
	const OUTBOUND_SUBS = array( 'dns', 'connect', 'timeout', 'tls', 'proxy', 'policy' );

	/* ------------------------------------------------------------------ */
	/* Pure classification                                                 */
	/* ------------------------------------------------------------------ */

	/**
	 * Classify one answer to the registration request.
	 *
	 * @param int      $status    HTTP status of the answer; 0 when no HTTP answer arrived.
	 * @param string   $rawbody   The answer body as received.
	 * @param array    $headers   Answer headers, lowercase name => first value.
	 * @param array    $transport Only for status 0: 'errno' (cURL error number), 'message',
	 *                            'proxy' (bool, a proxy is configured) and 'sub' (a sub-reason already known).
	 * @param int|null $now       Current time (for the daily reset of rate_limited).
	 * @return array ok, token, code, status, verify_status, error, hint, retry,
	 *               resets_at (epoch seconds or null), sub (outbound only) and source
	 *               ('code' = the service sent a code, 'legacy' = read from the error text,
	 *               'client' = decided here).
	 */
	public static function classify( int $status, string $rawbody, array $headers = array(), array $transport = array(), ?int $now = null ): array {
		$now = null === $now ? time() : $now;
		$out = self::blank( $status );
		if ( 0 === $status ) {
			$sub          = self::transport_sub( $transport );
			$out['code']  = 'outbound_blocked';
			$out['sub']   = $sub;
			$out['retry'] = 'policy' === $sub ? 'after_fix' : 'backoff';
			$out['error'] = trim( (string) ( isset( $transport['message'] ) ? $transport['message'] : '' ) );
			return $out;
		}

		$text  = trim( preg_replace( '/^\xEF\xBB\xBF/', '', $rawbody ) );
		$body  = json_decode( $text, true );
		$body  = is_array( $body ) ? $body : null;
		$error = ( null !== $body && isset( $body['error'] ) && is_string( $body['error'] ) ) ? $body['error'] : null;

		$out['error'] = null !== $error ? $error : ( 'HTTP ' . $status );

		if ( 200 === $status ) {
			$token = isset( $body['site_token'] ) ? $body['site_token'] : null;
			if ( is_string( $token ) && '' !== $token ) {
				$out['ok']    = true;
				$out['token'] = $token;
				$out['code']  = '';
				$out['retry'] = null;
				$out['error'] = '';
			}
			// Otherwise a 200 without a token: 'unknown'.
			return $out;
		}

		if ( null !== $body && isset( $body['code'] ) && is_string( $body['code'] ) && '' !== $body['code'] ) {
			$out['source']        = 'code';
			$out['verify_status'] = ( isset( $body['verify_status'] ) && is_int( $body['verify_status'] ) )
				? $body['verify_status'] : null;
			$code                 = $body['code'];
			if ( array_key_exists( $code, self::CODES ) && null !== self::CODES[ $code ] ) {
				$out['code']  = $code;
				$out['retry'] = self::CODES[ $code ];
			} else {
				// A code this version does not know yet: keep the service's own
				// English sentence to show, and its retry advice when valid.
				$out['code']  = 'unknown';
				$out['hint']  = ( isset( $body['hint'] ) && is_string( $body['hint'] ) ) ? self::clip( $body['hint'], 300 ) : null;
				$retry        = isset( $body['retry'] ) ? $body['retry'] : null;
				$out['retry'] = in_array( $retry, array( 'after_fix', 'backoff', 'at_reset' ), true ) ? $retry : 'backoff';
			}
			if ( 'rate_limited' === $out['code'] || 'at_reset' === $out['retry'] ) {
				$at               = ( isset( $body['resets_at'] ) && is_string( $body['resets_at'] ) ) ? strtotime( $body['resets_at'] ) : false;
				$out['resets_at'] = $at ? $at : self::next_utc_midnight( $now );
			}
			return $out;
		}

		if ( null !== $error ) {
			$out['source'] = 'legacy';
			$rule          = self::legacy_rule( $status, $error );
			if ( null !== $rule ) {
				$code = $rule['code'];
			} elseif ( 404 === $status && 'Not found' === $error ) {
				// The service address answered, but not the registration route:
				// a wrong address override. Not the site's fault.
				$code = 'service_error';
			} else {
				$code = 'unknown';
			}
			$out['code']          = $code;
			$out['retry']         = isset( self::CODES[ $code ] ) ? self::CODES[ $code ] : 'backoff';
			$out['verify_status'] = self::verify_status_of( $error );
			if ( 'rate_limited' === $code ) {
				$out['resets_at'] = self::next_utc_midnight( $now );
			}
			return $out;
		}

		// No JSON error at all, so the registration service itself did not answer.
		// A Cloudflare page in front of it is the service's problem; an egress
		// proxy's own refusal page is this server's network.
		$cfpage = isset( $headers['cf-ray'] ) || preg_match( '/error code:?\s*1\d{3}\b/i', $text );
		if ( $cfpage ) {
			$out['code'] = 'service_error';
		} elseif ( 403 === $status || 407 === $status ) {
			$out['code'] = 'outbound_blocked';
			$out['sub']  = 'proxy';
		} elseif ( $status >= 500 ) {
			$out['code'] = 'service_error';
		}
		$out['retry'] = 'backoff';
		return $out;
	}

	/**
	 * Classify a registration error message stored without a code, by a version
	 * of this plugin that predates the codes.
	 *
	 * Such a message is the service's error text, a transport error message, or
	 * 'HTTP <status>' when the answer had no error text.
	 *
	 * @param string   $error        The stored message.
	 * @param string[] $blockedtexts Texts that mean WordPress itself refused the request.
	 * @return array Same shape as classify(), with source 'legacy'.
	 */
	public static function classify_stored( string $error, array $blockedtexts = array() ): array {
		$out           = self::blank( 0 );
		$out['source'] = 'legacy';
		$out['error']  = $error;
		$rule          = self::legacy_rule( null, $error );
		if ( null !== $rule ) {
			$out['code']          = $rule['code'];
			$out['status']        = $rule['status'];
			$out['retry']         = isset( self::CODES[ $rule['code'] ] ) ? self::CODES[ $rule['code'] ] : 'backoff';
			$out['verify_status'] = self::verify_status_of( $error );
			return $out;
		}
		if ( preg_match( '/^HTTP (\d{3})$/', $error, $m ) ) {
			$out['status'] = (int) $m[1];
			if ( 403 === $out['status'] || 407 === $out['status'] ) {
				$out['code'] = 'outbound_blocked';
				$out['sub']  = 'proxy';
			} elseif ( $out['status'] >= 500 ) {
				$out['code'] = 'service_error';
			}
			return $out;
		}
		$blockedtexts[] = self::WP_BLOCKED_TEXT;
		if ( preg_match( '/cURL error \d+/i', $error ) || in_array( $error, $blockedtexts, true ) ) {
			// The next attempt re-checks the address, so even a stored 'blocked'
			// stays on automatic retries until a fresh answer says otherwise.
			$out['code'] = 'outbound_blocked';
			$sub         = self::transport_sub( array( 'message' => $error ) );
			$out['sub']  = in_array( $error, $blockedtexts, true ) ? null : $sub;
			return $out;
		}
		return $out;
	}

	/**
	 * The first LEGACY rule matching an error text.
	 *
	 * @param int|null $status HTTP status of the answer, or null to match on the text alone.
	 * @param string   $error  The error text.
	 * @return array|null The rule, or null when none matches.
	 */
	public static function legacy_rule( ?int $status, string $error ): ?array {
		foreach ( self::LEGACY as $rule ) {
			if ( null !== $status && $rule['status'] !== $status ) {
				continue;
			}
			if ( isset( $rule['error'] ) ) {
				if ( $rule['error'] === $error ) {
					return $rule;
				}
				continue;
			}
			if ( 0 !== strpos( $error, $rule['error_prefix'] ) ) {
				continue;
			}
			if ( ! isset( $rule['error_regex'] ) ) {
				return $rule;
			}
			$rest    = (string) substr( $error, strlen( $rule['error_prefix'] ) );
			$pattern = '~' . $rule['error_regex'] . '~' . ( ( isset( $rule['flags'] ) ? $rule['flags'] : '' ) === 'i' ? 'i' : '' );
			if ( preg_match( $pattern, $rest ) ) {
				return $rule;
			}
		}
		return null;
	}

	/**
	 * The sub-reason of an outbound failure, from the transport error.
	 *
	 * @param array $transport 'sub' (already known), 'errno', 'message' and 'proxy' (bool).
	 * @return string One of OUTBOUND_SUBS.
	 */
	public static function transport_sub( array $transport ): string {
		if ( isset( $transport['sub'] ) && in_array( $transport['sub'], self::OUTBOUND_SUBS, true ) ) {
			return $transport['sub'];
		}
		$message = (string) ( isset( $transport['message'] ) ? $transport['message'] : '' );
		$errno   = isset( $transport['errno'] ) ? (int) $transport['errno'] : 0;
		if ( 0 === $errno && preg_match( '/cURL error (\d+)/i', $message, $m ) ) {
			$errno = (int) $m[1];
		}
		if ( 5 === $errno || preg_match( '/\bproxy\b/i', $message ) ) {
			return 'proxy';
		}
		if ( ! empty( $transport['proxy'] ) && in_array( $errno, array( 7, 28, 56 ), true ) ) {
			return 'proxy';
		}
		if ( 6 === $errno ) {
			return 'dns';
		}
		if ( 28 === $errno ) {
			return 'timeout';
		}
		if ( in_array( $errno, array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 83, 90, 91 ), true ) ) {
			return 'tls';
		}
		return 'connect';
	}

	/**
	 * The code of a check-time answer from the free-check service, if it has one
	 * this plugin explains: token_invalid or daily_limit_reached.
	 *
	 * @param int        $status HTTP status.
	 * @param array|null $body   Decoded JSON body.
	 * @return array|null array( 'code' => ..., 'limit' => ?int, 'resets_at' => ?int ), or null.
	 */
	public static function check_code( int $status, ?array $body ): ?array {
		$code = ( null !== $body && isset( $body['code'] ) && is_string( $body['code'] ) ) ? $body['code'] : null;
		if ( null === $code && null !== $body && isset( $body['error'] ) && is_string( $body['error'] ) ) {
			$rule = self::legacy_rule( $status, $body['error'] );
			$code = isset( $rule['code'] ) ? $rule['code'] : null;
		}
		if ( 'token_invalid' !== $code && 'daily_limit_reached' !== $code ) {
			return null;
		}
		$at = ( isset( $body['resets_at'] ) && is_string( $body['resets_at'] ) ) ? strtotime( $body['resets_at'] ) : false;
		return array(
			'code'      => $code,
			'limit'     => ( isset( $body['limit'] ) && is_int( $body['limit'] ) ) ? $body['limit'] : null,
			'resets_at' => $at ? $at : null,
		);
	}

	/**
	 * When the next automatic attempt may run after a failure.
	 *
	 * @param array $failure A failure as returned by failure(): code, retry, at,
	 *                       attempts and resets_at.
	 * @return int|null Epoch seconds, or null when only a person (Try again) may retry.
	 */
	public static function next_auto_at( array $failure ): ?int {
		$at    = (int) ( isset( $failure['at'] ) ? $failure['at'] : 0 );
		$retry = isset( $failure['retry'] ) ? $failure['retry'] : 'backoff';
		if ( 'after_fix' === $retry ) {
			return null;
		}
		if ( 'at_reset' === $retry ) {
			return ! empty( $failure['resets_at'] ) ? (int) $failure['resets_at'] : self::next_utc_midnight( $at );
		}
		$n = max( 1, (int) ( isset( $failure['attempts'] ) ? $failure['attempts'] : 1 ) );
		return $at + self::BACKOFF[ min( $n, count( self::BACKOFF ) ) - 1 ];
	}

	/**
	 * The verify status inside a 'Domain verification failed (HTTP n)' text.
	 *
	 * @param string $error The error text.
	 * @return int|null
	 */
	public static function verify_status_of( string $error ): ?int {
		return preg_match( '/^Domain verification failed \(HTTP (\d{3})\)$/', $error, $m ) ? (int) $m[1] : null;
	}

	/**
	 * The next 00:00 UTC after a moment.
	 *
	 * @param int $now Epoch seconds.
	 * @return int
	 */
	public static function next_utc_midnight( int $now ): int {
		return ( intdiv( $now, 86400 ) + 1 ) * 86400;
	}

	/**
	 * An outcome with every field present and nothing decided yet.
	 *
	 * @param int $status HTTP status.
	 * @return array
	 */
	private static function blank( int $status ): array {
		return array(
			'ok'            => false,
			'token'         => null,
			'code'          => 'unknown',
			'status'        => $status,
			'verify_status' => null,
			'error'         => '',
			'hint'          => null,
			'retry'         => 'backoff',
			'resets_at'     => null,
			'sub'           => null,
			'source'        => 'client',
		);
	}

	/**
	 * Cut a text to a length, on a character boundary.
	 *
	 * @param string $text The text.
	 * @param int    $max  Maximum length in characters.
	 * @return string
	 */
	private static function clip( string $text, int $max ): string {
		$text = trim( preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $text ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, $max ) : substr( $text, 0, $max );
	}

	/**
	 * Remove the proxy credentials from a transport error message.
	 *
	 * WordPress sends its proxy user name and password (WP_PROXY_USERNAME,
	 * WP_PROXY_PASSWORD) with every proxied request, and a transport error such
	 * as 'Could not resolve proxy: ...' can carry them in clear text. The user
	 * name on its own is left alone: it is not a secret, and replacing it
	 * everywhere would garble ordinary words and addresses.
	 *
	 * @param string $text     The message.
	 * @param string $user     The proxy user name.
	 * @param string $password The proxy password.
	 * @return string
	 */
	public static function redact_proxy( string $text, string $user, string $password ): string {
		if ( '' === $password ) {
			return $text;
		}
		$map = array();
		foreach ( array( $user . $password, $user . ':' . $password, $password ) as $secret ) {
			foreach ( array( $secret, rawurlencode( $secret ), urlencode( $secret ) ) as $form ) {
				$map[ $form ] = '[proxy credentials]';
			}
		}
		// One pass, longest match first: a replaced part is never searched again.
		return strtr( $text, $map );
	}

	/* ------------------------------------------------------------------ */
	/* Site state (plugin options)                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * The address the service fetches to confirm this site runs the plugin.
	 *
	 * @return string
	 */
	public static function verify_url(): string {
		return rest_url( 'eqw/v1/verify' );
	}

	/**
	 * Host name of the free-check service.
	 *
	 * @return string
	 */
	public static function service_host(): string {
		return (string) wp_parse_url( EQW_PDF_WORKER_URL, PHP_URL_HOST );
	}

	/**
	 * Host of this site's Site Address (home_url()).
	 *
	 * @return string
	 */
	public static function current_host(): string {
		return (string) wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/**
	 * Whether this site holds a working free-check registration, and if not, why.
	 *
	 * A stored token counts only when it was issued for this site's host and the
	 * service has not refused it since the last successful registration:
	 *  - after a move, or on a copy of another site, the token belongs to the old
	 *    host, and the service refuses it for this one;
	 *  - a token the service refused at check time (token_invalid) shows as a
	 *    failure with Try again. Nothing is cleared automatically.
	 *
	 * @return array 'registered' (bool); 'movedfrom' (the host the stored token was
	 *               issued for, when it is not this one, else null); 'failure' (what to show
	 *               when not registered: the newer of the last failed registration and the
	 *               last refusal of the token, or null when neither is on record).
	 */
	public static function state(): array {
		$token      = (string) get_option( self::OPT_SITE_TOKEN, '' );
		$movedfrom  = self::moved_from();
		$rejectedat = ( '' !== $token && null === $movedfrom ) ? self::rejected_at() : 0;
		if ( '' !== $token && null === $movedfrom && 0 === $rejectedat ) {
			return array( 'registered' => true, 'movedfrom' => null, 'failure' => null );
		}
		$failure = self::failure();
		if ( $rejectedat > 0 && ( null === $failure || (int) $failure['at'] < $rejectedat ) ) {
			$failure = self::rejection( $rejectedat );
		}
		return array( 'registered' => false, 'movedfrom' => $movedfrom, 'failure' => $failure );
	}

	/**
	 * The host the stored token was issued for, when that is not this site's host:
	 * the site moved, or this is a copy of another site (for example a staging
	 * clone of production). The service refuses such a token, so it is no
	 * registration here. A token stored without a host, by an early version,
	 * counts as this host's. Host names compare without case, as the service
	 * compares them.
	 *
	 * @return string|null The other host, or null.
	 */
	public static function moved_from(): ?string {
		$host = (string) get_option( self::OPT_SITE_HOST, '' );
		if ( '' === (string) get_option( self::OPT_SITE_TOKEN, '' ) || '' === $host
			|| strtolower( $host ) === strtolower( self::current_host() ) ) {
			return null;
		}
		return $host;
	}

	/**
	 * When the service last refused the stored token, if that was after the last
	 * successful registration.
	 *
	 * @return int Epoch seconds, or 0.
	 */
	private static function rejected_at(): int {
		$at = (int) get_option( self::OPT_TOKEN_REJECTED_AT, 0 );
		return $at > (int) get_option( self::OPT_REG_OK_AT, 0 ) ? $at : 0;
	}

	/**
	 * The refusal of the stored token, in the shape of failure().
	 *
	 * @param int $at When the service refused it.
	 * @return array
	 */
	private static function rejection( int $at ): array {
		return array(
			'code'           => 'token_invalid',
			'error'          => 'Site not registered or invalid token',
			'at'             => $at,
			'status'         => 401,
			'verify_status'  => null,
			'retry'          => 'after_fix',
			'resets_at'      => null,
			'sub'            => null,
			'hint'           => null,
			'attempts'       => 0,
			'trigger'        => 'check',
			'plugin'         => null,
			'platform'       => null,
			'verify_url'     => null,
			'site_url'       => null,
			'worker_version' => null,
			'source'         => 'check',
		);
	}

	/**
	 * Record that the service refused this site's token at check time
	 * (token_invalid). The token stays: the notice shows the registration as no
	 * longer valid, with Try again, until a registration succeeds or the service
	 * accepts the token again. A refusal of a token that is no longer the stored
	 * one (a registration replaced it meanwhile) changes nothing.
	 *
	 * @param string $token The token the refused request carried.
	 */
	public static function note_rejection( string $token ) {
		if ( '' !== $token && $token === (string) get_option( self::OPT_SITE_TOKEN, '' ) ) {
			update_option( self::OPT_TOKEN_REJECTED_AT, time(), false );
		}
	}

	/**
	 * Record that the service accepted this site's token, which ends an earlier
	 * refusal (for example one seen while the service was still storing a new
	 * registration).
	 *
	 * @param string $token The token the accepted request carried.
	 */
	public static function note_acceptance( string $token ) {
		if ( '' !== $token && '' !== (string) get_option( self::OPT_TOKEN_REJECTED_AT, '' )
			&& $token === (string) get_option( self::OPT_SITE_TOKEN, '' ) ) {
			delete_option( self::OPT_TOKEN_REJECTED_AT );
		}
	}

	/**
	 * The last failed registration, or null when none is on record.
	 *
	 * A message saved by an earlier version of the plugin has no code; it is
	 * classified from its text, so an upgraded site explains it the same way.
	 *
	 * @return array|null code, error, at, status, verify_status, retry, resets_at,
	 *                    sub, hint, attempts, trigger, plugin, platform, verify_url, site_url,
	 *                    worker_version and source.
	 */
	public static function failure(): ?array {
		// Scrubbed again on the way out: a message stored by an earlier version
		// can still carry the proxy password.
		$raw = EQW_API_Client::scrub( (string) get_option( self::OPT_REG_ERROR, '' ) );
		if ( '' === $raw ) {
			return null;
		}
		$code = (string) get_option( self::OPT_REG_CLASS, '' );
		if ( '' !== $code && array_key_exists( $code, self::CODES ) ) {
			$detail     = get_option( self::OPT_REG_DETAIL, array() );
			$f          = is_array( $detail ) ? $detail : array();
			$f['code']  = $code;
			$f['error'] = $raw;
			$f['at']    = (int) get_option( self::OPT_REG_AT, 0 );
		} else {
			$f             = self::classify_stored( $raw );
			$f['at']       = (int) get_option( self::OPT_REG_ATTEMPTED, 0 );
			$f['attempts'] = 1;
		}
		return $f + array(
			'status'         => null,
			'verify_status'  => null,
			'retry'          => isset( self::CODES[ $code ] ) ? self::CODES[ $code ] : 'backoff',
			'resets_at'      => null,
			'sub'            => null,
			'hint'           => null,
			'attempts'       => 1,
			'trigger'        => null,
			'plugin'         => null,
			'platform'       => null,
			'verify_url'     => null,
			'site_url'       => null,
			'worker_version' => null,
			'source'         => 'code',
		);
	}

	/**
	 * Whether this site should register automatically now (after the admin page
	 * loaded, before a check, or before a check when the site moved).
	 *
	 * Never more often than every 10 minutes. After a failure the retry policy
	 * decides: a reason a person must fix waits for Try again (or a changed site
	 * address), the rate limit waits for its reset, anything else backs off.
	 * A token issued for another host counts as no registration. A token for this
	 * host never registers automatically, also when the service refused it: that
	 * waits for Try again.
	 *
	 * @return bool
	 */
	public static function auto_due(): bool {
		if ( '' !== (string) get_option( self::OPT_SITE_TOKEN, '' ) && null === self::moved_from() ) {
			return false;
		}
		$now = time();
		if ( (int) get_option( self::OPT_REG_ATTEMPTED, 0 ) > $now - 600 ) {
			return false;
		}
		$f = self::failure();
		if ( null === $f ) {
			return true;
		}
		if ( 'after_fix' === $f['retry'] ) {
			return ! empty( $f['site_url'] ) && $f['site_url'] !== home_url();
		}
		return $now >= (int) self::next_auto_at( $f );
	}

	/**
	 * Store the outcome of one registration attempt.
	 *
	 * @param array $outcome From classify().
	 * @param array $context trigger, plugin, platform, verify_url, site_url, worker_version.
	 */
	public static function record( array $outcome, array $context ) {
		if ( $outcome['ok'] ) {
			update_option( self::OPT_SITE_TOKEN, $outcome['token'], false );
			update_option( self::OPT_SITE_HOST, self::current_host(), false );
			update_option( self::OPT_REG_OK_AT, time(), false );
			foreach ( array( self::OPT_REG_ERROR, self::OPT_REG_CLASS, self::OPT_REG_AT, self::OPT_REG_DETAIL, self::OPT_TOKEN_REJECTED_AT ) as $name ) {
				delete_option( $name );
			}
			delete_transient( 'eqw_pdf_rereg_throttle' );
			return;
		}
		$previous = self::failure();
		$error    = '' !== $outcome['error'] ? $outcome['error'] : ( 'HTTP ' . $outcome['status'] );
		update_option( self::OPT_REG_ERROR, self::clip( $error, 1000 ), false );
		update_option( self::OPT_REG_CLASS, $outcome['code'], false );
		update_option( self::OPT_REG_AT, time(), false );
		update_option( self::OPT_REG_DETAIL, array(
			'status'        => $outcome['status'],
			'verify_status' => $outcome['verify_status'],
			'retry'         => $outcome['retry'],
			'resets_at'     => $outcome['resets_at'],
			'sub'           => $outcome['sub'],
			'hint'          => $outcome['hint'],
			'source'        => $outcome['source'],
			'attempts'      => ( null !== $previous ? (int) $previous['attempts'] : 0 ) + 1,
		) + $context, false );
	}

	/* ------------------------------------------------------------------ */
	/* Wording                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Why it failed, in one sentence.
	 *
	 * @param array $f A failure (failure()) or an outcome (classify()).
	 * @return string Plain text.
	 */
	public static function why( array $f ): string {
		switch ( $f['code'] ) {
			case 'rate_limited':
				return __( 'Too many registration attempts came from this server network today.', 'equalweb' );
			case 'bad_request':
				return __( 'The registration request reached EqualWeb incomplete.', 'equalweb' );
			case 'not_https':
				/* translators: %s: the Site Address (URL) of this site. */
				return sprintf( __( 'Free checks need this site to use an https:// address, but the site address is %s.', 'equalweb' ), home_url() );
			case 'host_mismatch':
				return __( 'The verification address is on a different domain or port than the site address.', 'equalweb' );
			case 'verify_redirect':
				return __( 'Your site redirected the EqualWeb check instead of answering it, usually to a login (single sign-on) page, a maintenance page or another address.', 'equalweb' );
			case 'verify_auth':
				return __( 'Your site asked the EqualWeb check to log in.', 'equalweb' );
			case 'verify_blocked':
				return __( 'A firewall or security service in front of your site blocked the EqualWeb check.', 'equalweb' );
			case 'verify_not_found':
				return __( 'EqualWeb reached your domain, but the verification address does not exist there.', 'equalweb' );
			case 'verify_nonce_missing':
				return __( 'EqualWeb reached the plugin on your site, but the plugin did not find the one-time code it had just created.', 'equalweb' );
			case 'verify_site_error':
				return __( 'Your site returned a server error to the EqualWeb check.', 'equalweb' );
			case 'verify_tls':
				return __( 'EqualWeb could not open a secure connection to your site, because its certificate is not valid from the internet.', 'equalweb' );
			case 'verify_unreachable':
				return __( 'EqualWeb could not reach your site from the internet.', 'equalweb' );
			case 'verify_timeout':
				return __( 'Your site did not answer the EqualWeb check within 15 seconds.', 'equalweb' );
			case 'verify_not_json':
				return __( 'Your site answered the EqualWeb check with a web page instead of the expected short JSON message.', 'equalweb' );
			case 'verify_debug_output':
				return __( 'Your site printed extra text, such as PHP warnings, in front of its answer to the EqualWeb check.', 'equalweb' );
			case 'verify_nonce_mismatch':
				return __( 'Your site answered the EqualWeb check with an outdated one-time code.', 'equalweb' );
			case 'verify_http':
				return __( 'Your site answered the EqualWeb check with an unexpected status.', 'equalweb' );
			case 'service_error':
				return __( 'The EqualWeb service had a temporary problem. Your site is not the cause.', 'equalweb' );
			case 'token_invalid':
				return __( 'This site registration for free checks is no longer valid.', 'equalweb' );
			case 'daily_limit_reached':
				return __( 'This site has used its free checks for today.', 'equalweb' );
			case 'outbound_blocked':
				/* translators: %s: host name of the EqualWeb free-check service. */
				return sprintf( __( 'This server could not connect to the EqualWeb service at %s.', 'equalweb' ), self::service_host() );
		}
		// A code this version does not know: the service's own sentence when it sent
		// one, otherwise the answer itself (the contract's hint, then error).
		if ( ! empty( $f['hint'] ) ) {
			return (string) $f['hint'];
		}
		$error = self::clip( (string) ( isset( $f['error'] ) ? $f['error'] : '' ), 200 );
		return '' !== $error
			/* translators: %s: the error text the EqualWeb service answered with. */
			? sprintf( __( 'Registration for free checks did not complete (%s).', 'equalweb' ), $error )
			: __( 'Registration for free checks did not complete.', 'equalweb' );
	}

	/**
	 * What the admin should do about it.
	 *
	 * @param array $f A failure (failure()) or an outcome (classify()).
	 * @return string Plain text.
	 */
	public static function todo( array $f ): string {
		switch ( $f['code'] ) {
			case 'rate_limited':
				$at = ! empty( $f['resets_at'] ) ? (int) $f['resets_at'] : self::next_utc_midnight( time() );
				/* translators: %s: date and time of the next daily reset. */
				return sprintf( __( 'Wait until %s (00:00 UTC), then click Try again. More clicks do not help. On shared hosting, the other sites on the same server count toward the same limit.', 'equalweb' ), self::format_time( $at ) );
			case 'bad_request':
				return __( 'A proxy or security filter on the server network probably changed the request. Click Try again. If it happens again, contact EqualWeb support with the technical details below.', 'equalweb' );
			case 'not_https':
				return __( 'Set the WordPress Address (URL) and the Site Address (URL) in Settings > General to the https:// address of the site, with a valid certificate, then click Try again.', 'equalweb' );
			case 'host_mismatch':
				return __( 'Make sure the WordPress Address (URL) and the Site Address (URL) in Settings > General are the public https:// address of the site, on the same domain, without an internal name, an IP address or a port number. A proxy or CDN that rewrites addresses can cause this. Then click Try again.', 'equalweb' );
			case 'verify_redirect':
				return __( 'Open the verification address below in a private browser window. It must answer directly with a short JSON message. Exclude this one address from single sign-on, forced login, maintenance mode, and any http-to-https, www or trailing-slash rules. Then click Try again.', 'equalweb' );
			case 'verify_auth':
				return __( 'The site is password-protected (a staging site, or web server or Windows authentication), or a plugin limits the REST API to logged-in users. Allow anonymous access to the verification address below. It is needed only while registering. A site that is not public on the internet cannot use free checks. Then click Try again.', 'equalweb' );
			case 'verify_blocked':
				return __( 'Allow anonymous HTTPS GET requests to the verification address below in your firewall, WAF, CDN, security plugin or security service. The check comes from Cloudflare and has no fixed IP address, so allow it by path and by the user agent EqualWeb-SiteVerify. Then click Try again.', 'equalweb' );
			case 'verify_not_found':
				return __( 'Check that this plugin is active on every web server of the site, that the WordPress REST API (/wp-json/) is not disabled or renamed, and that the domain points at this server. Open the verification address below from outside your network: it should answer with a short JSON message. Then click Try again.', 'equalweb' );
			case 'verify_nonce_missing':
				return __( 'A page cache or CDN probably answered with an old copy, two registrations overlapped, or the site runs on several servers that do not share one object cache and database. Exclude the verification address below from caching, wait a minute, then click Try again.', 'equalweb' );
			case 'verify_site_error':
				return __( 'If the site is in maintenance mode, turn it off or let the verification address below through. Otherwise check the web server and PHP error logs for this address. Then click Try again.', 'equalweb' );
			case 'verify_tls':
				return __( 'Install a certificate from a public certificate authority, with its full chain, for this address. An internal or self-signed certificate does not work. If the site is behind Cloudflare, check its SSL mode and origin certificate. Then click Try again.', 'equalweb' );
			case 'verify_unreachable':
				/* translators: %s: the Site Address (URL) of this site. */
				return sprintf( __( 'Free checks need the site to be publicly reachable over HTTPS. An intranet, VPN-only or private staging site cannot register. Open %s from a phone on mobile data. If it opens, click Try again. If that fails, contact EqualWeb support with the technical details below.', 'equalweb' ), home_url() );
			case 'verify_timeout':
				return __( 'A firewall may be dropping requests from the internet without answering, or the server could not answer a second request while it was busy with the registration itself (a server with a single PHP worker). Allow anonymous access to the verification address below, then click Try again.', 'equalweb' );
			case 'verify_not_json':
				return __( 'This is usually a login or single sign-on page, a security challenge or a maintenance page shown in place of the verification address below. Open the address in a private window and make it answer directly, then click Try again.', 'equalweb' );
			case 'verify_debug_output':
				return __( 'Turn off on-screen debug output (set WP_DEBUG_DISPLAY to false in wp-config.php) and on-screen PHP errors (display_errors), then click Try again.', 'equalweb' );
			case 'verify_nonce_mismatch':
				return __( 'A page or CDN cache probably kept an earlier answer, or two registrations ran at the same time. Exclude the verification address below from caching, wait a minute, then click Try again.', 'equalweb' );
			case 'verify_http':
				return __( 'Open the verification address below in a private window: it should answer with a short JSON message. Then click Try again. If it happens again, contact EqualWeb support with the technical details below.', 'equalweb' );
			case 'service_error':
				return __( 'Click Try again in a few minutes. Automatic attempts back off. If it continues for more than a day, contact EqualWeb support with the technical details below.', 'equalweb' );
			case 'token_invalid':
				return __( 'Click Try again to register this site again. If this keeps happening, another installation on the same domain may be registering over this one. Contact EqualWeb support.', 'equalweb' );
			case 'daily_limit_reached':
				$at = ! empty( $f['resets_at'] ) ? (int) $f['resets_at'] : self::next_utc_midnight( time() );
				/* translators: %s: date and time when free checks are available again. */
				return sprintf( __( 'Free checks are available again at %s. The limit is shared by everyone who checks files on this site.', 'equalweb' ), self::format_time( $at ) );
			case 'outbound_blocked':
				return ( isset( $f['sub'] ) ? $f['sub'] : null ) === 'policy'
					? __( 'WordPress itself blocked the request: WP_HTTP_BLOCK_EXTERNAL is set in wp-config.php. Add the EqualWeb service address below to WP_ACCESSIBLE_HOSTS, then click Try again.', 'equalweb' )
					: __( 'Ask your hosting or IT team to allow outgoing HTTPS (port 443) from the web server to the EqualWeb service address below. If the server must use a proxy, set WP_PROXY_HOST and WP_PROXY_PORT in wp-config.php. For certificate errors, update the CA bundle of the server. Then click Try again.', 'equalweb' );
		}
		return __( 'Click Try again. If it fails again, contact EqualWeb support and include the technical details below.', 'equalweb' );
	}

	/**
	 * The row message when free checks cannot run because the site is not
	 * registered: admins are pointed at the notice on the page, everyone else
	 * gets one line with nothing to act on.
	 *
	 * @return string
	 */
	public static function not_registered_message(): string {
		return current_user_can( 'manage_options' )
			? __( 'Free checks are not set up on this site yet. The notice at the top of the page explains why and what to do.', 'equalweb' )
			: __( 'Free checks are not set up on this site yet. Ask a site administrator to open the PDF accessibility page.', 'equalweb' );
	}

	/**
	 * Whether the failure is about the verification address (so the notice shows it).
	 *
	 * @param string $code The code.
	 * @return bool
	 */
	public static function names_verify_url( string $code ): bool {
		return 0 === strpos( $code, 'verify_' ) && 'verify_unreachable' !== $code && 'verify_tls' !== $code;
	}

	/**
	 * The copyable technical details of a failure, one 'name: value' line each.
	 *
	 * The names are fixed identifiers (they are read by EqualWeb support, not
	 * translated); the values are data.
	 *
	 * @param array $f A failure from failure().
	 * @return string
	 */
	public static function details( array $f ): string {
		$lines = array(
			'code'            => $f['code'] . ( ! empty( $f['sub'] ) ? ' (' . $f['sub'] . ')' : '' ),
			'worker_status'   => null === $f['status'] ? '-' : (string) $f['status'],
			'verify_status'   => null === $f['verify_status'] ? '-' : (string) $f['verify_status'],
			'error'           => (string) $f['error'],
			'time_utc'        => ! empty( $f['at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $f['at'] ) . ' UTC' : '-',
			'attempts'        => (string) (int) $f['attempts'],
			'trigger'         => ! empty( $f['trigger'] ) ? $f['trigger'] : '-',
			'plugin'          => 'equalweb ' . ( ! empty( $f['plugin'] ) ? $f['plugin'] : EQW_PDF_VERSION ),
			'wordpress'       => ! empty( $f['platform'] ) ? $f['platform'] : self::wp_version(),
			'verify_url'      => ! empty( $f['verify_url'] ) ? $f['verify_url'] : self::verify_url(),
			'service'         => self::service_host(),
			'service_version' => ! empty( $f['worker_version'] ) ? $f['worker_version'] : '-',
			'source'          => (string) $f['source'],
		);
		if ( ! empty( $f['hint'] ) ) {
			$lines['hint'] = (string) $f['hint'];
		}
		$movedfrom = self::moved_from();
		if ( null !== $movedfrom ) {
			$lines['registered_host'] = $movedfrom;
		}
		$out = '';
		foreach ( $lines as $name => $value ) {
			$out .= $name . ': ' . $value . "\n";
		}
		if ( defined( 'WP_PROXY_HOST' ) && WP_PROXY_HOST ) {
			$out .= "proxy: configured\n";
		}
		return rtrim( $out );
	}

	/**
	 * Short WordPress version, for example 6.8.2.
	 *
	 * @return string
	 */
	public static function wp_version(): string {
		return preg_match( '/^\d+(\.\d+)*/', (string) get_bloginfo( 'version' ), $m ) ? $m[0] : '';
	}

	/**
	 * A moment, in the site's date and time format and time zone.
	 *
	 * @param int $at Epoch seconds.
	 * @return string
	 */
	public static function format_time( int $at ): string {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at );
	}
}
