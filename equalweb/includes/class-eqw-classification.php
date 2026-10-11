<?php
/**
 * Classification & triage engine (v1, WordPress signals).
 *
 * Assigns every managed document a category + priority score from metadata
 * the plugin already has, plus admin-supplied location tags. Design rules
 * (docs/classification-triage-spec-2026-07.md):
 *  - The engine NEVER excludes a document or calls anything "exempt" —
 *    categories set PRIORITY only. Only an explicit, logged, reversible
 *    admin action (ignore) hides a row, and analytics still count it.
 *  - A signal that was not evaluated is "unknown", never a silent negative.
 *  - Admin tags (per-location or per-file) are first-class and always win
 *    over automatic rules; they are recorded with who/when.
 *  - Every result carries the matched rules — the "why" behind the rank.
 *
 * WordPress v1 signals (cheap, all local):
 *  - attachment post_parent is a PUBLISHED post: 'public'.
 *  - attached only to unpublished content, or not referenced: 'internal'.
 *  - _wp_attached_file path contains /archive|/old|/backup, or the upload is
 *    older than the (opt-in) age threshold while not publicly linked: 'archive'.
 *  - 'service' (required to apply for / access a service) is NOT machine-
 *    detectable — it comes only from admin tagging; filename heuristics
 *    merely SUGGEST it on the Classification screen.
 *
 * Automatic results are computed PER REQUEST and never persisted; only human
 * decisions live in postmeta/options, so nothing can go stale.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EQW_Classification {

	/** Option: location tags array — prefix => [ cat, by, at ]. */
	const OPT_LOCATIONS = 'eqw_pdf_cls_locations';

	/** Option: auto-archive age threshold in years (default 2; 0 = off). */
	const OPT_ARCHIVE_YEARS = 'eqw_pdf_cls_archive_years';

	/** Per-file human decision (postmeta). Only these persist. */
	const META_CAT        = '_eqw_cls_cat';
	const META_PRIO       = '_eqw_cls_prio';
	const META_SRC        = '_eqw_cls_src';   // 'user' when an admin tagged this file.
	const META_RULES      = '_eqw_cls_rules'; // array — the "why".
	const META_IGNORED    = '_eqw_ignored';
	const META_IGNORED_BY = '_eqw_ignored_by';
	const META_IGNORED_AT = '_eqw_ignored_at';

	/** Category => [ label, swatch color, table flag, priority for admin tags ]. */
	const CATS = array(
		'service'  => array( 'label' => 'Required for a service', 'color' => '#a12727', 'flag' => 'Fix first', 'prio' => 94 ),
		'public'   => array( 'label' => 'Publicly published', 'color' => '#9a4b00', 'flag' => 'High', 'prio' => 76 ),
		'internal' => array( 'label' => 'Internal only', 'color' => '#5b6472', 'flag' => 'In scope if used', 'prio' => 38 ),
		'archive'  => array( 'label' => 'Archive', 'color' => '#a7aaad', 'flag' => 'Possibly exempt — confirm', 'prio' => 12 ),
	);

	/** Filename pattern that suggests "required for a service" to the admin. */
	const SERVICE_HINT = '/form|request|claim|application|enrol|apply|טופס|בקשה/iu';

	/** Path pattern that auto-classifies as Archive (leading slash required). */
	const ARCHIVE_PATH = '#/(archive|archived|old|backup|backups)(/|$)#i';

	/** The category metadata (exposed so callers avoid const array dereference). */
	public static function cats() {
		return self::CATS;
	}

	/**
	 * Age (in years) after which an UNLINKED file is auto-classified Archive.
	 * Ships ON, defaulting to 2 years; set 0 to turn the age rule off (opt-out).
	 * The customer can raise, lower or disable it. Age never overrides active
	 * use: a file referenced by published content stays out of Archive at any
	 * age (the legal "currently used" line), and folder paths literally named
	 * /archive|/old still classify regardless of this setting.
	 *
	 * @return int 0..15.
	 */
	public static function archive_years() {
		$years = (int) get_option( self::OPT_ARCHIVE_YEARS, 2 );
		return max( 0, min( 15, $years ) );
	}

	public static function set_archive_years( $years ) {
		update_option( self::OPT_ARCHIVE_YEARS, max( 0, min( 15, (int) $years ) ), false );
	}

	/* ---------------------------------------------------------------- */
	/* Location tags (admin "tell us how it's used")                     */
	/* ---------------------------------------------------------------- */

	/**
	 * The uploads-relative path of an attachment, normalized to a single
	 * leading slash so root files live under a real, matchable prefix ("/").
	 * e.g. "2026/07/x.pdf" -> "/2026/07/x.pdf"; "x.pdf" -> "/x.pdf".
	 *
	 * @param int $attachment_id
	 * @return string
	 */
	public static function uri( $attachment_id ) {
		$rel = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$rel = ltrim( str_replace( '\\', '/', $rel ), '/' );
		return '/' . $rel;
	}

	/** Filename component of a normalized uri. */
	public static function filename( $uri ) {
		$pos = strrpos( $uri, '/' );
		return false === $pos ? $uri : substr( $uri, $pos + 1 );
	}

	/**
	 * Directory prefix of a normalized uri, e.g. "/2026/07/x.pdf" ->
	 * "/2026/07/"; root files -> "/".
	 */
	public static function dir_prefix( $uri ) {
		$pos = strrpos( $uri, '/' );
		return false === $pos ? $uri : substr( $uri, 0, $pos + 1 );
	}

	/**
	 * Top-level location grouping for a uri. WordPress's library is shallow
	 * (YYYY/MM), so the file's own directory is the grouping unit; root files
	 * group under "/". A tag on a shallower ancestor still cascades via the
	 * longest-prefix match in classify_all().
	 */
	public static function top_location( $uri ) {
		return self::dir_prefix( $uri );
	}

	/** All saved location tags: prefix => [ cat, by, at ]. */
	public static function location_tags() {
		$tags = get_option( self::OPT_LOCATIONS, array() );
		return is_array( $tags ) ? $tags : array();
	}

	/** Save/replace one location tag (logged: who + when). */
	public static function tag_location( $prefix, $cat ) {
		$tags             = self::location_tags();
		$tags[ $prefix ]  = array(
			'cat' => $cat,
			'by'  => wp_get_current_user()->display_name,
			'at'  => time(),
		);
		update_option( self::OPT_LOCATIONS, $tags, false );
	}

	public static function clear_location( $prefix ) {
		$tags = self::location_tags();
		unset( $tags[ $prefix ] );
		update_option( self::OPT_LOCATIONS, $tags, false );
	}

	/** Longest location-tag prefix matching a uri, or null. */
	private static function match_location( $uri, $tags ) {
		$best     = null;
		$best_len = -1;
		foreach ( $tags as $prefix => $tag ) {
			// strpos (not str_starts_with) — the plugin targets PHP 7.4.
			if ( 0 === strpos( $uri, (string) $prefix ) && strlen( (string) $prefix ) > $best_len ) {
				$best     = array_merge( array( 'prefix' => $prefix ), (array) $tag );
				$best_len = strlen( (string) $prefix );
			}
		}
		return $best;
	}

	/** Human label for a location prefix. */
	public static function location_label( $prefix ) {
		return '/' === $prefix ? '/ (files root)' : $prefix;
	}

	/* ---------------------------------------------------------------- */
	/* Published-linkage signal (batched)                                */
	/* ---------------------------------------------------------------- */

	/**
	 * For a set of attachments: true when the attachment's post_parent is a
	 * PUBLISHED post, false when attached to unpublished content, null when it
	 * has no parent at all. ONE query resolves every parent's status — never
	 * one query per file.
	 *
	 * @param WP_Post[] $posts
	 * @return array id => true|false|null
	 */
	private static function published_linkage( $posts ) {
		$out     = array();
		$parents = array();
		foreach ( $posts as $post ) {
			$id         = (int) $post->ID;
			$out[ $id ] = null;
			$parent     = (int) $post->post_parent;
			if ( $parent > 0 ) {
				$parents[ $parent ][] = $id;
			}
		}
		if ( ! $parents ) {
			return $out;
		}
		global $wpdb;
		// Values are post_parent integers straight from the DB — safe to inline.
		$ids  = array_map( 'absint', array_keys( $parents ) );
		$in   = implode( ',', $ids );
		$rows = $wpdb->get_results( "SELECT ID, post_status FROM {$wpdb->posts} WHERE ID IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$status = array();
		foreach ( (array) $rows as $r ) {
			$status[ (int) $r->ID ] = $r->post_status;
		}
		foreach ( $parents as $parent => $child_ids ) {
			// A missing parent (deleted post) counts as unpublished linkage —
			// being attached at all is the stronger claim than "no parent".
			$is_pub = isset( $status[ $parent ] ) && 'publish' === $status[ $parent ];
			foreach ( $child_ids as $cid ) {
				$out[ $cid ] = $is_pub;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------- */
	/* Classify                                                          */
	/* ---------------------------------------------------------------- */

	/**
	 * Classify a list of attachments. Returns id => [ cat, prio, src, rules,
	 * ignored ]. src: 'user' (admin tag, wins) | 'auto'.
	 *
	 * @param WP_Post[] $posts
	 * @return array
	 */
	public static function classify_all( $posts ) {
		$linkage = self::published_linkage( $posts );
		$tags    = self::location_tags();
		$years   = self::archive_years();
		$out     = array();

		foreach ( $posts as $post ) {
			$id      = (int) $post->ID;
			$ignored = (bool) get_post_meta( $id, self::META_IGNORED, true );

			// 1) Per-file admin tag beats everything.
			$ucat = get_post_meta( $id, self::META_CAT, true );
			$usrc = get_post_meta( $id, self::META_SRC, true );
			if ( $ucat && isset( self::CATS[ $ucat ] ) && 'user' === $usrc ) {
				$rules      = get_post_meta( $id, self::META_RULES, true );
				$out[ $id ] = array(
					'cat'     => $ucat,
					'prio'    => (int) ( get_post_meta( $id, self::META_PRIO, true ) ? get_post_meta( $id, self::META_PRIO, true ) : self::CATS[ $ucat ]['prio'] ),
					'src'     => 'user',
					'rules'   => is_array( $rules ) && $rules ? $rules : array( 'Tagged by an administrator' ),
					'ignored' => $ignored,
				);
				continue;
			}

			$uri = self::uri( $id );

			// 2) Admin location tag (longest prefix wins; children inherit).
			$loc = self::match_location( $uri, $tags );
			if ( $loc ) {
				$cat = $loc['cat'];
				$by  = isset( $loc['by'] ) && '' !== $loc['by'] ? $loc['by'] : 'an administrator';
				$at  = isset( $loc['at'] ) ? (int) $loc['at'] : 0;
				$out[ $id ] = array(
					'cat'     => $cat,
					'prio'    => self::CATS[ $cat ]['prio'],
					'src'     => 'user',
					'rules'   => array(
						'Inherited from ' . self::location_label( $loc['prefix'] )
						. ' — tagged by ' . $by
						. ( $at ? ' on ' . date_i18n( 'M j, Y', $at ) : '' ) . ' (overrides auto rules)',
					),
					'ignored' => $ignored,
				);
				continue;
			}

			// 3) Automatic rules. Age applies unless the admin turned it off
			// ($years defaults to 2; 0 = off, and the customer can change it).
			$linked    = isset( $linkage[ $id ] ) ? $linkage[ $id ] : null;
			$created   = (int) get_post_time( 'U', true, $post );
			$old       = $years > 0 && $created > 0 && $created < time() - $years * 31536000;
			$arch_path = (bool) preg_match( self::ARCHIVE_PATH, $uri );
			// WordPress-accurate linkage phrasing for the two "not public" cases.
			$link_phrase = ( false === $linked ) ? 'Attached only to unpublished content' : 'Not referenced by any published content';

			if ( ( $arch_path || $old ) && true !== $linked ) {
				$rules = array();
				if ( $arch_path ) {
					$rules[] = 'Auto rule: path looks like an archive (' . self::location_label( self::dir_prefix( $uri ) ) . ')';
				}
				if ( $old ) {
					$rules[] = 'Auto rule: uploaded over ' . $years . ' year' . ( $years > 1 ? 's' : '' ) . ' ago (threshold is configurable in Classification)';
				}
				$rules[]    = $link_phrase;
				$out[ $id ] = array( 'cat' => 'archive', 'prio' => self::CATS['archive']['prio'], 'src' => 'auto', 'rules' => $rules, 'ignored' => $ignored );
			} elseif ( true === $linked ) {
				$out[ $id ] = array(
					'cat'     => 'public',
					'prio'    => self::CATS['public']['prio'],
					'src'     => 'auto',
					'rules'   => array( 'Referenced by published content on this site' ),
					'ignored' => $ignored,
				);
			} else {
				$out[ $id ] = array(
					'cat'     => 'internal',
					'prio'    => self::CATS['internal']['prio'],
					'src'     => 'auto',
					'rules'   => array( $link_phrase ),
					'ignored' => $ignored,
				);
			}
		}
		return $out;
	}

	/**
	 * Location summary for the Classification screen. Groups the files by
	 * top-level directory; per location: doc count, current tag (or null),
	 * and a name-based suggestion for untagged ones. Sorted untagged-first,
	 * biggest-first — the highest-impact decision on top.
	 *
	 * @param WP_Post[] $posts
	 * @param array     $cls classify_all() output.
	 * @return array
	 */
	public static function locations( $posts, $cls ) {
		$tags = self::location_tags();
		$locs = array();
		foreach ( $posts as $post ) {
			$id  = (int) $post->ID;
			$uri = self::uri( $id );
			$top = self::top_location( $uri );
			if ( ! isset( $locs[ $top ] ) ) {
				$locs[ $top ] = array( 'count' => 0 );
			}
			$locs[ $top ]['count']++;
			if ( ! empty( $cls[ $id ]['ignored'] ) ) {
				$locs[ $top ]['ignored'] = ( isset( $locs[ $top ]['ignored'] ) ? $locs[ $top ]['ignored'] : 0 ) + 1;
			}
			if ( preg_match( self::SERVICE_HINT, self::filename( $uri ) ) ) {
				$locs[ $top ]['service_hits'] = ( isset( $locs[ $top ]['service_hits'] ) ? $locs[ $top ]['service_hits'] : 0 ) + 1;
			}
			if ( isset( $cls[ $id ] ) && 'archive' === $cls[ $id ]['cat'] && 'auto' === $cls[ $id ]['src'] ) {
				$locs[ $top ]['archive_hits'] = ( isset( $locs[ $top ]['archive_hits'] ) ? $locs[ $top ]['archive_hits'] : 0 ) + 1;
			}
			// Deeper admin-tagged prefixes under this top location (for display).
			foreach ( $tags as $prefix => $tag ) {
				if ( $prefix !== $top && 0 === strpos( (string) $prefix, (string) $top ) ) {
					$locs[ $top ]['children'][ $prefix ] = $tag;
				}
			}
		}
		foreach ( $locs as $prefix => &$loc ) {
			$loc['prefix']     = $prefix;
			$loc['label']      = self::location_label( $prefix );
			$loc['tag']        = isset( $tags[ $prefix ] ) ? $tags[ $prefix ] : null;
			$loc['suggestion'] = null;
			if ( ! $loc['tag'] ) {
				if ( ! empty( $loc['service_hits'] ) ) {
					$loc['suggestion'] = array(
						'cat' => 'service',
						'why' => $loc['service_hits'] . ' filename(s) look like forms or applications',
					);
				} elseif ( isset( $loc['archive_hits'] ) && (int) $loc['archive_hits'] === (int) $loc['count'] && $loc['count'] > 0 ) {
					$loc['suggestion'] = array( 'cat' => 'archive', 'why' => 'every file here already matches the archive rules' );
				}
			}
		}
		unset( $loc );
		// Untagged first, biggest first.
		uasort(
			$locs,
			function ( $a, $b ) {
				$ua = $a['tag'] ? 1 : 0;
				$ub = $b['tag'] ? 1 : 0;
				return $ua === $ub ? ( $b['count'] - $a['count'] ) : ( $ua - $ub );
			}
		);
		return $locs;
	}
}
