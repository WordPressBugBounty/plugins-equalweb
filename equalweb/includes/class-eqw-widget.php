<?php
/**
 * The EqualWeb accessibility widget on the site: the installation code from
 * the EqualWeb account, parsed into settings, the button designed from
 * WordPress, and the code the visitors get.
 *
 * What the old "Equalweb Accessibility" 1.0 plugin did with a textarea and an
 * echo in the footer (4,000 sites) lives here, done properly:
 *  - the pasted code is PARSED (site key, core version, integrity hash, the
 *    button style) and only the parsed values are stored; nothing the admin
 *    typed is ever echoed as script;
 *  - the core is loaded with wp_enqueue_script, with the same attributes the
 *    portal's snippet sets (defer, integrity, crossorigin, data-cfasync);
 *  - the button can be designed here (position, size, colours, icon, shape),
 *    within what the core supports: icons 1 to 14 and the five shapes of the
 *    core stylesheet, languages from the core's own list (read from the CDN);
 *  - the 1.0 settings are imported on the first run after the update, so the
 *    widget keeps working with no admin action;
 *  - a snippet that cannot be parsed (hand-edited) is kept as "legacy" output,
 *    flagged in the tab, until a fresh code is pasted.
 *
 * By design (nir, 2026-10-11) the code from the EqualWeb account is the single
 * source of truth for the site key, the core version and its integrity hash:
 * every core release needs the code pasted again. The plugin never follows
 * core versions on its own.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EQW_Widget {

	/** One option holds everything (autoloaded; it is read on every front request). */
	const OPTION = 'eqw_widget';

	/** The 1.0 plugin's options, imported once and left in place for a rollback. */
	const LEGACY_CODE     = 'equalweb_code';
	const LEGACY_ENABLE   = 'equalweb_enable';
	const LEGACY_REDIRECT = 'equalweb_activation_redirect';

	/** The default domains (EqualWeb), used when the code names none. */
	const CDN = 'https://cdn.equalweb.com/';
	const ACC = 'https://access.equalweb.com/';

	/**
	 * The brands the widget is sold under, by base domain: the code's js/acc
	 * domains must be one of these or a subdomain of one. EqualWeb, and Nagich
	 * (nagich.co.il; Enterprise on nagich.com), whose CDN serves the same core.
	 */
	const BRAND_DOMAINS = array( 'equalweb.com', 'nagich.co.il', 'nagich.com' );

	/**
	 * Button shapes: the four the EqualWeb portal's designer offers (circle, square,
	 * rounded square, semicircle), in the core's own names. The core stylesheet also
	 * knows a fifth, "canoe", which the portal never emits, so it is not offered here.
	 */
	const SHAPES = array( 'circle', 'rectangle', 'rounded', 'semicircle' );

	/** Icons are assets/images/1.svg .. 14.svg on the CDN (15+ do not exist). */
	const ICON_MIN = 1;
	const ICON_MAX = 14;

	const POSITIONS = array( 'left', 'right' );

	const SCRIPT_HANDLE  = 'eqw-accessibility-widget';
	const ACTION_SAVE    = 'eqw_widget_save';
	const NONCE_SAVE     = 'eqw_widget_save';
	const AJAX_CHECK     = 'eqw_widget_check';
	const LANG_TRANSIENT = 'eqw_widget_langs';
	const CHECK_UA       = 'EqualWeb-WidgetCheck/1.0';

	/**
	 * The core's language list (assets/data/lang.json on the CDN, 112 codes on
	 * 2026-10-11), used when the CDN cannot be read. languages() prefers the live
	 * list, cached for a day, so new languages show up without a plugin release.
	 */
	const LANG_FALLBACK = array(
		'af', 'am', 'ar', 'az', 'be', 'bg', 'bn', 'bs', 'ca', 'co', 'cs', 'cy', 'da', 'de', 'el', 'en', 'en-AU', 'en-CA', 'en-GB', 'en-ZA',
		'eo', 'es', 'es-MX', 'et', 'eu', 'fa', 'fi', 'fr', 'fr-CA', 'fy', 'ga', 'gd', 'gl', 'gu', 'ha', 'he', 'hi', 'hr', 'hu', 'hy',
		'id', 'ig', 'is', 'it', 'ja', 'ka', 'kk', 'km', 'kn', 'ko', 'ku', 'ky', 'lb', 'lo', 'lt', 'lv', 'mg', 'mi', 'mk', 'ml', 'mn',
		'mr', 'ms', 'mt', 'my', 'nb', 'ne', 'nl', 'no', 'ny', 'or', 'pa', 'pl', 'ps', 'pt', 'pt-PT', 'ro', 'ru', 'rw', 'sd', 'si', 'sk',
		'sl', 'sm', 'sn', 'so', 'sq', 'sr', 'st', 'su', 'sv', 'sw', 'ta', 'te', 'tg', 'th', 'tk', 'tr', 'tt', 'ug', 'uk', 'ur', 'uz',
		'vi', 'xh', 'yi', 'yo', 'zh', 'zh-CN', 'zh-TW', 'zu',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_migrate' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_legacy_page' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_front' ) );
		add_filter( 'script_loader_tag', array( __CLASS__, 'script_tag' ), 10, 3 );
		add_action( 'wp_footer', array( __CLASS__, 'legacy_footer' ), 99 );
		add_shortcode( 'equalweb_button', array( __CLASS__, 'shortcode_button' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_CHECK, array( __CLASS__, 'handle_check' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'enabled'        => false,
			// 'config' = built from the parsed values; 'legacy' = the imported 1.0
			// snippet is output as-is until a fresh code is pasted.
			'mode'           => 'config',
			'sitekey'        => '',
			'cdn'            => self::CDN,   // domains.js from the code (EqualWeb or Nagich).
			'acc'            => self::ACC,   // domains.acc from the code.
			'core_version'   => '',
			'integrity'      => '',
			'cfasync'        => true,
			'for_domain'     => '',
			'position'       => '',          // '' = the core decides (right for RTL, else left).
			'menulang'       => '',          // '' = the core decides (page language, then en).
			'draggable'      => true,
			'hide_btn'       => false,
			'show_to_admins' => true,
			'btn'            => array(
				'vposition'     => array( '', '' ),   // [desktop, mobile]
				'margin'        => array( '', '' ),
				'scale'         => array( '', '' ),
				'color_main'    => '',
				'color_second'  => '',
				'icon_type'     => 1,                  // 1..14, or a short text.
				'icon_shape'    => 'circle',
				'icon_outline'  => false,
				'outline_color' => '',
			),
			'unknown'        => array(),     // keys of the pasted code the designer does not know (reported, never dropped silently).
			'raw_snippet'    => '',
			'legacy_raw'     => '',
			'imported_v1'    => false,
			'domains_v'      => 0,           // 1 = stored settings re-read for the brand domains (3.0.1).
			'saved_at'       => 0,
			'last_check'     => array(),
		);
	}

	/** The stored settings over the defaults (always complete). */
	public static function get() {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$d = self::defaults();
		$s = array_merge( $d, $stored );
		$s['btn'] = array_merge( $d['btn'], is_array( $s['btn'] ) ? $s['btn'] : array() );
		return $s;
	}

	public static function save( array $settings ) {
		$settings['saved_at'] = time();
		update_option( self::OPTION, self::sanitize( $settings ), true );
	}

	/* ------------------------------------------------------------------ */
	/* The installation code                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Parse the installation code from the EqualWeb account.
	 *
	 * The portal's code is not JSON: `sitekey` and `domains` are JS getters,
	 * the object ends with a trailing comma, and comments surround it. So this
	 * is a tolerant extractor: regexes for the scalar values and the loader
	 * line, and a JSON decode of the one object that IS valid JSON, btnStyle.
	 *
	 * @param string $text The pasted code.
	 * @return array|WP_Error Settings fields (a subset of defaults()).
	 */
	public static function parse_snippet( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'empty', __( 'Paste the installation code from your EqualWeb account.', 'equalweb' ) );
		}
		// Both spellings: the portal's getter `get sitekey (){ return "..." }` and plain `"sitekey": "..."`.
		if ( ! preg_match( '/sitekey.{0,40}?["\']([a-f0-9]{32})["\']/is', $text, $m ) ) {
			return new WP_Error( 'no_sitekey', __( 'This does not look like an EqualWeb installation code: no site key was found. Copy the whole code from your EqualWeb account.', 'equalweb' ) );
		}
		$out = array( 'sitekey' => strtolower( $m[1] ) );

		// The core may only come from a brand's CDN (BRAND_DOMAINS). A code that
		// points elsewhere would make this plugin load an arbitrary script on the
		// site: refused. Both domains are kept as the code names them, so a Nagich
		// site keeps loading from Nagich.
		$found = array();
		foreach ( array( 'js', 'acc' ) as $key ) {
			if ( preg_match( '/["\']' . $key . '["\']\s*:\s*["\']([^"\']+)["\']/', $text, $m ) ) {
				$found[ $key ] = self::brand_url( $m[1] );
				if ( '' === $found[ $key ] ) {
					return new WP_Error( 'bad_domain', __( 'The code loads its script from an address that is not EqualWeb or Nagich. Only the code from your EqualWeb or Nagich account can be used.', 'equalweb' ) );
				}
			}
		}
		// Older codes write the loader's full address instead of domains.js.
		if ( preg_match( '#(https?:)?//([a-z0-9.-]+)/core/\d#i', $text, $m ) ) {
			$src = self::brand_url( $m[2] );
			if ( '' === $src ) {
				return new WP_Error( 'bad_domain', __( 'The code loads its script from an address that is not EqualWeb or Nagich. Only the code from your EqualWeb or Nagich account can be used.', 'equalweb' ) );
			}
			if ( ! isset( $found['js'] ) ) {
				$found['js'] = $src;
			}
		}
		$out['cdn'] = isset( $found['js'] ) ? $found['js'] : self::CDN;
		$out['acc'] = isset( $found['acc'] ) ? $found['acc'] : self::sibling_acc( $out['cdn'] );
		if ( preg_match( '#core/(\d+(?:\.\d+){1,3})/accessibility\.js#', $text, $m ) ) {
			$out['core_version'] = $m[1];
		} else {
			return new WP_Error( 'no_core', __( 'The code does not say which EqualWeb core version to load. Copy the whole code, including the part below the settings.', 'equalweb' ) );
		}
		if ( preg_match( '/integrity\s*=?\s*["\']?(sha(?:256|384|512)-[A-Za-z0-9+\/=]{40,120})/', $text, $m ) ) {
			$out['integrity'] = $m[1];
		} else {
			$out['integrity'] = '';
		}
		$out['cfasync'] = (bool) preg_match( '/data-cfasync/', $text );
		if ( preg_match( '/Accessibility Code for\s+["\']([^"\']+)["\']/i', $text, $m ) ) {
			$out['for_domain'] = strtolower( trim( $m[1] ) );
		}
		if ( preg_match( '/["\']Position["\']\s*:\s*["\']([^"\']*)["\']/', $text, $m ) ) {
			$out['position'] = strtolower( trim( $m[1] ) );
		}
		if ( preg_match( '/["\']Menulang["\']\s*:\s*["\']([^"\']*)["\']/', $text, $m ) ) {
			$out['menulang'] = trim( $m[1] );
		}
		if ( preg_match( '/["\']draggable["\']\s*:\s*(true|false|1|0)/', $text, $m ) ) {
			$out['draggable'] = in_array( $m[1], array( 'true', '1' ), true );
		}
		if ( preg_match( '/["\']hideBtn["\']\s*:\s*(true|false|1|0)/', $text, $m ) ) {
			$out['hide_btn'] = in_array( $m[1], array( 'true', '1' ), true );
		}

		$unknown = array();
		$style   = self::extract_object( $text, 'btnStyle' );
		if ( null !== $style ) {
			$btn   = array();
			$known = array( 'vPosition', 'margin', 'scale', 'color', 'icon' );
			foreach ( array( 'vPosition' => 'vposition', 'margin' => 'margin', 'scale' => 'scale' ) as $src => $dst ) {
				if ( isset( $style[ $src ] ) ) {
					$pair        = is_array( $style[ $src ] ) ? array_values( $style[ $src ] ) : array( $style[ $src ], $style[ $src ] );
					$btn[ $dst ] = array(
						isset( $pair[0] ) && null !== $pair[0] ? (string) $pair[0] : '',
						isset( $pair[1] ) && null !== $pair[1] ? (string) $pair[1] : '',
					);
				}
			}
			if ( isset( $style['color'] ) ) {
				if ( is_array( $style['color'] ) ) {
					$btn['color_main']   = isset( $style['color']['main'] ) ? (string) $style['color']['main'] : '';
					$btn['color_second'] = isset( $style['color']['second'] ) ? (string) $style['color']['second'] : '';
				} else {
					$btn['color_main'] = (string) $style['color'];
				}
			}
			if ( isset( $style['icon'] ) && is_array( $style['icon'] ) ) {
				$icon = $style['icon'];
				if ( isset( $icon['type'] ) ) {
					$btn['icon_type'] = is_numeric( $icon['type'] ) ? (int) $icon['type'] : (string) $icon['type'];
				}
				if ( isset( $icon['shape'] ) ) {
					$btn['icon_shape'] = strtolower( (string) $icon['shape'] );
				}
				if ( isset( $icon['outline'] ) ) {
					$btn['icon_outline'] = (bool) $icon['outline'];
				}
				if ( isset( $icon['outlineColor'] ) ) {
					$btn['outline_color'] = (string) $icon['outlineColor'];
				}
				foreach ( array_keys( $icon ) as $k ) {
					if ( ! in_array( $k, array( 'type', 'shape', 'outline', 'outlineColor' ), true ) ) {
						$unknown[] = 'btnStyle.icon.' . $k;
					}
				}
			}
			foreach ( array_keys( $style ) as $k ) {
				if ( ! in_array( $k, $known, true ) ) {
					$unknown[] = 'btnStyle.' . $k;
				}
			}
			$out['btn'] = $btn;
		}
		$out['unknown']     = $unknown;
		$out['raw_snippet'] = $text;
		return $out;
	}

	/**
	 * The JSON object that follows `"<key>":` in the code, decoded, or null.
	 * Finds the balanced closing brace (strings aware), then tolerates the
	 * trailing commas the portal emits.
	 */
	/**
	 * A js/acc domain from the code as `https://host/`, or '' when the host is
	 * not one of BRAND_DOMAINS (or a subdomain of one). Takes the portal's form
	 * (https://cdn.equalweb.com/) and the bare form (cdn.nagich.co.il).
	 */
	public static function brand_url( $v ) {
		$v = trim( (string) $v );
		if ( ! preg_match( '#^(?:https?:)?(?://)?([a-z0-9.-]+)/?$#i', $v, $m ) ) {
			return '';
		}
		$host = strtolower( rtrim( $m[1], '.' ) );
		foreach ( self::BRAND_DOMAINS as $base ) {
			if ( $host === $base || substr( $host, -strlen( '.' . $base ) ) === '.' . $base ) {
				return 'https://' . $host . '/';
			}
		}
		return '';
	}

	/** The access domain of the same brand as a CDN (cdn.X -> access.X). */
	private static function sibling_acc( $cdn ) {
		$host = (string) wp_parse_url( $cdn, PHP_URL_HOST );
		foreach ( self::BRAND_DOMAINS as $base ) {
			if ( $host === $base || substr( $host, -strlen( '.' . $base ) ) === '.' . $base ) {
				return 'https://access.' . $base . '/';
			}
		}
		return self::ACC;
	}

	private static function extract_object( $text, $key ) {
		if ( ! preg_match( '/["\']' . preg_quote( $key, '/' ) . '["\']\s*:\s*\{/', $text, $m, PREG_OFFSET_CAPTURE ) ) {
			return null;
		}
		$start = $m[0][1] + strlen( $m[0][0] ) - 1;
		$depth = 0;
		$in    = false;
		$len   = strlen( $text );
		for ( $i = $start; $i < $len; $i++ ) {
			$c = $text[ $i ];
			if ( $in ) {
				if ( '\\' === $c ) {
					$i++;
				} elseif ( '"' === $c ) {
					$in = false;
				}
				continue;
			}
			if ( '"' === $c ) {
				$in = true;
			} elseif ( '{' === $c ) {
				$depth++;
			} elseif ( '}' === $c ) {
				$depth--;
				if ( 0 === $depth ) {
					$json = substr( $text, $start, $i - $start + 1 );
					$json = preg_replace( '/,\s*([}\]])/', '$1', $json );
					$json = preg_replace( '/\/\*.*?\*\//s', '', $json );
					$obj  = json_decode( $json, true );
					return is_array( $obj ) ? $obj : null;
				}
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ */
	/* Sanitising                                                          */
	/* ------------------------------------------------------------------ */

	/** A CSS length the core accepts for vPosition/margin: a number, N%, Npx (also em/rem/vh/vw), or empty. */
	public static function css_length( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v || 'null' === strtolower( $v ) ) {
			return '';
		}
		if ( preg_match( '/^-?\d+(\.\d+)?(px|%|em|rem|vh|vw)?$/', $v ) ) {
			return $v;
		}
		return '';
	}

	public static function hex_color( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v ) ? strtolower( $v ) : '';
	}

	private static function bool( $v ) {
		return in_array( $v, array( true, 1, '1', 'true', 'on', 'yes' ), true );
	}

	/** The language code as the core spells it, or '' when it is not in the core's list. */
	public static function language_code( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		foreach ( self::languages() as $code ) {
			if ( 0 === strcasecmp( $code, $v ) ) {
				return $code;
			}
		}
		return '';
	}

	public static function sanitize( array $s ) {
		$d   = self::defaults();
		$out = array();
		$out['enabled']        = self::bool( isset( $s['enabled'] ) ? $s['enabled'] : false );
		$out['mode']           = ( isset( $s['mode'] ) && 'legacy' === $s['mode'] ) ? 'legacy' : 'config';
		$out['sitekey']        = ( isset( $s['sitekey'] ) && preg_match( '/^[a-f0-9]{32}$/i', (string) $s['sitekey'] ) ) ? strtolower( $s['sitekey'] ) : '';
		$out['cdn']            = isset( $s['cdn'] ) && '' !== self::brand_url( $s['cdn'] ) ? self::brand_url( $s['cdn'] ) : self::CDN;
		$out['acc']            = isset( $s['acc'] ) && '' !== self::brand_url( $s['acc'] ) ? self::brand_url( $s['acc'] ) : self::sibling_acc( $out['cdn'] );
		$out['core_version']   = ( isset( $s['core_version'] ) && preg_match( '/^\d+(\.\d+){1,3}$/', (string) $s['core_version'] ) ) ? (string) $s['core_version'] : '';
		$out['integrity']      = ( isset( $s['integrity'] ) && preg_match( '/^sha(256|384|512)-[A-Za-z0-9+\/=]{40,120}$/', (string) $s['integrity'] ) ) ? (string) $s['integrity'] : '';
		$out['cfasync']        = self::bool( isset( $s['cfasync'] ) ? $s['cfasync'] : true );
		$out['for_domain']     = isset( $s['for_domain'] ) ? strtolower( sanitize_text_field( (string) $s['for_domain'] ) ) : '';
		$out['position']       = ( isset( $s['position'] ) && in_array( strtolower( (string) $s['position'] ), self::POSITIONS, true ) ) ? strtolower( $s['position'] ) : '';
		$out['menulang']       = isset( $s['menulang'] ) ? self::language_code( $s['menulang'] ) : '';
		$out['draggable']      = self::bool( isset( $s['draggable'] ) ? $s['draggable'] : true );
		$out['hide_btn']       = self::bool( isset( $s['hide_btn'] ) ? $s['hide_btn'] : false );
		$out['show_to_admins'] = self::bool( isset( $s['show_to_admins'] ) ? $s['show_to_admins'] : true );

		$b   = isset( $s['btn'] ) && is_array( $s['btn'] ) ? $s['btn'] : array();
		$btn = array();
		foreach ( array( 'vposition', 'margin' ) as $k ) {
			$pair      = isset( $b[ $k ] ) && is_array( $b[ $k ] ) ? array_values( $b[ $k ] ) : array( '', '' );
			$btn[ $k ] = array( self::css_length( isset( $pair[0] ) ? $pair[0] : '' ), self::css_length( isset( $pair[1] ) ? $pair[1] : '' ) );
		}
		$pair         = isset( $b['scale'] ) && is_array( $b['scale'] ) ? array_values( $b['scale'] ) : array( '', '' );
		$btn['scale'] = array( self::scale( isset( $pair[0] ) ? $pair[0] : '' ), self::scale( isset( $pair[1] ) ? $pair[1] : '' ) );
		$btn['color_main']    = self::hex_color( isset( $b['color_main'] ) ? $b['color_main'] : '' );
		$btn['color_second']  = self::hex_color( isset( $b['color_second'] ) ? $b['color_second'] : '' );
		$type                 = isset( $b['icon_type'] ) ? $b['icon_type'] : $d['btn']['icon_type'];
		if ( is_numeric( $type ) ) {
			$type = (int) $type;
			$btn['icon_type'] = ( $type >= self::ICON_MIN && $type <= self::ICON_MAX ) ? $type : $d['btn']['icon_type'];
		} else {
			// A text label instead of an icon: short, plain text.
			$label            = sanitize_text_field( (string) $type );
			$btn['icon_type'] = '' === $label ? $d['btn']['icon_type'] : ( function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 20 ) : substr( $label, 0, 20 ) );
		}
		$shape                = isset( $b['icon_shape'] ) ? strtolower( (string) $b['icon_shape'] ) : '';
		$btn['icon_shape']    = in_array( $shape, self::SHAPES, true ) ? $shape : $d['btn']['icon_shape'];
		$btn['icon_outline']  = self::bool( isset( $b['icon_outline'] ) ? $b['icon_outline'] : false );
		$btn['outline_color'] = self::hex_color( isset( $b['outline_color'] ) ? $b['outline_color'] : '' );
		$out['btn']           = $btn;

		$out['unknown']     = isset( $s['unknown'] ) && is_array( $s['unknown'] ) ? array_values( array_map( 'sanitize_text_field', array_slice( $s['unknown'], 0, 20 ) ) ) : array();
		$out['raw_snippet'] = isset( $s['raw_snippet'] ) ? (string) $s['raw_snippet'] : '';
		$out['legacy_raw']  = isset( $s['legacy_raw'] ) ? (string) $s['legacy_raw'] : '';
		$out['imported_v1'] = self::bool( isset( $s['imported_v1'] ) ? $s['imported_v1'] : false );
		$out['domains_v']   = isset( $s['domains_v'] ) ? (int) $s['domains_v'] : 0;
		$out['saved_at']    = isset( $s['saved_at'] ) ? (int) $s['saved_at'] : 0;
		$out['last_check']  = isset( $s['last_check'] ) && is_array( $s['last_check'] ) ? $s['last_check'] : array();
		if ( 'legacy' === $out['mode'] && '' === $out['legacy_raw'] ) {
			$out['mode'] = 'config';
		}
		return $out;
	}

	/** A button scale between 0.1 and 3, as the core reads it (a string), or empty. */
	public static function scale( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v || 'null' === strtolower( $v ) || ! is_numeric( $v ) ) {
			return '';
		}
		$f = (float) $v;
		if ( $f < 0.1 || $f > 3 ) {
			return '';
		}
		return rtrim( rtrim( number_format( $f, 2, '.', '' ), '0' ), '.' );
	}

	/* ------------------------------------------------------------------ */
	/* The code the visitors get                                           */
	/* ------------------------------------------------------------------ */

	/** The window.interdeal object, as the core reads it (the portal's casing). */
	public static function config( array $s = null ) {
		if ( null === $s ) {
			$s = self::get();
		}
		$c = array(
			'sitekey' => $s['sitekey'],
			'domains' => array(
				'js'  => ! empty( $s['cdn'] ) ? $s['cdn'] : self::CDN,
				'acc' => ! empty( $s['acc'] ) ? $s['acc'] : self::ACC,
			),
		);
		if ( '' !== $s['position'] ) {
			$c['Position'] = $s['position'];
		}
		if ( '' !== $s['menulang'] ) {
			$c['Menulang'] = $s['menulang'];
		}
		$c['draggable'] = (bool) $s['draggable'];
		if ( ! empty( $s['hide_btn'] ) ) {
			$c['hideBtn'] = true;
		}
		$b     = $s['btn'];
		$style = array();
		foreach ( array( 'vposition' => 'vPosition', 'margin' => 'margin', 'scale' => 'scale' ) as $k => $name ) {
			if ( '' !== $b[ $k ][0] || '' !== $b[ $k ][1] ) {
				$style[ $name ] = array(
					'' === $b[ $k ][0] ? null : $b[ $k ][0],
					'' === $b[ $k ][1] ? null : $b[ $k ][1],
				);
			}
		}
		if ( '' !== $b['color_main'] || '' !== $b['color_second'] ) {
			$style['color'] = array(
				'main'   => '' !== $b['color_main'] ? $b['color_main'] : '#171d2d',
				'second' => '' !== $b['color_second'] ? $b['color_second'] : '#ffffff',
			);
		}
		$icon = array(
			'type'    => $b['icon_type'],
			'shape'   => $b['icon_shape'],
			'outline' => (bool) $b['icon_outline'],
		);
		if ( $b['icon_outline'] && '' !== $b['outline_color'] ) {
			$icon['outlineColor'] = $b['outline_color'];
		}
		$style['icon']   = $icon;
		$c['btnStyle']   = $style;
		return $c;
	}

	/** The inline script that precedes the core: `window.interdeal = {...};`. */
	public static function inline_js( array $s = null ) {
		return 'window.interdeal = ' . wp_json_encode( self::config( $s ), JSON_UNESCAPED_SLASHES ) . ';';
	}

	public static function core_url( array $s = null ) {
		if ( null === $s ) {
			$s = self::get();
		}
		return ( ! empty( $s['cdn'] ) ? $s['cdn'] : self::CDN ) . 'core/' . $s['core_version'] . '/accessibility.js';
	}

	/** Whether the widget goes out on this request. */
	public static function should_load( array $s = null ) {
		if ( null === $s ) {
			$s = self::get();
		}
		if ( empty( $s['enabled'] ) ) {
			return false;
		}
		if ( 'legacy' !== $s['mode'] && ( '' === $s['sitekey'] || '' === $s['core_version'] ) ) {
			return false;
		}
		if ( empty( $s['show_to_admins'] ) && is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return false;
		}
		return true;
	}

	public static function enqueue_front() {
		$s = self::get();
		if ( 'legacy' === $s['mode'] || ! self::should_load( $s ) ) {
			return;
		}
		// No version query: the integrity hash is for the exact file the portal named.
		wp_register_script( self::SCRIPT_HANDLE, self::core_url( $s ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- the URL is versioned by the core path and pinned by its integrity hash.
		wp_add_inline_script( self::SCRIPT_HANDLE, self::inline_js( $s ), 'before' );
		wp_enqueue_script( self::SCRIPT_HANDLE );
	}

	/** The attributes the portal's loader sets: defer, integrity, crossorigin, data-cfasync. */
	public static function script_tag( $tag, $handle, $src ) {
		if ( self::SCRIPT_HANDLE !== $handle ) {
			return $tag;
		}
		$s     = self::get();
		$attrs = ' defer crossorigin="anonymous"';
		if ( '' !== $s['integrity'] ) {
			$attrs .= ' integrity="' . esc_attr( $s['integrity'] ) . '"';
		}
		if ( ! empty( $s['cfasync'] ) ) {
			$attrs .= ' data-cfasync="true"';
		}
		// Only the core's own tag, once; WordPress may already have added defer.
		if ( false !== strpos( $tag, ' defer' ) ) {
			$attrs = str_replace( ' defer', '', $attrs );
		}
		return str_replace( ' src=', $attrs . ' src=', $tag );
	}

	/**
	 * Legacy mode: the snippet imported from 1.0 could not be parsed, so it is
	 * output as it was, exactly as 1.0 did, until a fresh code is pasted. It
	 * is the site administrator's own embed code (manage_options wrote it),
	 * the tab flags it, and no other path ever prints raw markup.
	 */
	public static function legacy_footer() {
		$s = self::get();
		if ( 'legacy' !== $s['mode'] || ! self::should_load( $s ) || '' === $s['legacy_raw'] ) {
			return;
		}
		echo "\n<!-- EqualWeb accessibility widget (imported embed code) -->\n";
		echo $s['legacy_raw']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the administrator's own embed code, imported from plugin 1.0 and kept verbatim until re-pasted (see the class docblock).
		echo "\n";
	}

	/**
	 * [equalweb_button text="Accessibility" class=""]: the site's own button.
	 * The core adopts a #INDmenu-btn that exists before it loads and only
	 * manages aria-expanded on it; the theme styles it.
	 */
	public static function shortcode_button( $atts ) {
		$s = self::get();
		if ( ! self::should_load( $s ) ) {
			return '';
		}
		$a = shortcode_atts( array(
			'text'  => __( 'Accessibility', 'equalweb' ),
			'class' => '',
		), $atts, 'equalweb_button' );
		$class = trim( 'eqw-a11y-button ' . preg_replace( '/[^A-Za-z0-9_\- ]/', '', (string) $a['class'] ) );
		return '<button type="button" id="INDmenu-btn" class="' . esc_attr( $class ) . '" aria-expanded="false" aria-label="' . esc_attr( $a['text'] ) . '">' . esc_html( $a['text'] ) . '</button>';
	}

	/* ------------------------------------------------------------------ */
	/* Core updates through the EqualWeb API (needs the account's API key)  */
	/* ------------------------------------------------------------------ */

	/**
	 * The EqualWeb API hands out the current installation code of a site the
	 * key's account owns: GET /widget/code/{siteKey}?format=json, header
	 * x-a11y-api-key (the same key as PDF remediation, Settings tab). With it
	 * the plugin can follow new core versions without a code being pasted
	 * again (nir, 2026-10-14). The API is EqualWeb's only, so this covers codes
	 * on EqualWeb's domains; a Nagich code is updated by pasting it again.
	 *
	 * An update takes from the fresh code only what pins the core: version,
	 * integrity hash and domains. The button design made here is kept.
	 */
	const API_CODE_URL     = 'https://login.equalweb.com/api/v2/widget/code/';
	const UPDATE_TRANSIENT = 'eqw_widget_update';
	const UPDATE_TTL       = 43200;   // 12 h between checks when the tab is opened.

	/** Whether the code is on EqualWeb's own domains (the API covers only those). */
	public static function is_equalweb( array $s ) {
		$host = (string) wp_parse_url( isset( $s['cdn'] ) ? (string) $s['cdn'] : '', PHP_URL_HOST );
		return 'legacy' !== $s['mode'] && '' !== $s['sitekey'] && '' !== $s['core_version']
			&& ( 'equalweb.com' === $host || '.equalweb.com' === substr( $host, -13 ) );
	}

	/** The account's API key (Settings tab), or ''. */
	public static function api_key() {
		return class_exists( 'EQW_API_Client' ) ? (string) EQW_API_Client::get_personal_key() : '';
	}

	/**
	 * The latest installation code for this site from the EqualWeb API, parsed.
	 *
	 * @return array|WP_Error parse_snippet() values, or an error code:
	 *                        no_key, not_found (the key's account does not own
	 *                        this site key), bad_key, http, bad_response.
	 */
	public static function fetch_latest( array $s ) {
		$key = self::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'no_key', '' );
		}
		$res = wp_remote_get( self::API_CODE_URL . rawurlencode( $s['sitekey'] ) . '?format=json', array(
			'timeout' => 10,
			'headers' => array( 'x-a11y-api-key' => $key, 'Accept' => 'application/json' ),
		) );
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'http', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 404 === $code ) {
			return new WP_Error( 'not_found', '' );
		}
		// The API answers a wrong key with 400 {"error":"Bad API key"} (seen
		// 2026-10-14; the docs list 403), a missing one with 401, a locked one with 423.
		$err = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$err = is_array( $err ) && isset( $err['error'] ) && is_string( $err['error'] ) ? $err['error'] : '';
		if ( 401 === $code || 403 === $code || 423 === $code || ( 400 === $code && false !== stripos( $err, 'api key' ) ) ) {
			return new WP_Error( 'bad_key', 'HTTP ' . $code );
		}
		if ( 200 !== $code ) {
			return new WP_Error( 'http', 'HTTP ' . $code );
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $body ) || empty( $body['code'] ) || ! is_string( $body['code'] ) ) {
			return new WP_Error( 'bad_response', '' );
		}
		$parsed = self::parse_snippet( $body['code'] );
		// The same brand allow-list as a pasted code, and it must be THIS site.
		if ( is_wp_error( $parsed ) || $parsed['sitekey'] !== $s['sitekey'] || ! self::is_equalweb( array_merge( $s, $parsed ) ) ) {
			return new WP_Error( 'bad_response', is_wp_error( $parsed ) ? $parsed->get_error_code() : 'sitekey' );
		}
		return $parsed;
	}

	/**
	 * Is a newer core available? Cached per site key + version, so opening the
	 * tab does not call the API every time.
	 *
	 * @return array { status: current|available|no_key|not_equalweb|error, latest, detail, at }
	 */
	public static function update_status( array $s, $force = false ) {
		if ( ! self::is_equalweb( $s ) ) {
			return array( 'status' => 'not_equalweb', 'latest' => '', 'detail' => '', 'at' => 0 );
		}
		if ( '' === self::api_key() ) {
			return array( 'status' => 'no_key', 'latest' => '', 'detail' => '', 'at' => 0 );
		}
		$tag    = $s['sitekey'] . '|' . $s['core_version'];
		$cached = get_transient( self::UPDATE_TRANSIENT );
		if ( ! $force && is_array( $cached ) && isset( $cached['tag'] ) && $tag === $cached['tag'] ) {
			return $cached;
		}
		$latest = self::fetch_latest( $s );
		if ( is_wp_error( $latest ) ) {
			$out = array( 'status' => 'error', 'latest' => '', 'detail' => $latest->get_error_code(), 'at' => time() );
		} else {
			$newer = version_compare( $latest['core_version'], $s['core_version'], '>' );
			$out   = array( 'status' => $newer ? 'available' : 'current', 'latest' => $latest['core_version'], 'detail' => '', 'at' => time() );
		}
		$out['tag'] = $tag;
		// Errors are retried sooner (an hour), a result is kept for 12 h.
		set_transient( self::UPDATE_TRANSIENT, $out, 'error' === $out['status'] ? 3600 : self::UPDATE_TTL );
		return $out;
	}

	/** The settings with the core of a fresh code: version, hash, domains; the design is kept. */
	public static function with_core( array $s, array $latest ) {
		foreach ( array( 'core_version', 'integrity', 'cfasync', 'cdn', 'acc', 'for_domain' ) as $k ) {
			if ( isset( $latest[ $k ] ) ) {
				$s[ $k ] = $latest[ $k ];
			}
		}
		$s['raw_snippet'] = $latest['raw_snippet'];
		$s['last_check']  = array();
		return $s;
	}

	/**
	 * Fetch and apply the latest core now: the tab's "Update now". Never
	 * downgrades. Deliberately never automatic (nir, 2026-10-14): the admin
	 * decides when the live widget changes.
	 *
	 * @return string|WP_Error The version now in use.
	 */
	public static function update_core() {
		$s = self::get();
		if ( ! self::is_equalweb( $s ) ) {
			return new WP_Error( 'not_equalweb', '' );
		}
		$latest = self::fetch_latest( $s );
		if ( is_wp_error( $latest ) ) {
			return $latest;
		}
		if ( version_compare( $latest['core_version'], $s['core_version'], '>' ) ) {
			$s = self::with_core( $s, $latest );
			self::save( $s );
		}
		delete_transient( self::UPDATE_TRANSIENT );
		return $s['core_version'];
	}

	/** The status-card line for updates (escaped parts). */
	private static function update_row( array $s ) {
		$u = self::update_status( $s );
		if ( 'not_equalweb' === $u['status'] ) {
			return '';
		}
		if ( 'no_key' === $u['status'] ) {
			$url = admin_url( 'admin.php?page=' . EQW_Admin_Page::SLUG . '&tab=settings' );
			return self::status_row( __( 'Updates', 'equalweb' ), esc_html__( 'Add your EqualWeb API key to check for new widget versions and update from here.', 'equalweb' )
				. ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Add your API key', 'equalweb' ) . '</a>' );
		}
		$form = function ( $label, $primary, $name ) {
			return '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eqw-wupdate">'
				. '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_SAVE ) . '">'
				. '<input type="hidden" name="eqw_form" value="' . esc_attr( $name ) . '">'
				. wp_nonce_field( self::NONCE_SAVE, '_wpnonce', true, false )
				. '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
		};
		if ( 'available' === $u['status'] ) {
			/* translators: %s: core version number */
			$text = '<span class="eqw-badge eqw-warn">' . esc_html( sprintf( __( 'Version %s is available', 'equalweb' ), $u['latest'] ) ) . '</span> ';
			return self::status_row( __( 'Updates', 'equalweb' ), $text . $form( __( 'Update now', 'equalweb' ), true, 'update' ) );
		}
		if ( 'current' === $u['status'] ) {
			return self::status_row( __( 'Updates', 'equalweb' ), '<span class="eqw-badge eqw-ok">' . esc_html__( 'Up to date', 'equalweb' ) . '</span> ' . $form( __( 'Check again', 'equalweb' ), false, 'update_check' ) );
		}
		$why = array(
			'not_found'    => __( 'Your API key belongs to an EqualWeb account that does not own this site key.', 'equalweb' ),
			'bad_key'      => __( 'EqualWeb did not accept your API key. Check it in Settings.', 'equalweb' ),
			'http'         => __( 'EqualWeb could not be reached; it will be tried again later.', 'equalweb' ),
			'bad_response' => __( 'EqualWeb sent a code this plugin could not use.', 'equalweb' ),
		);
		$msg = isset( $why[ $u['detail'] ] ) ? $why[ $u['detail'] ] : $why['http'];
		return self::status_row( __( 'Updates', 'equalweb' ), '<span class="eqw-badge eqw-warn">' . esc_html( $msg ) . '</span> ' . $form( __( 'Check again', 'equalweb' ), false, 'update_check' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The 1.0 plugin: import and the old page                             */
	/* ------------------------------------------------------------------ */

	/**
	 * First run after the update from the 1.0 plugin (or a fresh install): the
	 * option does not exist yet. Import the 1.0 settings when there are any,
	 * so the widget keeps working with no admin action; otherwise store the
	 * defaults so this runs once.
	 */
	public static function maybe_migrate() {
		$stored = get_option( self::OPTION );
		if ( false !== $stored ) {
			if ( is_array( $stored ) && empty( $stored['domains_v'] ) ) {
				self::repair_domains();
			}
			return;
		}
		$s      = self::defaults();
		$legacy = get_option( self::LEGACY_CODE, '' );
		if ( is_string( $legacy ) && '' !== trim( $legacy ) ) {
			$parsed = self::parse_snippet( $legacy );
			if ( is_wp_error( $parsed ) ) {
				$s['mode']       = 'legacy';
				$s['legacy_raw'] = $legacy;
			} else {
				$s = array_merge( $s, $parsed );
				if ( isset( $parsed['btn'] ) ) {
					$s['btn'] = array_merge( self::defaults()['btn'], $parsed['btn'] );
				}
			}
			$s['enabled']     = (bool) get_option( self::LEGACY_ENABLE, 0 );
			$s['imported_v1'] = true;
		}
		$s['domains_v'] = 1;
		delete_option( self::LEGACY_REDIRECT );
		self::save( $s );
	}

	/**
	 * Once, for settings saved by 3.0.0, which knew only EqualWeb's domains:
	 *  - a Nagich code imported from 1.0 was refused by the parser and sits in
	 *    legacy mode; it is parsed now (the design comes from the code, as on
	 *    a first import);
	 *  - a parsed code had its domains replaced by EqualWeb's; they are read
	 *    again from the stored code. Nothing else (the design) changes.
	 */
	public static function repair_domains() {
		$s = self::get();
		if ( 'legacy' === $s['mode'] && '' !== $s['legacy_raw'] ) {
			$parsed = self::parse_snippet( $s['legacy_raw'] );
			if ( ! is_wp_error( $parsed ) ) {
				$s         = array_merge( $s, $parsed );
				$s['btn']  = isset( $parsed['btn'] ) ? array_merge( self::defaults()['btn'], $parsed['btn'] ) : self::defaults()['btn'];
				$s['mode'] = 'config';
				$s['legacy_raw'] = '';
			}
		} elseif ( '' !== $s['raw_snippet'] ) {
			$parsed = self::parse_snippet( $s['raw_snippet'] );
			if ( ! is_wp_error( $parsed ) ) {
				$s['cdn'] = $parsed['cdn'];
				$s['acc'] = $parsed['acc'];
			}
		}
		$s['domains_v'] = 1;
		self::save( $s );
	}

	/** The 1.0 settings page (Settings > EqualWeb) now opens the widget tab. */
	public static function maybe_redirect_legacy_page() {
		global $pagenow;
		if ( 'options-general.php' !== $pagenow || ! isset( $_GET['page'] ) || 'equalweb' !== sanitize_key( wp_unslash( $_GET['page'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a read-only redirect of a bookmarked admin URL.
			return;
		}
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	public static function tab_url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => EQW_Admin_Page::SLUG, 'tab' => 'widget' ), $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* The CDN lists                                                       */
	/* ------------------------------------------------------------------ */

	/** The core's language codes, from the CDN (cached a day), else the built-in copy. */
	public static function languages() {
		$cached = get_transient( self::LANG_TRANSIENT );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		$list = array();
		$res  = wp_remote_get( self::CDN . 'assets/data/lang.json', array( 'timeout' => 5, 'user-agent' => self::CHECK_UA ) );
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			if ( is_array( $json ) ) {
				foreach ( $json as $code ) {
					if ( is_string( $code ) && preg_match( '/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $code ) ) {
						$list[] = $code;
					}
				}
			}
		}
		$list = $list ? array_values( array_unique( $list ) ) : self::LANG_FALLBACK;
		set_transient( self::LANG_TRANSIENT, $list, DAY_IN_SECONDS );
		return $list;
	}

	public static function icon_url( $n ) {
		return self::CDN . 'assets/images/' . (int) $n . '.svg';
	}

	/* ------------------------------------------------------------------ */
	/* Saving from the tab, and the check                                  */
	/* ------------------------------------------------------------------ */

	/** admin-post.php?action=eqw_widget_save: the tab's forms (code / design / options). */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'equalweb' ), 403 );
		}
		check_admin_referer( self::NONCE_SAVE );
		$s    = self::get();
		$form = isset( $_POST['eqw_form'] ) ? sanitize_key( wp_unslash( $_POST['eqw_form'] ) ) : '';
		if ( 'code' === $form ) {
			$text   = isset( $_POST['eqw_snippet'] ) ? (string) wp_unslash( $_POST['eqw_snippet'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed by parse_snippet(); only parsed values are stored.
			$parsed = self::parse_snippet( $text );
			if ( is_wp_error( $parsed ) ) {
				wp_safe_redirect( self::tab_url( array( 'eqw_error' => $parsed->get_error_code() ) ) );
				exit;
			}
			$btn       = isset( $parsed['btn'] ) ? array_merge( self::defaults()['btn'], $parsed['btn'] ) : self::defaults()['btn'];
			$s         = array_merge( $s, $parsed );
			$s['btn']  = $btn;
			$s['mode'] = 'config';
			$s['legacy_raw'] = '';
			$s['enabled']    = true;
			$s['last_check'] = array();
			self::save( $s );
			wp_safe_redirect( self::tab_url( array( 'eqw_saved' => 'code' ) ) );
			exit;
		}
		if ( 'design' === $form ) {
			$in  = isset( $_POST['eqw_btn'] ) && is_array( $_POST['eqw_btn'] ) ? wp_unslash( $_POST['eqw_btn'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every field is sanitised in sanitize().
			$btn = array(
				'vposition'     => array( isset( $in['vposition_d'] ) ? $in['vposition_d'] : '', isset( $in['vposition_m'] ) ? $in['vposition_m'] : '' ),
				'margin'        => array( isset( $in['margin_d'] ) ? $in['margin_d'] : '', isset( $in['margin_m'] ) ? $in['margin_m'] : '' ),
				'scale'         => array( isset( $in['scale_d'] ) ? $in['scale_d'] : '', isset( $in['scale_m'] ) ? $in['scale_m'] : '' ),
				'color_main'    => isset( $in['color_main'] ) ? $in['color_main'] : '',
				'color_second'  => isset( $in['color_second'] ) ? $in['color_second'] : '',
				'icon_type'     => ( isset( $in['icon_mode'] ) && 'text' === $in['icon_mode'] ) ? ( isset( $in['icon_text'] ) ? $in['icon_text'] : '' ) : ( isset( $in['icon_type'] ) ? $in['icon_type'] : 1 ),
				'icon_shape'    => isset( $in['icon_shape'] ) ? $in['icon_shape'] : 'circle',
				'icon_outline'  => ! empty( $in['icon_outline'] ),
				'outline_color' => isset( $in['outline_color'] ) ? $in['outline_color'] : '',
			);
			$s['btn']       = $btn;
			$s['position']  = isset( $in['position'] ) ? $in['position'] : '';
			$s['menulang']  = isset( $in['menulang'] ) ? $in['menulang'] : '';
			$s['draggable'] = ! empty( $in['draggable'] );
			self::save( $s );
			wp_safe_redirect( self::tab_url( array( 'eqw_saved' => 'design' ) ) );
			exit;
		}
		if ( 'toggle' === $form ) {
			$s['enabled'] = ! $s['enabled'];
			self::save( $s );
			wp_safe_redirect( self::tab_url( array( 'eqw_saved' => $s['enabled'] ? 'on' : 'off' ) ) );
			exit;
		}
		if ( 'update' === $form ) {
			$r = self::update_core();
			wp_safe_redirect( self::tab_url( is_wp_error( $r ) ? array( 'eqw_error' => 'upd_' . $r->get_error_code() ) : array( 'eqw_saved' => 'updated' ) ) );
			exit;
		}
		if ( 'update_check' === $form ) {
			self::update_status( $s, true );
			wp_safe_redirect( self::tab_url() );
			exit;
		}
		if ( 'options' === $form ) {
			$s['show_to_admins'] = ! empty( $_POST['eqw_show_to_admins'] );
			$s['hide_btn']       = ! empty( $_POST['eqw_hide_btn'] );
			self::save( $s );
			wp_safe_redirect( self::tab_url( array( 'eqw_saved' => 'options' ) ) );
			exit;
		}
		if ( 'reset' === $form && '' !== $s['raw_snippet'] ) {
			// Back to the design in the code from EqualWeb.
			$parsed = self::parse_snippet( $s['raw_snippet'] );
			if ( ! is_wp_error( $parsed ) ) {
				$s        = array_merge( $s, $parsed );
				$s['btn'] = isset( $parsed['btn'] ) ? array_merge( self::defaults()['btn'], $parsed['btn'] ) : self::defaults()['btn'];
				self::save( $s );
			}
			wp_safe_redirect( self::tab_url( array( 'eqw_saved' => 'reset' ) ) );
			exit;
		}
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	/**
	 * "Check on your site": fetch the front page as a visitor and look for the
	 * site key and the core script. Answers ok / partial / missing / error, or
	 * duplicate (the core loads twice: the code is also in the theme or another
	 * plugin); with the widget off, gone / still_on (a page cache or a copy of
	 * the code elsewhere keeps it on the site).
	 */
	public static function handle_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'equalweb' ) ), 403 );
		}
		check_ajax_referer( EQW_Ajax::NONCE );
		$s      = self::get();
		$result = self::check_site( $s );
		$s['last_check'] = $result;
		self::save( $s );
		wp_send_json_success( $result );
	}

	public static function check_site( array $s ) {
		$url = home_url( '/' );
		$res = wp_remote_get( $url, array(
			'timeout'    => 20,
			'user-agent' => self::CHECK_UA,
			'headers'    => array( 'Cache-Control' => 'no-cache', 'Pragma' => 'no-cache' ),
		) );
		$now = time();
		if ( is_wp_error( $res ) ) {
			return array( 'at' => $now, 'status' => 'error', 'detail' => $res->get_error_message() );
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = (string) wp_remote_retrieve_body( $res );
		if ( $code < 200 || $code >= 400 ) {
			return array( 'at' => $now, 'status' => 'error', 'detail' => 'HTTP ' . $code );
		}
		// Every EqualWeb/Nagich loader names the core file; more than one is a double install.
		$cores = preg_match_all( '#/core/[0-9.]+/accessibility\.js#', $body );
		if ( empty( $s['enabled'] ) ) {
			return array( 'at' => $now, 'status' => ( $cores > 0 || false !== stripos( $body, 'window.interdeal' ) ) ? 'still_on' : 'gone', 'detail' => '' );
		}
		if ( $cores > 1 ) {
			return array( 'at' => $now, 'status' => 'duplicate', 'detail' => (string) $cores );
		}
		$has_key  = '' !== $s['sitekey'] && false !== stripos( $body, $s['sitekey'] );
		$has_core = false !== strpos( $body, 'core/' . $s['core_version'] . '/accessibility.js' );
		if ( 'legacy' === $s['mode'] ) {
			$has_key  = false !== stripos( $body, 'interdeal' );
			$has_core = false !== strpos( $body, 'accessibility.js' );
		}
		if ( $has_key && $has_core ) {
			return array( 'at' => $now, 'status' => 'ok', 'detail' => '' );
		}
		if ( $has_key || $has_core ) {
			return array( 'at' => $now, 'status' => 'partial', 'detail' => $has_key ? 'config' : 'core' );
		}
		return array( 'at' => $now, 'status' => 'missing', 'detail' => '' );
	}

	/* ------------------------------------------------------------------ */
	/* The tab                                                             */
	/* ------------------------------------------------------------------ */

	/** The site key with only its first and last characters showing. */
	private static function masked_key( $key ) {
		return '' === $key ? '' : substr( $key, 0, 6 ) . str_repeat( '•', 20 ) . substr( $key, -6 );
	}

	/** One notice line for the status card: a badge + text, from escaped parts. */
	private static function status_row( $label, $value_html ) {
		return '<div class="eqw-wstatus-row"><span class="eqw-wstatus-label">' . esc_html( $label ) . '</span><span class="eqw-wstatus-value">' . $value_html . '</span></div>';
	}

	/** The "Accessibility widget" tab (manage_options only; the caller checks). */
	public static function render_tab() {
		$s        = self::get();
		$host     = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$saved    = isset( $_GET['eqw_saved'] ) ? sanitize_key( wp_unslash( $_GET['eqw_saved'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a flag set by our own redirect, display only.
		$error    = isset( $_GET['eqw_error'] ) ? sanitize_key( wp_unslash( $_GET['eqw_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- same.
		$errors   = array(
			'empty'      => __( 'Paste the installation code from your EqualWeb account.', 'equalweb' ),
			'no_sitekey' => __( 'This does not look like an EqualWeb installation code: no site key was found. Copy the whole code from your EqualWeb account.', 'equalweb' ),
			'no_core'    => __( 'The code does not say which EqualWeb core version to load. Copy the whole code, including the part below the settings.', 'equalweb' ),
			'upd_no_key'       => __( 'Add your EqualWeb API key in Settings to update the widget from here.', 'equalweb' ),
			'upd_not_found'    => __( 'Your API key belongs to an EqualWeb account that does not own this site key.', 'equalweb' ),
			'upd_bad_key'      => __( 'EqualWeb did not accept your API key. Check it in Settings.', 'equalweb' ),
			'upd_http'         => __( 'EqualWeb could not be reached. Try again in a few minutes.', 'equalweb' ),
			'upd_bad_response' => __( 'EqualWeb sent a code this plugin could not use. Paste the installation code from your account instead.', 'equalweb' ),
			'upd_not_equalweb' => __( 'Updates from here work for codes from an EqualWeb account. Paste the new code from your account instead.', 'equalweb' ),
			'bad_domain' => __( 'The code loads its script from an address that is not EqualWeb or Nagich. Only the code from your EqualWeb or Nagich account can be used.', 'equalweb' ),
		);
		$saved_msgs = array(
			'code'    => __( 'The installation code was saved and the widget is on.', 'equalweb' ),
			'design'  => __( 'The button design was saved.', 'equalweb' ),
			'options' => __( 'The options were saved.', 'equalweb' ),
			'updated' => __( 'The widget now uses the latest version from EqualWeb. Your design is kept.', 'equalweb' ),
			'reset'   => __( 'The design was reset to the code from your EqualWeb account.', 'equalweb' ),
			'on'      => __( 'The widget is now on your site.', 'equalweb' ),
			'off'     => __( 'The widget was removed from your site. Your code and design are kept.', 'equalweb' ),
		);
		$configured = 'legacy' !== $s['mode'] && '' !== $s['sitekey'] && '' !== $s['core_version'];
		$langs      = self::languages();
		$check      = $s['last_check'];
		$b          = $s['btn'];
		$icon_is_text = ! is_int( $b['icon_type'] );
		?>
		<div class="eqw-widget" data-eqw-cdn="<?php echo esc_attr( $s['cdn'] ); ?>" data-eqw-acc="<?php echo esc_attr( $s['acc'] ); ?>" data-eqw-core="<?php echo esc_attr( $configured ? self::core_url( $s ) : '' ); ?>" data-eqw-integrity="<?php echo esc_attr( $s['integrity'] ); ?>" data-eqw-sitekey="<?php echo esc_attr( $configured ? $s['sitekey'] : '' ); ?>">
			<?php if ( '' !== $saved && isset( $saved_msgs[ $saved ] ) ) : ?>
				<div class="notice notice-success inline eqw-wnotice"><p><?php echo esc_html( $saved_msgs[ $saved ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' !== $error ) : ?>
				<div class="notice notice-error inline eqw-wnotice"><p><?php echo esc_html( isset( $errors[ $error ] ) ? $errors[ $error ] : __( 'The code could not be saved.', 'equalweb' ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( $s['imported_v1'] && '' === $saved ) : ?>
				<div class="notice notice-info inline eqw-wnotice"><p><?php esc_html_e( 'Your accessibility widget settings were imported from the previous version of this plugin. Nothing changed on your site; you can now design the button below.', 'equalweb' ); ?></p></div>
			<?php endif; ?>

			<div class="eqw-wtop">
			<div class="eqw-explain eqw-wstatus">
				<h2><?php esc_html_e( 'Accessibility widget', 'equalweb' ); ?>
					<?php if ( $s['enabled'] && ( $configured || 'legacy' === $s['mode'] ) ) : ?>
						<span class="eqw-badge eqw-ok"><?php esc_html_e( 'On', 'equalweb' ); ?></span>
					<?php else : ?>
						<span class="eqw-badge eqw-warn"><?php esc_html_e( 'Off', 'equalweb' ); ?></span>
					<?php endif; ?>
				</h2>
				<p class="description"><?php esc_html_e( 'The EqualWeb accessibility widget gives your visitors an accessibility menu on every page. It needs the installation code from your EqualWeb account; the button can then be designed here.', 'equalweb' ); ?></p>
				<?php
				if ( 'legacy' === $s['mode'] ) {
					echo '<div class="notice notice-warning inline eqw-wnotice"><p>' . esc_html__( 'The code imported from the previous version could not be read, so it is used exactly as it was. Paste the current installation code from your EqualWeb account below to design the button from here.', 'equalweb' ) . '</p></div>';
				} elseif ( $configured ) {
					echo self::status_row( __( 'Site key', 'equalweb' ), '<code>' . esc_html( self::masked_key( $s['sitekey'] ) ) . '</code>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
					echo self::status_row( __( 'Core version', 'equalweb' ), '<code>' . esc_html( $s['core_version'] ) . '</code>' . ( '' === $s['integrity'] ? ' <span class="eqw-badge eqw-warn">' . esc_html__( 'no integrity hash', 'equalweb' ) . '</span>' : '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
					echo self::update_row( $s ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
					if ( '' !== $s['for_domain'] ) {
						$same = ( $s['for_domain'] === $host ) || ( 'www.' . $s['for_domain'] === $host ) || ( $s['for_domain'] === 'www.' . $host );
						echo self::status_row( __( 'Code generated for', 'equalweb' ), '<code>' . esc_html( $s['for_domain'] ) . '</code>' . ( $same ? '' : ' <span class="eqw-badge eqw-warn">' . esc_html( sprintf( /* translators: %s: this site's host name */ __( 'this site is %s', 'equalweb' ), $host ) ) . '</span>' ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
					}
					if ( $s['unknown'] ) {
						echo self::status_row( __( 'Not designable here', 'equalweb' ), esc_html( implode( ', ', $s['unknown'] ) ) . ' <span class="description">' . esc_html__( '(kept from your code as is)', 'equalweb' ) . '</span>' ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped parts.
					}
				} else {
					echo '<p>' . esc_html__( 'Not set up yet: paste the installation code below.', 'equalweb' ) . '</p>';
				}
				?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eqw-wtoggle">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
					<input type="hidden" name="eqw_form" value="toggle">
					<?php wp_nonce_field( self::NONCE_SAVE ); ?>
					<?php if ( $s['enabled'] ) : ?>
						<button type="submit" class="button"><?php esc_html_e( 'Remove the widget from this site', 'equalweb' ); ?></button>
						<span class="description"><?php esc_html_e( 'The widget stops loading; your code and design are kept.', 'equalweb' ); ?></span>
					<?php else : ?>
						<button type="submit" class="button button-primary" <?php disabled( ! ( $configured || 'legacy' === $s['mode'] ) ); ?>><?php esc_html_e( 'Install the widget on this site', 'equalweb' ); ?></button>
						<?php if ( ! ( $configured || 'legacy' === $s['mode'] ) ) : ?><span class="description"><?php esc_html_e( 'Save the installation code first.', 'equalweb' ); ?></span><?php endif; ?>
					<?php endif; ?>
				</form>
				<div class="eqw-wcheck">
					<button type="button" class="button" id="eqw-widget-check" <?php disabled( ! ( $configured || 'legacy' === $s['mode'] ) ); ?>><?php esc_html_e( 'Check on your site', 'equalweb' ); ?></button>
					<span class="eqw-wcheck-result" role="status">
						<?php
						if ( ! empty( $check['at'] ) ) {
							$status_text = array(
								'ok'      => __( 'The widget is on your site.', 'equalweb' ),
								'partial' => __( 'Only part of the widget was found on your site (a cache or another plugin may be in the way).', 'equalweb' ),
								'missing' => __( 'The widget was not found on your site. A page cache may still serve old pages; purge it and check again.', 'equalweb' ),
								'error'     => __( 'Your site could not be fetched.', 'equalweb' ),
								'duplicate' => __( 'The widget loads more than once on your site. The code is probably also pasted in your theme or another plugin; remove it there so only this plugin installs it.', 'equalweb' ),
								'still_on'  => __( 'The widget was removed here, but your site still shows it: purge your page cache, and check your theme or other plugins for a copy of the code.', 'equalweb' ),
								'gone'      => __( 'The widget is not on your site.', 'equalweb' ),
							);
							$cls = in_array( $check['status'], array( 'ok', 'gone' ), true ) ? 'eqw-ok' : ( in_array( $check['status'], array( 'error', 'duplicate', 'still_on' ), true ) ? 'eqw-bad' : 'eqw-warn' );
							echo '<span class="eqw-badge ' . esc_attr( $cls ) . '">' . esc_html( isset( $status_text[ $check['status'] ] ) ? $status_text[ $check['status'] ] : $check['status'] ) . '</span> ';
							echo esc_html( sprintf( /* translators: %s: date and time */ __( 'Last checked %s.', 'equalweb' ), date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $check['at'] ) ) );
							if ( ! empty( $check['detail'] ) && 'error' === $check['status'] ) {
								echo ' <code>' . esc_html( $check['detail'] ) . '</code>';
							}
						}
						?>
					</span>
				</div>
			<div class="eqw-explain">
				<h2><?php esc_html_e( 'Options', 'equalweb' ); ?></h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
					<input type="hidden" name="eqw_form" value="options">
					<?php wp_nonce_field( self::NONCE_SAVE ); ?>
					<p><label><input type="checkbox" name="eqw_show_to_admins" value="1" <?php checked( $s['show_to_admins'] ); ?>> <?php esc_html_e( 'Also show it to logged-in administrators (turn off while you edit the site)', 'equalweb' ); ?></label></p>
					<p><label><input type="checkbox" name="eqw_hide_btn" value="1" <?php checked( $s['hide_btn'] ); ?>> <?php esc_html_e( 'Use my own button instead of the EqualWeb button', 'equalweb' ); ?></label></p>
					<p class="description"><?php esc_html_e( 'With your own button, place the shortcode where the button should appear; your theme styles it and EqualWeb opens the menu from it. Needs core 5.0.5 or newer.', 'equalweb' ); ?> <code>[equalweb_button text="Accessibility"]</code></p>
					<p class="eqw-wactions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save options', 'equalweb' ); ?></button></p>
				</form>
			</div>
			</div>
			</div>

			<div class="eqw-explain">
				<h2><?php esc_html_e( 'Installation code', 'equalweb' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'In your EqualWeb account, open your website and copy the installation code. Each new core version comes with a new code: with your EqualWeb API key saved in Settings, this tab shows a new version and updates it in one click; otherwise paste it again when EqualWeb asks you to update.', 'equalweb' ); ?>
					<a href="<?php echo esc_url( EQW_Admin_Page::SIGNUP_URL ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'No account yet? Create one at EqualWeb', 'equalweb' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens in a new tab)', 'equalweb' ); ?></span><?php echo EQW_Admin_Page::icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput -- plugin-built SVG. ?></a>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
					<input type="hidden" name="eqw_form" value="code">
					<?php wp_nonce_field( self::NONCE_SAVE ); ?>
					<label for="eqw-snippet" class="screen-reader-text"><?php esc_html_e( 'Installation code', 'equalweb' ); ?></label>
					<textarea id="eqw-snippet" name="eqw_snippet" rows="5" class="large-text code" spellcheck="false" placeholder="<?php esc_attr_e( 'Paste the whole code here, from <!-- Accessibility Code ... to </script>', 'equalweb' ); ?>"><?php echo esc_textarea( 'legacy' === $s['mode'] ? $s['legacy_raw'] : $s['raw_snippet'] ); ?></textarea>
					<p class="eqw-wactions"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save code', 'equalweb' ); ?></button></p>
				</form>
			</div>


			<div class="eqw-explain eqw-wdesign">
				<h2><?php esc_html_e( 'Design the button', 'equalweb' ); ?></h2>
				<p class="description"><?php esc_html_e( 'The designer starts from the design in your installation code. What you set here is what your visitors get; the preview loads the real widget with these settings. Leave a field empty to keep the EqualWeb default.', 'equalweb' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="eqw-design-form" class="eqw-wdesign-grid">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION_SAVE ); ?>">
					<input type="hidden" name="eqw_form" value="design">
					<?php wp_nonce_field( self::NONCE_SAVE ); ?>
					<div class="eqw-wcontrols">
						<fieldset>
							<legend><?php esc_html_e( 'Placement', 'equalweb' ); ?></legend>
							<p>
								<label for="eqw-position"><?php esc_html_e( 'Side', 'equalweb' ); ?></label>
								<select id="eqw-position" name="eqw_btn[position]">
									<option value="" <?php selected( $s['position'], '' ); ?>><?php esc_html_e( 'Automatic (by language direction)', 'equalweb' ); ?></option>
									<option value="left" <?php selected( $s['position'], 'left' ); ?>><?php esc_html_e( 'Left', 'equalweb' ); ?></option>
									<option value="right" <?php selected( $s['position'], 'right' ); ?>><?php esc_html_e( 'Right', 'equalweb' ); ?></option>
								</select>
							</p>
							<p class="eqw-wpair">
								<label for="eqw-vposition-d"><?php esc_html_e( 'Vertical position', 'equalweb' ); ?></label>
								<input type="text" id="eqw-vposition-d" name="eqw_btn[vposition_d]" value="<?php echo esc_attr( $b['vposition'][0] ); ?>" placeholder="80%" pattern="-?\d+(\.\d+)?(px|%|em|rem|vh|vw)?" title="<?php esc_attr_e( 'A number, a percentage or pixels, e.g. 80% or 24px', 'equalweb' ); ?>"> <span class="description"><?php esc_html_e( 'desktop', 'equalweb' ); ?></span>
								<input type="text" id="eqw-vposition-m" name="eqw_btn[vposition_m]" value="<?php echo esc_attr( $b['vposition'][1] ); ?>" placeholder="80%" pattern="-?\d+(\.\d+)?(px|%|em|rem|vh|vw)?" aria-label="<?php esc_attr_e( 'Vertical position, mobile', 'equalweb' ); ?>"> <span class="description"><?php esc_html_e( 'mobile', 'equalweb' ); ?></span>
							</p>
							<p class="description"><?php esc_html_e( 'A percentage above 50 is measured from the bottom, so 80% sits near the bottom of the screen.', 'equalweb' ); ?></p>
							<p class="eqw-wpair">
								<label for="eqw-margin-d"><?php esc_html_e( 'Distance from the edge', 'equalweb' ); ?></label>
								<input type="text" id="eqw-margin-d" name="eqw_btn[margin_d]" value="<?php echo esc_attr( $b['margin'][0] ); ?>" placeholder="0" pattern="-?\d+(\.\d+)?(px|%|em|rem|vh|vw)?"> <span class="description"><?php esc_html_e( 'desktop', 'equalweb' ); ?></span>
								<input type="text" id="eqw-margin-m" name="eqw_btn[margin_m]" value="<?php echo esc_attr( $b['margin'][1] ); ?>" placeholder="0" pattern="-?\d+(\.\d+)?(px|%|em|rem|vh|vw)?" aria-label="<?php esc_attr_e( 'Distance from the edge, mobile', 'equalweb' ); ?>"> <span class="description"><?php esc_html_e( 'mobile', 'equalweb' ); ?></span>
							</p>
							<p class="eqw-wpair">
								<label for="eqw-scale-d"><?php esc_html_e( 'Size', 'equalweb' ); ?></label>
								<input type="number" id="eqw-scale-d" name="eqw_btn[scale_d]" value="<?php echo esc_attr( $b['scale'][0] ); ?>" placeholder="1" min="0.1" max="3" step="0.05"> <span class="description"><?php esc_html_e( 'desktop', 'equalweb' ); ?></span>
								<input type="number" id="eqw-scale-m" name="eqw_btn[scale_m]" value="<?php echo esc_attr( $b['scale'][1] ); ?>" placeholder="1" min="0.1" max="3" step="0.05" aria-label="<?php esc_attr_e( 'Size, mobile', 'equalweb' ); ?>"> <span class="description"><?php esc_html_e( 'mobile (1 = the EqualWeb size)', 'equalweb' ); ?></span>
							</p>
							<p><label><input type="checkbox" name="eqw_btn[draggable]" value="1" <?php checked( $s['draggable'] ); ?>> <?php esc_html_e( 'Visitors can drag the button', 'equalweb' ); ?></label></p>
						</fieldset>
						<fieldset>
							<legend><?php esc_html_e( 'Colours', 'equalweb' ); ?></legend>
							<p class="eqw-wcolor">
								<label for="eqw-color-main"><?php esc_html_e( 'Button colour', 'equalweb' ); ?></label>
								<input type="color" id="eqw-color-main-pick" value="<?php echo esc_attr( '' !== $b['color_main'] ? substr( $b['color_main'], 0, 7 ) : '#171d2d' ); ?>" aria-label="<?php esc_attr_e( 'Pick the button colour', 'equalweb' ); ?>">
								<input type="text" id="eqw-color-main" name="eqw_btn[color_main]" value="<?php echo esc_attr( $b['color_main'] ); ?>" placeholder="#171d2d" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})" class="eqw-whex">
							</p>
							<p class="eqw-wcolor">
								<label for="eqw-color-second"><?php esc_html_e( 'Icon colour', 'equalweb' ); ?></label>
								<input type="color" id="eqw-color-second-pick" value="<?php echo esc_attr( '' !== $b['color_second'] ? substr( $b['color_second'], 0, 7 ) : '#ffffff' ); ?>" aria-label="<?php esc_attr_e( 'Pick the icon colour', 'equalweb' ); ?>">
								<input type="text" id="eqw-color-second" name="eqw_btn[color_second]" value="<?php echo esc_attr( $b['color_second'] ); ?>" placeholder="#ffffff" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})" class="eqw-whex">
							</p>
							<p class="eqw-wcontrast" role="status" id="eqw-contrast"></p>
							<p><label><input type="checkbox" id="eqw-outline" name="eqw_btn[icon_outline]" value="1" <?php checked( $b['icon_outline'] ); ?>> <?php esc_html_e( 'Outline around the button', 'equalweb' ); ?></label></p>
							<p class="eqw-wcolor">
								<label for="eqw-outline-color"><?php esc_html_e( 'Outline colour', 'equalweb' ); ?></label>
								<input type="color" id="eqw-outline-color-pick" value="<?php echo esc_attr( '' !== $b['outline_color'] ? substr( $b['outline_color'], 0, 7 ) : '#ffffff' ); ?>" aria-label="<?php esc_attr_e( 'Pick the outline colour', 'equalweb' ); ?>">
								<input type="text" id="eqw-outline-color" name="eqw_btn[outline_color]" value="<?php echo esc_attr( $b['outline_color'] ); ?>" placeholder="#ffffff" pattern="#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})" class="eqw-whex">
							</p>
						</fieldset>
						<fieldset>
							<legend><?php esc_html_e( 'Icon and shape', 'equalweb' ); ?></legend>
							<p>
								<label><input type="radio" name="eqw_btn[icon_mode]" value="icon" <?php checked( ! $icon_is_text ); ?>> <?php esc_html_e( 'An icon', 'equalweb' ); ?></label>
								&nbsp; <label><input type="radio" name="eqw_btn[icon_mode]" value="text" <?php checked( $icon_is_text ); ?>> <?php esc_html_e( 'A short text', 'equalweb' ); ?></label>
							</p>
							<?php $tile_style = '--eqw-wmain:' . ( '' !== $b['color_main'] ? $b['color_main'] : '#171d2d' ) . ';--eqw-wsecond:' . ( '' !== $b['color_second'] ? $b['color_second'] : '#ffffff' ) . ';'; ?>
							<div class="eqw-wicons eqw-wshape-<?php echo esc_attr( $b['icon_shape'] ); ?><?php echo 'right' === $s['position'] ? ' eqw-wright' : ''; ?>" role="radiogroup" aria-label="<?php esc_attr_e( 'Icon', 'equalweb' ); ?>" style="<?php echo esc_attr( $tile_style ); ?>">
								<?php for ( $i = self::ICON_MIN; $i <= self::ICON_MAX; $i++ ) : ?>
									<label class="eqw-wicon">
										<input type="radio" name="eqw_btn[icon_type]" value="<?php echo (int) $i; ?>" <?php checked( ! $icon_is_text && (int) $b['icon_type'] === $i ); ?>>
										<span class="eqw-wicon-tile" aria-hidden="true"><span class="eqw-wicon-glyph" style="-webkit-mask-image:url(<?php echo esc_url( self::icon_url( $i ) ); ?>);mask-image:url(<?php echo esc_url( self::icon_url( $i ) ); ?>)"></span></span>
										<span class="screen-reader-text"><?php echo esc_html( sprintf( /* translators: %d: icon number */ __( 'Icon %d', 'equalweb' ), $i ) ); ?></span>
									</label>
								<?php endfor; ?>
							</div>
							<p class="eqw-wicon-text">
								<label for="eqw-icon-text"><?php esc_html_e( 'Text', 'equalweb' ); ?></label>
								<input type="text" id="eqw-icon-text" name="eqw_btn[icon_text]" value="<?php echo esc_attr( $icon_is_text ? $b['icon_type'] : '' ); ?>" maxlength="20" placeholder="<?php esc_attr_e( 'Accessibility', 'equalweb' ); ?>">
							</p>
							<p>
								<label for="eqw-shape"><?php esc_html_e( 'Shape', 'equalweb' ); ?></label>
								<select id="eqw-shape" name="eqw_btn[icon_shape]">
									<?php $shape_names = array( 'circle' => __( 'Circle', 'equalweb' ), 'rectangle' => __( 'Square', 'equalweb' ), 'rounded' => __( 'Rounded square', 'equalweb' ), 'semicircle' => __( 'Semicircle (flat side at the edge)', 'equalweb' ) ); ?>
									<?php foreach ( self::SHAPES as $shape ) : ?>
										<option value="<?php echo esc_attr( $shape ); ?>" <?php selected( $b['icon_shape'], $shape ); ?>><?php echo esc_html( $shape_names[ $shape ] ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
							<p>
								<label for="eqw-menulang"><?php esc_html_e( 'Menu language', 'equalweb' ); ?></label>
								<select id="eqw-menulang" name="eqw_btn[menulang]">
									<option value="" <?php selected( $s['menulang'], '' ); ?>><?php esc_html_e( 'Automatic (the page language)', 'equalweb' ); ?></option>
									<?php foreach ( $langs as $code ) : ?>
										<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $s['menulang'], $code ); ?>><?php echo esc_html( $code ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						</fieldset>
						<p class="eqw-wactions">
							<button type="submit" class="button button-primary" <?php disabled( ! $configured ); ?>><?php esc_html_e( 'Save design', 'equalweb' ); ?></button>
							<?php if ( '' !== $s['raw_snippet'] && 'legacy' !== $s['mode'] ) : ?>
								<button type="submit" class="button" name="eqw_form" value="reset"><?php esc_html_e( 'Reset to the code from EqualWeb', 'equalweb' ); ?></button>
							<?php endif; ?>
						</p>
					</div>
					<div class="eqw-wpreview">
						<h3><?php esc_html_e( 'Preview', 'equalweb' ); ?></h3>
						<?php if ( $configured ) : ?>
							<iframe id="eqw-preview" title="<?php esc_attr_e( 'Preview of the accessibility button', 'equalweb' ); ?>" sandbox="allow-scripts allow-same-origin" referrerpolicy="origin"></iframe>
							<p class="description"><?php esc_html_e( 'The preview updates as you change the settings. The real widget is loaded, so the menu opens here too.', 'equalweb' ); ?></p>
						<?php else : ?>
							<p class="description"><?php esc_html_e( 'Save the installation code first; the preview then shows the button with your design.', 'equalweb' ); ?></p>
						<?php endif; ?>
					</div>
				</form>
			</div>
		</div>
		<?php
	}
}
