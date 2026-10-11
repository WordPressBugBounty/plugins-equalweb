<?php
/**
 * Replaces, backs up and restores PDF files in the media library.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EQW_File_Manager {

	const META_BACKUP     = '_eqw_backup_path';
	const META_REMEDIATED = '_eqw_remediated';
	const META_LOCK       = '_eqw_replace_lock'; // Legacy (pre-2.1.2) lock meta; no longer written.

	/**
	 * Acquire a named MySQL lock (server-side atomic, connection-scoped).
	 *
	 * Unlike a postmeta "lock" (SELECT-then-INSERT — not atomic, and orphaned
	 * forever if PHP dies before the delete), GET_LOCK is atomic in the DB
	 * server and auto-released when the connection closes, so a crashed
	 * request can never wedge the file.
	 *
	 * @param string $name
	 * @return bool
	 */
	public static function db_lock( $name ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', 'eqw_' . md5( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- GET_LOCK is a server-side atomic lock, not a cacheable table read.
	}

	/**
	 * Release a named MySQL lock.
	 *
	 * @param string $name
	 */
	public static function db_unlock( $name ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'eqw_' . md5( $name ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- RELEASE_LOCK is a server-side lock release, not a cacheable table read.
	}

	/**
	 * Overwrite the attachment's file with a remediated file, keeping the
	 * same path/URL. The original is kept next to it as a backup.
	 *
	 * Guarded by a per-attachment atomic lock: two concurrent requests (e.g.
	 * an auto-replace poll from one tab and a manual apply-replace from
	 * another) must never interleave the backup/rename steps, or the backup
	 * could be overwritten with the remediated file and the original lost.
	 *
	 * @param int    $attachment_id
	 * @param string $new_file Path to the downloaded remediated PDF (consumed).
	 * @return true|WP_Error
	 */
	public static function replace_in_place( $attachment_id, $new_file ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Server-side atomic lock; auto-released on connection close, so a
		// crashed request can never leave the file permanently locked.
		if ( ! self::db_lock( 'replace_' . $attachment_id ) ) {
			return new WP_Error( 'eqw_locked', __( 'This file is already being processed. Please wait a moment and try again.', 'equalweb' ) );
		}

		try {
			$target = get_attached_file( $attachment_id );
			if ( ! $target || ! file_exists( $target ) ) {
				return new WP_Error( 'eqw_no_file', __( 'Original file not found on disk.', 'equalweb' ) );
			}
			if ( ! file_exists( $new_file ) || filesize( $new_file ) < 100 ) {
				return new WP_Error( 'eqw_bad_download', __( 'The remediated file is missing or empty.', 'equalweb' ) );
			}

			$backup = $target . '.eqw-backup';
			if ( ! file_exists( $backup ) && ! copy( $target, $backup ) ) {
				return new WP_Error( 'eqw_backup_failed', __( 'Could not create a backup of the original file.', 'equalweb' ) );
			}

			if ( ! $wp_filesystem || ! $wp_filesystem->move( $new_file, $target, true ) ) {
				if ( ! copy( $new_file, $target ) ) {
					return new WP_Error( 'eqw_replace_failed', __( 'Could not overwrite the original file.', 'equalweb' ) );
				}
				wp_delete_file( $new_file );
			}

			// Flag only — the path is derived from get_attached_file() when needed
			// (stored paths lose backslashes to wp_unslash on Windows).
			update_post_meta( $attachment_id, self::META_BACKUP, 1 );
			update_post_meta( $attachment_id, self::META_REMEDIATED, time() );
			clean_attachment_cache( $attachment_id );
			return true;
		} finally {
			self::db_unlock( 'replace_' . $attachment_id );
			delete_post_meta( $attachment_id, self::META_LOCK ); // Clear any legacy lock row.
		}
	}

	/**
	 * Add the remediated file as a new attachment next to the original.
	 *
	 * @return int|WP_Error New attachment ID.
	 */
	public static function add_as_new( $attachment_id, $new_file ) {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}

		clearstatcache( true, $new_file );
		if ( ! file_exists( $new_file ) || filesize( $new_file ) < 100 ) {
			return new WP_Error( 'eqw_bad_download', __( 'The remediated file is missing or empty.', 'equalweb' ) );
		}
		$original = get_attached_file( $attachment_id );
		$dir      = wp_upload_dir();

		// The remediated result is always a PDF. PDF sources keep the historical
		// "-accessible.pdf" naming EXACTLY; office sources keep the same base
		// filename with a .pdf extension (report.docx -> report.pdf).
		$base     = pathinfo( $original ? $original : 'document.pdf', PATHINFO_FILENAME );
		$name     = 'application/pdf' === get_post_mime_type( $attachment_id ) ? $base . '-accessible.pdf' : $base . '.pdf';
		$name     = wp_unique_filename( $dir['path'], $name );
		$dest     = trailingslashit( $dir['path'] ) . $name;

		if ( ! $wp_filesystem || ! $wp_filesystem->move( $new_file, $dest, true ) ) {
			if ( ! copy( $new_file, $dest ) ) {
				return new WP_Error( 'eqw_move_failed', __( 'Could not move the remediated file into the uploads folder.', 'equalweb' ) );
			}
			// move failed but copy succeeded (e.g. cross-device tmp dir) — the
			// source temp file is left behind by copy(); remove it.
			wp_delete_file( $new_file );
		}

		$new_id = wp_insert_attachment( array(
			'post_mime_type' => 'application/pdf',
			'post_title'     => get_the_title( $attachment_id ) . ' ' . __( '(accessible)', 'equalweb' ),
			'post_status'    => 'inherit',
			// Keep the ORIGINAL uploader as owner (not the applier) so the
			// accessible copy stays visible under own-files scope.
			'post_author'    => (int) get_post_field( 'post_author', $attachment_id ),
		), $dest );
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		wp_update_attachment_metadata( $new_id, wp_generate_attachment_metadata( $new_id, $dest ) );
		update_post_meta( $new_id, self::META_REMEDIATED, time() );
		update_post_meta( $new_id, '_eqw_source_attachment', $attachment_id );
		// The SOURCE row is the one that advances to state 'remediated' (its
		// accessible version was applied), so it carries the applied-timestamp —
		// exactly like replace_in_place() stamps it. The yearly report counts a
		// remediation by state + this timestamp, so without it every save-as-new
		// would be missing from the report.
		update_post_meta( $attachment_id, self::META_REMEDIATED, time() );
		return $new_id;
	}

	/**
	 * LEGACY SWEEP. Unlink every "{target}.{token}.eqw-remediated[.tmp]"
	 * sidecar left behind by a version that cached the remediated PDF next to
	 * the original. Nothing writes those files any more (EqualWeb retains the
	 * audited output for autoRemove days, so it is fetched on demand), but an
	 * upgraded install can still hold them and they are unreachable without the
	 * meta that named their random token — so uninstall and attachment deletion
	 * still clear them instead of orphaning paid PDFs in the uploads directory.
	 *
	 * Uses a directory scan instead of glob() so filenames containing glob
	 * metacharacters ([ ] * ?) cannot silently skip the sweep.
	 *
	 * @param string $target Path of the original file.
	 */
	public static function sweep_sidecars( $target ) {
		$dir    = dirname( $target );
		$prefix = basename( $target ) . '.';
		foreach ( (array) @scandir( $dir ) as $entry ) {
			if ( is_string( $entry ) && 0 === strpos( $entry, $prefix ) && false !== strpos( $entry, '.eqw-remediated' ) ) {
				wp_delete_file( $dir . '/' . $entry );
			}
		}
	}

	/**
	 * delete_attachment hook: remove our sidecar files (the backup, plus any
	 * legacy remediated-cache sidecar) so deleting the attachment does not
	 * orphan them on disk.
	 *
	 * @param int $attachment_id
	 */
	public static function on_delete_attachment( $attachment_id ) {
		// Every managed document type can carry a legacy remediated-cache
		// sidecar (office rows too — e.g. a remediation left in remediated_ready
		// by an older version).
		if ( ! array_key_exists( (string) get_post_mime_type( $attachment_id ), EQW_Admin_Page::DOC_MIMES ) ) {
			return;
		}
		$target = get_attached_file( $attachment_id );
		if ( ! $target ) {
			return;
		}
		self::sweep_sidecars( $target );
		if ( file_exists( $target . '.eqw-backup' ) ) {
			wp_delete_file( $target . '.eqw-backup' );
		}
	}

	/**
	 * Restore the original file from its backup.
	 *
	 * @return true|WP_Error
	 */
	public static function restore_backup( $attachment_id ) {
		$target = get_attached_file( $attachment_id );
		$backup = $target ? $target . '.eqw-backup' : '';
		if ( ! get_post_meta( $attachment_id, self::META_BACKUP, true ) || ! $backup || ! file_exists( $backup ) ) {
			return new WP_Error( 'eqw_no_backup', __( 'No backup found for this file.', 'equalweb' ) );
		}
		if ( ! copy( $backup, $target ) ) {
			return new WP_Error( 'eqw_restore_failed', __( 'Could not restore the backup.', 'equalweb' ) );
		}
		wp_delete_file( $backup );
		delete_post_meta( $attachment_id, self::META_BACKUP );
		delete_post_meta( $attachment_id, self::META_REMEDIATED );
		clean_attachment_cache( $attachment_id );
		return true;
	}
}
