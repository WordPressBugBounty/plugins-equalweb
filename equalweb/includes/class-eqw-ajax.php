<?php
/**
 * AJAX endpoints for the admin UI. All EqualWeb traffic happens here,
 * server-side; API keys are never exposed to the browser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EQW_Ajax {

	const NONCE = 'eqw_pdf_nonce';

	// Attachment meta keys.
	const META_DOC_ID      = '_eqw_doc_id';      // Check document/report ID.
	const META_DOC_BACKEND = '_eqw_doc_backend'; // 'key' | 'proxy' — the account the check doc lives on.
	const META_REM_DOC_ID  = '_eqw_rem_doc_id';  // Remediation document/report ID.
	const META_STATE       = '_eqw_state';       // checking | checked | remediating | remediated_ready
	const META_SUMMARY     = '_eqw_summary';     // score + issue counts.
	const META_REPORT      = '_eqw_report';      // Full checker report JSON.
	const META_POLL_LOCK   = '_eqw_poll_lock';   // Single-writer lock for finalizing a remediation.

	// Spend/activity attribution: who initiated the last check/remediation
	// (recorded at start time, read by the Analytics "Activity by user" card).
	const META_CHECKED_BY         = '_eqw_checked_by';
	const META_CHECKED_BY_NAME    = '_eqw_checked_by_name';
	const META_REMEDIATED_BY      = '_eqw_remediated_by';
	const META_REMEDIATED_BY_NAME = '_eqw_remediated_by_name';

	/**
	 * States in which a file is already claimed: mid-process, waiting for the
	 * user's apply decision, or already remediated. Starting a check or a
	 * remediation in any of them would upload the wrong file, clobber the
	 * "before" score, or re-spend a credit.
	 */
	const BUSY_STATES = array( 'checking', 'remediating', 'remediated_ready', 'remediated' );

	// Per-user throttle on the PAID endpoint: a remediate-capable user (or a
	// hijacked session) could otherwise script the endpoint across every file
	// id and drain the whole credit pool in one burst. 60/minute is far above
	// real UI use (a bulk action covers one page = at most 10 rows) and still
	// stops a scripted drain. Check/poll/report are free and stay unthrottled.
	const REMEDIATE_MAX_PER_WINDOW = 60;
	const REMEDIATE_WINDOW         = 60; // Seconds.

	public static function init() {
		$actions = array(
			'check', 'poll', 'report', 'remediate', 'apply', 'restore', 'download_file', 'yearly_report', 'save_settings', 'register', 'balance', 'save_permissions',
			// Classification (triage) — all admin-only.
			'cls_tag_location', 'cls_clear_location', 'cls_bulk_tag', 'cls_bulk_untag', 'cls_ignore', 'cls_unignore', 'cls_set_archive_years',
		);
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_eqw_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	/** Common guard: capability + nonce + attachment resolution + scope. */
	private static function guard( $cap, $need_attachment = true ) {
		if ( ! current_user_can( $cap ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'equalweb' ) ), 403 );
		}
		check_ajax_referer( self::NONCE );
		if ( ! $need_attachment ) {
			return 0;
		}
		$id = isset( $_REQUEST['attachment_id'] ) ? (int) $_REQUEST['attachment_id'] : 0;
		// A scope failure is byte-identical to an invalid id — an out-of-scope
		// user must not be able to probe which attachment ids exist. Invalid,
		// unsupported-type and nonexistent attachments all share this ONE
		// identical envelope.
		if ( ! $id || 'attachment' !== get_post_type( $id )
			|| ! array_key_exists( (string) get_post_mime_type( $id ), EQW_Admin_Page::DOC_MIMES )
			|| ! EQW_Capabilities::current_user_can_scope( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid document.', 'equalweb' ) ), 400 );
		}
		return $id;
	}

	private static function fail_if_error( $thing ) {
		if ( is_wp_error( $thing ) ) {
			wp_send_json_error( array( 'message' => $thing->get_error_message() ) );
		}
		return $thing;
	}

	/**
	 * Count one remediation start against the current user's rate window.
	 *
	 * A fixed window (start timestamp + counter in one transient): a plain
	 * counter would be wrong, because set_transient() resets the TTL on every
	 * write and would never let the window expire for a steady caller.
	 *
	 * @return bool False when the user is over the limit.
	 */
	private static function take_remediate_slot() {
		$key = 'eqw_pdf_rem_rate_' . get_current_user_id();
		$win = get_transient( $key );
		$now = time();
		if ( ! is_array( $win ) || ! isset( $win['start'], $win['n'] ) || $now - (int) $win['start'] >= self::REMEDIATE_WINDOW ) {
			$win = array( 'start' => $now, 'n' => 0 );
		}
		if ( (int) $win['n'] >= self::REMEDIATE_MAX_PER_WINDOW ) {
			return false;
		}
		$win['n'] = (int) $win['n'] + 1;
		// TTL > window: the stored start timestamp, not the TTL, closes the window.
		set_transient( $key, $win, 2 * self::REMEDIATE_WINDOW );
		return true;
	}

	/**
	 * Store the row's current score. A score of 0 is a legitimate value (an
	 * untagged PDF scores exactly 0), so only a NULL score — meaning "unknown",
	 * not "zero" — clears the meta instead of writing a misleading 0.
	 *
	 * @param int      $id    Attachment ID.
	 * @param int|null $score
	 */
	private static function store_score( $id, $score ) {
		if ( null === $score ) {
			delete_post_meta( $id, '_eqw_score' );
			return;
		}
		update_post_meta( $id, '_eqw_score', (int) $score );
	}

	/**
	 * Reset a row whose remediation can no longer be completed (the accessible
	 * copy is gone from EqualWeb — the document aged out of its autoRemove
	 * window). Leaving it at 'remediated_ready' would wedge it — check/remediate
	 * reject that state and no backup exists to restore — so drop the run
	 * entirely and let the user remediate again. The caller decides WHEN (it
	 * owns the state check).
	 *
	 * @param int $id Attachment ID.
	 */
	private static function reset_remediation( $id ) {
		update_post_meta( $id, self::META_STATE, 'checked' );
		delete_post_meta( $id, self::META_REM_DOC_ID );
		delete_post_meta( $id, '_eqw_summary_after' );
		delete_post_meta( $id, '_eqw_report_after' );
	}

	/**
	 * Drop a running check that can no longer be resumed (its document vanished
	 * from EqualWeb, or the account it was started on is unreachable). The row
	 * goes back to having no run at all: 'checking' is a busy state, so a row
	 * left in it can never be re-checked and would be wedged for good.
	 *
	 * @param int $id Attachment ID.
	 */
	private static function reset_check( $id ) {
		delete_post_meta( $id, self::META_STATE );
		delete_post_meta( $id, self::META_DOC_ID );
		delete_post_meta( $id, self::META_DOC_BACKEND );
	}

	/** Start a free accessibility check. */
	public static function handle_check() {
		$id = self::guard( EQW_Capabilities::CAP_CHECK );

		// The free checker is PDF-only — office documents go straight to
		// remediation (which converts them to an accessible PDF).
		if ( 'application/pdf' !== get_post_mime_type( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Checking is available for PDF files only.', 'equalweb' ) ) );
		}

		// Re-checking a file that is mid-process, waiting to be applied, or
		// already remediated would upload the wrong file and clobber the
		// "before" score. Only a fresh or previously-checked file may be
		// (re)checked.
		$state = get_post_meta( $id, self::META_STATE, true );
		if ( in_array( $state, self::BUSY_STATES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This file cannot be checked in its current state. Restore the original first if you want to re-check it.', 'equalweb' ) ) );
		}

		// The run is BOUND to the backend it starts on: the doc id only exists
		// in that one EqualWeb account, so the poll must query the same one even
		// if the site's API key is added/removed while the check is in flight.
		$backend = EQW_API_Client::check_backend();
		$client  = self::fail_if_error( EQW_API_Client::for_check( $backend ) );

		// Uploading is slow and unbilled, so it stays OUTSIDE the lock.
		$doc_id = self::fail_if_error( $client->upload_file( get_attached_file( $id ), basename( get_attached_file( $id ) ) ) );

		// Starting the process and claiming the row must be ONE atomic section,
		// or two concurrent requests both read a startable state during the
		// upload window and the second one clobbers the first one's doc id.
		// Same lock name as the poll finaliser, so a start and a finalise on the
		// same file can never interleave.
		if ( ! EQW_File_Manager::db_lock( 'poll_' . $id ) ) {
			wp_send_json_error( array( 'message' => __( 'This file is busy right now. Try again in a moment.', 'equalweb' ) ) );
		}
		// wp_send_json_*() calls exit, and PHP does NOT run finally on exit — so
		// nothing is sent from inside the try. The response is built here and
		// sent after the unlock (the poll finaliser does exactly the same).
		$error   = '';
		$started = null;
		try {
			$state = get_post_meta( $id, self::META_STATE, true );
			if ( in_array( $state, self::BUSY_STATES, true ) ) {
				$error = __( 'The file state changed while uploading. Reload the page and try again.', 'equalweb' );
			} else {
				$started = $client->start_process( $doc_id, 'check' );
				if ( ! is_wp_error( $started ) ) {
					update_post_meta( $id, self::META_DOC_ID, $doc_id );
					update_post_meta( $id, self::META_DOC_BACKEND, $backend );
					update_post_meta( $id, self::META_STATE, 'checking' );
					// Spend/activity attribution: who initiated this check.
					update_post_meta( $id, self::META_CHECKED_BY, get_current_user_id() );
					update_post_meta( $id, self::META_CHECKED_BY_NAME, wp_get_current_user()->display_name );
				}
			}
		} finally {
			EQW_File_Manager::db_unlock( 'poll_' . $id );
		}

		if ( '' !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}
		self::fail_if_error( $started );
		wp_send_json_success( array( 'state' => 'checking' ) );
	}

	/** Poll processing status for a check or a remediation. */
	public static function handle_poll() {
		// Poll rides EITHER capability (any-of): a role granted remediate
		// without check must be able to poll its own paid remediation to
		// completion. Polling only observes or finalizes a run someone else
		// authorized. The auto-replace below runs purely per the admin's
		// site-wide setting — never per the polling user's caps — so the
		// outcome never depends on whose tab finalizes.
		$cap  = current_user_can( EQW_Capabilities::CAP_CHECK ) ? EQW_Capabilities::CAP_CHECK : EQW_Capabilities::CAP_REMEDIATE;
		$id   = self::guard( $cap );
		$mode = ( isset( $_REQUEST['mode'] ) && 'remediate' === $_REQUEST['mode'] ) ? 'remediate' : 'check'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() (check_ajax_referer).

		if ( 'remediate' === $mode ) {
			// Remediation always runs on the personal key, so there is no
			// backend to bind — for_remediation() can only ever pick that one.
			$client = self::fail_if_error( EQW_API_Client::for_remediation() );
			$doc_id = get_post_meta( $id, self::META_REM_DOC_ID, true );
		} else {
			// Query the SAME account the check was started on. Picking the
			// backend fresh here would send the poll to the other EqualWeb
			// account whenever the API key was added/removed mid-flight — the
			// doc id does not exist there, so the doc-not-found path below would
			// reset the row and discard a perfectly healthy running check.
			// Rows started before this version have no recorded backend: null
			// keeps the historical key-first auto behaviour for them.
			$backend = get_post_meta( $id, self::META_DOC_BACKEND, true );
			$client  = EQW_API_Client::for_check( $backend ? $backend : null );
			if ( is_wp_error( $client ) ) {
				// The account this run started on is unreachable (its key was
				// removed, or the registration lapsed). The doc id only exists in
				// THAT account, so the run cannot be resumed. Reset instead of
				// failing forever: 'checking' is a busy state, so a row left in it
				// can never be re-checked and would be wedged with no way out. The
				// check is free, so re-running it costs the user nothing.
				// A run on the shared check service stops because this site is no
				// longer registered there: say that, in the words for the user's
				// role (this is the message that reaches the row).
				if ( 'checking' === get_post_meta( $id, self::META_STATE, true ) ) {
					self::reset_check( $id );
					wp_send_json_error( array( 'message' => 'eqw_not_registered' === $client->get_error_code()
						? $client->get_error_message()
						: __( 'The EqualWeb account this check started on is no longer available. The file was reset. Run the check again.', 'equalweb' ) ) );
				}
				self::fail_if_error( $client );
			}
			$doc_id = get_post_meta( $id, self::META_DOC_ID, true );
		}
		if ( ! $doc_id ) {
			wp_send_json_error( array( 'message' => __( 'No process in progress for this file.', 'equalweb' ) ) );
		}

		$status = $client->get_status( $doc_id );
		if ( is_wp_error( $status ) ) {
			// The document can vanish from EqualWeb (retention / manual delete).
			// Endlessly polling a gone doc would wedge the row — reset it so
			// the user can simply run the check/remediation again.
			if ( 'eqw_doc_not_found' === $status->get_error_code() ) {
				$now = get_post_meta( $id, self::META_STATE, true );
				if ( 'remediate' === $mode && 'remediating' === $now ) {
					update_post_meta( $id, self::META_STATE, 'checked' );
					delete_post_meta( $id, self::META_REM_DOC_ID );
					wp_send_json_error( array( 'message' => __( 'The document is no longer on the EqualWeb service. The file was reset. Run the remediation again.', 'equalweb' ) ) );
				}
				if ( 'check' === $mode && 'checking' === $now ) {
					self::reset_check( $id );
					wp_send_json_error( array( 'message' => __( 'The document is no longer on the EqualWeb service. The file was reset. Run the check again.', 'equalweb' ) ) );
				}
			}
			self::fail_if_error( $status );
		}
		// EqualWeb's page count is authoritative, but it is only WRITTEN inside
		// the single-writer commit sections below: a stale poll for a superseded
		// doc must never overwrite the row's page count (pages feed the analytics
		// totals and the credit estimate).
		$pages      = ! empty( $status['numberOfPages'] ) ? (int) $status['numberOfPages'] : 0;
		$raw_status = isset( $status['status'] ) ? $status['status'] : '';
		if ( 'done' !== $raw_status ) {
			wp_send_json_success( array( 'state' => 'processing', 'raw_status' => $raw_status ) );
		}

		if ( 'check' === $mode ) {
			$report = self::fail_if_error( $client->get_report( $doc_id, 'original' ) );
			$summary = array(
				'score'      => isset( $report['checkerScore'] ) ? (int) $report['checkerScore'] : null,
				'issues'     => isset( $report['totalIssues'] ) ? $report['totalIssues'] : array(),
				'checked_at' => time(),
			);

			// Commit under the same single-writer lock as the remediate path:
			// a stale check poll must never clobber a row that has meanwhile
			// moved on (e.g. to 'remediating' — that would strand a paid run).
			if ( ! EQW_File_Manager::db_lock( 'poll_' . $id ) ) {
				wp_send_json_success( array( 'state' => 'processing', 'raw_status' => 'finalizing' ) );
			}
			$response = null;
			try {
				$state_now = get_post_meta( $id, self::META_STATE, true );
				$doc_now   = (string) get_post_meta( $id, self::META_DOC_ID, true );
				if ( $doc_now !== (string) $doc_id || 'checking' !== $state_now ) {
					// Stale run — discard, report the row as it is now.
					$response = array( 'state' => $state_now ? $state_now : 'none' );
				} else {
					if ( $pages > 0 ) {
						update_post_meta( $id, '_eqw_pages', $pages );
					}
					update_post_meta( $id, self::META_SUMMARY, $summary );
					self::store_score( $id, $summary['score'] );
					update_post_meta( $id, self::META_REPORT, wp_slash( wp_json_encode( $report ) ) );
					update_post_meta( $id, self::META_STATE, 'checked' );
					$response = array(
						'state'      => 'checked',
						'summary'    => $summary,
						'report_url' => EQW_API_Client::report_url( $doc_id ),
					);
				}
			} finally {
				EQW_File_Manager::db_unlock( 'poll_' . $id );
			}
			wp_send_json_success( $response );
		}

		// Remediation finished. Fetch the combined report (reads only — every
		// write happens inside the single-writer commit section below, so a
		// stale poll can never pollute a row that restore/apply just changed).
		$report = $client->get_report( $doc_id, 'both' );
		$after  = null;
		if ( ! is_wp_error( $report ) && ! empty( $report['audited'] ) ) {
			$after = array(
				'score'      => isset( $report['audited']['remediatedScore'] ) ? (int) $report['audited']['remediatedScore'] : null,
				'issues'     => isset( $report['audited']['totalIssues'] ) ? $report['audited']['totalIssues'] : array(),
				'checked_at' => time(),
			);
		}

		// EqualWeb RETAINS the audited output (deleteAfterAudit is never sent as
		// true; retention is the account's autoRemove window), so a finished run
		// no longer has to secure a local copy: download / replace / save-as-new
		// fetch the file from EqualWeb when they actually need it.
		//
		// The two AUTO branches are the exception — they write the file as part
		// of this very poll, so they need the bytes NOW. The download is slow and
		// unbilled, so it stays outside the commit lock.
		$is_pdf    = 'application/pdf' === get_post_mime_type( $id );
		$defaults  = EQW_API_Client::audit_defaults();
		$auto_repl = ! empty( $defaults['auto_replace'] ) && $is_pdf;
		$auto_save = ! $is_pdf;
		$tmp       = '';
		$dl        = true;
		if ( $auto_repl || $auto_save ) {
			$tmp = wp_tempnam( 'eqw-remediated.pdf' );
			$dl  = $client->download_to_file( $doc_id, $tmp );
		}

		// Commit section — exactly ONE request may finalize a remediation.
		// GET_LOCK is atomic server-side and auto-released if the connection
		// dies, so a crashed commit can never wedge polling. Responses are
		// still built first and sent after unlock for a clean lock lifetime.
		if ( ! EQW_File_Manager::db_lock( 'poll_' . $id ) ) {
			if ( '' !== $tmp ) {
				wp_delete_file( $tmp );
			}
			wp_send_json_success( array( 'state' => 'processing', 'raw_status' => 'finalizing' ) );
		}

		$response = null;
		try {
			$state = get_post_meta( $id, self::META_STATE, true );
			$live  = (string) get_post_meta( $id, self::META_REM_DOC_ID, true );

			if ( $live !== (string) $doc_id || 'remediating' !== $state ) {
				// Another request already finalized this run, or a restore /
				// new run replaced it. Discard OUR work — never write anything
				// for a stale run — and report the row as it is now.
				if ( '' !== $tmp ) {
					wp_delete_file( $tmp );
				}
				$response = array(
					'state'            => $state ? $state : 'none',
					'summary'          => get_post_meta( $id, self::META_SUMMARY, true ),
					'summary_after'    => get_post_meta( $id, '_eqw_summary_after', true ),
					'report_url_after' => $live ? EQW_API_Client::report_url( $live ) : '',
				);
			} else {
				// This poll owns the finalization: only now is meta written.
				if ( $pages > 0 ) {
					update_post_meta( $id, '_eqw_pages', $pages );
				}
				if ( $after ) {
					update_post_meta( $id, '_eqw_summary_after', $after );
					update_post_meta( $id, '_eqw_report_after', wp_slash( wp_json_encode( $report['audited'] ) ) );
				}
				// isset(), NOT empty(): a fully untagged PDF scores exactly 0, and
				// empty(0) is true — that dropped the "before" score of the
				// product's own headline 0->99 case, leaving the Before column
				// blank forever and the file out of every average.
				if ( ! is_wp_error( $report ) && isset( $report['original']['checkerScore'] ) && ! get_post_meta( $id, self::META_SUMMARY, true ) ) {
					// Remediated without a prior check — backfill the "before" score.
					$before = array(
						'score'      => (int) $report['original']['checkerScore'],
						'issues'     => isset( $report['original']['totalIssues'] ) ? $report['original']['totalIssues'] : array(),
						'checked_at' => time(),
					);
					update_post_meta( $id, self::META_SUMMARY, $before );
					self::store_score( $id, $before['score'] );
					update_post_meta( $id, self::META_REPORT, wp_slash( wp_json_encode( $report['original'] ) ) );
				}

				// Auto-replace: when enabled, apply immediately instead of asking.
				// PDF sources only — the remediated result is always a PDF, so it
				// must never overwrite an office original; office rows auto-SAVE
				// below instead.
				$meta = null;
				if ( $auto_repl && ! is_wp_error( $dl ) ) {
					$meta = self::replace_and_refresh( $id, $tmp );
				}

				// Office auto-save: an office remediation always produces a NEW
				// accessible PDF — there is no replace decision to ask for, so
				// skip the choice step and save it into the media library right
				// away (the exact add_as_new path apply-new uses: {basename}.pdf,
				// owned by the original attachment's author). This sits inside
				// the same single-writer commit as auto-replace — the state CAS
				// above already proved this poll owns the finalization, so
				// nothing else can apply the same result twice. On ANY failure,
				// fall through to remediated_ready: EqualWeb still holds the
				// audited file, so the manual choice box works from there.
				$new_id = null;
				if ( $auto_save && ! is_wp_error( $dl ) ) {
					$saved = EQW_File_Manager::add_as_new( $id, $tmp );
					if ( ! is_wp_error( $saved ) ) {
						$new_id = (int) $saved;
					}
				}
				if ( '' !== $tmp ) {
					wp_delete_file( $tmp );
				}

				if ( null !== $meta && ! is_wp_error( $meta ) ) {
					$response = array_merge( array(
						'state'            => 'remediated',
						'message'          => __( 'The original file was automatically replaced with the accessible version. A backup was kept.', 'equalweb' ),
						'summary'          => get_post_meta( $id, self::META_SUMMARY, true ),
						'summary_after'    => $after,
						'report_url_after' => EQW_API_Client::report_url( $doc_id ),
					), $meta );
				} elseif ( null !== $new_id ) {
					// Mirror apply-new exactly: the accessible copy is now its own
					// attachment, so advance the row. No backup exists — the
					// original office file was never touched.
					update_post_meta( $id, self::META_STATE, 'remediated' );
					update_post_meta( $id, '_eqw_new_attachment', $new_id );
					$response = array(
						'state'            => 'remediated',
						'message'          => __( 'The accessible PDF was saved to the media library.', 'equalweb' ),
						'new_url'          => wp_get_attachment_url( $new_id ),
						'summary'          => get_post_meta( $id, self::META_SUMMARY, true ),
						'summary_after'    => $after,
						'report_url_after' => EQW_API_Client::report_url( $doc_id ),
					);
				} else {
					update_post_meta( $id, self::META_STATE, 'remediated_ready' );
					$response = array(
						'state'            => 'remediated_ready',
						'summary'          => get_post_meta( $id, self::META_SUMMARY, true ),
						'summary_after'    => $after,
						'report_url_after' => EQW_API_Client::report_url( $doc_id ),
					);
				}
			}
		} finally {
			EQW_File_Manager::db_unlock( 'poll_' . $id );
		}

		wp_send_json_success( $response );
	}

	/** Return the stored full report(s) for the details modal. */
	public static function handle_report() {
		$id     = self::guard( EQW_Capabilities::CAP_VIEW );
		$report = get_post_meta( $id, self::META_REPORT, true );
		$after  = get_post_meta( $id, '_eqw_report_after', true );
		if ( ! $report && ! $after ) {
			wp_send_json_error( array( 'message' => __( 'No report stored for this file. Run a check first.', 'equalweb' ) ) );
		}
		wp_send_json_success( array(
			'report'       => $report ? json_decode( $report, true ) : null,
			'report_after' => $after ? json_decode( $after, true ) : null,
		) );
	}

	/** Send the file for paid remediation. */
	public static function handle_remediate() {
		$id = self::guard( EQW_Capabilities::CAP_REMEDIATE );

		// Never re-spend credits on a file that is already being processed,
		// waiting to be applied, or already remediated.
		$state = get_post_meta( $id, self::META_STATE, true );
		if ( in_array( $state, self::BUSY_STATES, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This file is already remediated or being processed. Restore the original first to remediate it again.', 'equalweb' ) ) );
		}

		if ( ! self::take_remediate_slot() ) {
			wp_send_json_error( array( 'message' => __( 'Too many remediations started in a short time. Wait a minute and try again.', 'equalweb' ) ), 429 );
		}

		$client = self::fail_if_error( EQW_API_Client::for_remediation() );

		// Fail CLOSED: when the balance cannot be read, refuse to spend rather
		// than skipping the gate and billing against an unknown account state.
		$balance = self::fail_if_error( $client->balance() );
		if ( $balance <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'No credits left in your EqualWeb account. Purchase credits and try again.', 'equalweb' ) ) );
		}

		$extra = EQW_API_Client::audit_extra();

		// Uploading is slow and UNBILLED, so it stays outside the lock below.
		// The part carries the file's REAL mime type: office documents
		// (Word/PowerPoint/Excel) must not be mislabeled as PDF or the
		// conversion on EqualWeb's side goes wrong.
		$doc_id = self::fail_if_error( $client->upload_file( get_attached_file( $id ), basename( get_attached_file( $id ) ), get_post_mime_type( $id ) ) );

		// start_process() is the call that SPENDS THE CREDIT, so it and the
		// state check that authorizes it must be one atomic section. Without the
		// lock, two concurrent requests (double-click, two tabs, two users) both
		// read state 'checked' during the seconds-long upload window and each
		// spends a credit on the same file. Same lock name as the poll finaliser,
		// so a start and a finalise can never interleave.
		if ( ! EQW_File_Manager::db_lock( 'poll_' . $id ) ) {
			wp_send_json_error( array( 'message' => __( 'This file is busy right now. Try again in a moment.', 'equalweb' ) ) );
		}
		// wp_send_json_*() calls exit, and PHP does NOT run finally on exit — so
		// nothing is sent from inside the try. The response is built here and
		// sent after the unlock (the poll finaliser does exactly the same).
		$error   = '';
		$started = null;
		try {
			$state = get_post_meta( $id, self::META_STATE, true );
			if ( in_array( $state, self::BUSY_STATES, true ) ) {
				$error = __( 'The file state changed while uploading. Reload the page and try again.', 'equalweb' );
			} else {
				$started = $client->start_process( $doc_id, 'tag', $extra );
				if ( ! is_wp_error( $started ) ) {
					update_post_meta( $id, self::META_REM_DOC_ID, $doc_id );
					update_post_meta( $id, self::META_STATE, 'remediating' );
					// Spend/activity attribution: who initiated this paid remediation.
					update_post_meta( $id, self::META_REMEDIATED_BY, get_current_user_id() );
					update_post_meta( $id, self::META_REMEDIATED_BY_NAME, wp_get_current_user()->display_name );
				}
			}
		} finally {
			EQW_File_Manager::db_unlock( 'poll_' . $id );
		}

		if ( '' !== $error ) {
			wp_send_json_error( array( 'message' => $error ) );
		}
		self::fail_if_error( $started );
		wp_send_json_success( array( 'state' => 'remediating', 'report_url_after' => EQW_API_Client::report_url( $doc_id ) ) );
	}

	/** Apply a finished remediation: replace | new | (download handled separately). */
	public static function handle_apply() {
		$id     = self::guard( EQW_Capabilities::CAP_REPLACE );
		$how    = isset( $_REQUEST['how'] ) ? sanitize_key( $_REQUEST['how'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() (check_ajax_referer).
		$doc_id = get_post_meta( $id, self::META_REM_DOC_ID, true );
		if ( ! $doc_id ) {
			wp_send_json_error( array( 'message' => __( 'No remediated file available for this attachment.', 'equalweb' ) ) );
		}

		// Only a finished-but-unapplied remediation may be applied. Rejecting
		// any other state stops two tabs from both applying (e.g. one replace
		// and one save-as-new) the same result.
		if ( 'remediated_ready' !== get_post_meta( $id, self::META_STATE, true ) ) {
			wp_send_json_error( array( 'message' => __( 'This remediation has already been applied or is no longer pending.', 'equalweb' ) ) );
		}
		// The remediated result is always a PDF: it must never overwrite an
		// office original in place. Office sources only save-as-new or download.
		if ( 'replace' === $how && 'application/pdf' !== get_post_mime_type( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'Replacing the original is available for PDF files only. Save the accessible copy as a new PDF instead.', 'equalweb' ) ) );
		}
		// Fetch the audited file from EqualWeb, which retains it for the
		// account's autoRemove window (deleteAfterAudit is never sent as true).
		$client = self::fail_if_error( EQW_API_Client::for_remediation() );
		$tmp    = wp_tempnam( 'eqw-remediated.pdf' );
		$dl     = $client->download_to_file( $doc_id, $tmp );
		if ( is_wp_error( $dl ) ) {
			wp_delete_file( $tmp );
			// EqualWeb no longer has the audited file (the document aged out of
			// its retention window). Leaving the row at remediated_ready would
			// wedge it (check/remediate reject that state and no backup exists to
			// restore) — reset it so the user actually CAN remediate again.
			// Re-read the state first: a concurrent apply may have just succeeded
			// and must keep its row.
			if ( 'remediated_ready' === get_post_meta( $id, self::META_STATE, true ) ) {
				self::reset_remediation( $id );
			}
			wp_send_json_error( array( 'message' => $dl->get_error_message() . ' ' . __( 'The remediated copy is no longer available. The file was reset so you can remediate it again.', 'equalweb' ) ) );
		}

		if ( 'replace' === $how ) {
			$meta = self::replace_and_refresh( $id, $tmp );
			if ( is_wp_error( $meta ) ) {
				wp_delete_file( $tmp );
				self::fail_if_error( $meta );
			}
			wp_send_json_success( array_merge( array(
				'state'   => 'remediated',
				'message' => __( 'The original file was replaced with the accessible version. A backup was kept.', 'equalweb' ),
			), $meta ) );
		}

		if ( 'new' === $how ) {
			$new_id = EQW_File_Manager::add_as_new( $id, $tmp );
			if ( is_wp_error( $new_id ) ) {
				wp_delete_file( $tmp );
				self::fail_if_error( $new_id );
			}
			update_post_meta( $id, self::META_STATE, 'remediated' );
			update_post_meta( $id, '_eqw_new_attachment', (int) $new_id );
			wp_send_json_success( array(
				'state'   => 'remediated',
				'message' => __( 'The accessible version was added as a new file in the media library.', 'equalweb' ),
				'new_url' => wp_get_attachment_url( $new_id ),
			) );
		}

		wp_delete_file( $tmp );
		wp_send_json_error( array( 'message' => __( 'Unknown action.', 'equalweb' ) ), 400 );
	}

	/**
	 * Overwrite the original file with a remediated one and refresh cached
	 * PDF metadata. Language is ALWAYS re-extracted (source PDFs often carry
	 * a wrong or missing /Lang); when the original had no title, the
	 * attachment title is updated from the remediated document.
	 *
	 * @param int    $id  Attachment ID.
	 * @param string $tmp Path to the downloaded remediated PDF (consumed).
	 * @return array|WP_Error array( 'title' => ..., 'lang' => ... )
	 */
	private static function replace_and_refresh( $id, $tmp ) {
		$old_title = EQW_Admin_Page::get_doc_title( $id );

		$ok = EQW_File_Manager::replace_in_place( $id, $tmp );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		update_post_meta( $id, self::META_STATE, 'remediated' );

		delete_post_meta( $id, '_eqw_doc_title' );
		delete_post_meta( $id, '_eqw_doc_lang' );
		delete_post_meta( $id, '_eqw_pages' );
		$new_title = EQW_Admin_Page::get_doc_title( $id );
		$new_lang  = EQW_Admin_Page::get_doc_lang( $id );
		EQW_Admin_Page::get_page_count( $id );

		if ( '' === $old_title && '' !== $new_title ) {
			wp_update_post( array( 'ID' => $id, 'post_title' => $new_title ) );
		}

		return array(
			'title' => '' !== $new_title ? $new_title : get_the_title( $id ),
			'lang'  => $new_lang,
		);
	}

	/**
	 * Content-Disposition value for an attachment download.
	 *
	 * The quoted filename is a LITERAL string — percent-encoding it (the exact
	 * mistake EQW_API_Client::upload_file() documents and avoids) makes a
	 * Hebrew-named PDF download as "%D7%90%D7%91.pdf". So: a sanitised ASCII
	 * literal for every client, plus an RFC 5987 filename* carrying the exact
	 * UTF-8 name whenever it is not pure ASCII.
	 *
	 * @param string $name Desired filename.
	 * @return string
	 */
	private static function content_disposition( $name ) {
		// CR/LF would split the header; the quote and backslash would end the
		// quoted-string early.
		$safe  = str_replace( array( "\r", "\n", '"', '\\' ), '', $name );
		$ascii = preg_replace( '/[^\x20-\x7E]/', '_', $safe );
		$out   = 'attachment; filename="' . $ascii . '"';
		if ( $ascii !== $safe ) {
			$out .= "; filename*=UTF-8''" . rawurlencode( $safe );
		}
		return $out;
	}

	/** Stream the remediated PDF to the user (download-only option). */
	public static function handle_download_file() {
		// Download is a read: gated at view level (plus scope) — it never
		// touches live content, so remediate/replace would over-gate it.
		$id     = self::guard( EQW_Capabilities::CAP_VIEW );
		$doc_id = get_post_meta( $id, self::META_REM_DOC_ID, true );
		if ( ! $doc_id ) {
			wp_die( esc_html__( 'No remediated file available.', 'equalweb' ) );
		}

		// Fetch the audited file from EqualWeb, which retains it for the
		// account's autoRemove window (deleteAfterAudit is never sent as true).
		$client = EQW_API_Client::for_remediation();
		if ( is_wp_error( $client ) ) {
			wp_die( esc_html( $client->get_error_message() ) );
		}
		$tmp = wp_tempnam( 'eqw-download.pdf' );
		$ok  = $client->download_to_file( $doc_id, $tmp );
		if ( is_wp_error( $ok ) ) {
			wp_delete_file( $tmp );
			// The document aged out of EqualWeb: reset a remediated_ready row so
			// it does not wedge (see handle_apply). Applied rows keep their state
			// — their accessible copy is already live on the site.
			if ( 'remediated_ready' === get_post_meta( $id, self::META_STATE, true ) ) {
				self::reset_remediation( $id );
			}
			wp_die( esc_html( $ok->get_error_message() . ' ' . __( 'The remediated copy is no longer available. The file was reset so you can remediate it again.', 'equalweb' ) ) );
		}

		// What comes back is always a PDF. PDF sources keep the historical
		// "-accessible.pdf" suffix; office sources download as {basename}.pdf
		// (report.docx -> report.pdf).
		$base = pathinfo( get_attached_file( $id ), PATHINFO_FILENAME );
		$name = 'application/pdf' === get_post_mime_type( $id ) ? $base . '-accessible.pdf' : $base . '.pdf';
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . self::content_disposition( $name ) );
		header( 'Content-Length: ' . filesize( $tmp ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a file download to the browser.
		readfile( $tmp );
		wp_delete_file( $tmp );
		exit;
	}

	/**
	 * GET (admin-ajax) — the yearly Executive report PDF for the Analytics tab.
	 *
	 * A read, so view-gated like download_file: any user who can open the page
	 * may download. The data flows through EQW_Admin_Page::yearly_stats() (the
	 * shared query_pdfs() scope choke point), so own-scope users get a report
	 * of THEIR files only. The year must be exactly 4 digits and have at least
	 * one check/remediation event.
	 */
	public static function handle_yearly_report() {
		self::guard( EQW_Capabilities::CAP_VIEW, false );
		$year = isset( $_GET['year'] ) ? sanitize_text_field( wp_unslash( $_GET['year'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() above.
		if ( ! preg_match( '/^\d{4}$/', $year ) ) {
			wp_die( esc_html__( 'Invalid year.', 'equalweb' ), '', array( 'response' => 400 ) );
		}
		$stats = EQW_Admin_Page::yearly_stats();
		$y     = (int) $year;
		if ( empty( $stats[ $y ] ) ) {
			wp_die( esc_html__( 'No report data for this year.', 'equalweb' ), '', array( 'response' => 404 ) );
		}
		// The generator stays framework-agnostic — everything WordPress-specific
		// is resolved HERE and passed as plain values. The scope wording mirrors
		// the actual query_pdfs() scoping for the requesting user.
		$pdf = EQW_PDF_Report::render(
			$y,
			$stats[ $y ],
			EQW_PDF_DIR . 'assets/logowb.jpg',
			(string) get_bloginfo( 'name' ),
			(string) wp_parse_url( home_url(), PHP_URL_HOST ),
			(string) wp_get_current_user()->display_name,
			current_user_can( EQW_Capabilities::CAP_VIEW_ALL )
		);
		// Per-user scoped data — nocache_headers() keeps shared caches from
		// serving one user's report to another.
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . self::content_disposition( 'equalweb-accessibility-report-' . $y . '.pdf' ) );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF bytes.
		exit;
	}

	/** Restore the original file from backup. */
	public static function handle_restore() {
		$id  = self::guard( EQW_Capabilities::CAP_REPLACE );
		$res = EQW_File_Manager::restore_backup( $id );
		if ( is_wp_error( $res ) ) {
			// After "save as new" the original was never modified, so there is
			// no backup — "restore" then just clears the remediation state
			// (otherwise the row would be stuck in 'remediated' forever).
			if ( 'eqw_no_backup' !== $res->get_error_code() || 'remediated' !== get_post_meta( $id, self::META_STATE, true ) ) {
				self::fail_if_error( $res );
			}
		}
		update_post_meta( $id, self::META_STATE, 'checked' );

		// Back to the original file — re-extract its metadata and drop every
		// trace of the remediation so stale "after" report links and the
		// download endpoint no longer point at the discarded remediated doc.
		delete_post_meta( $id, '_eqw_doc_title' );
		delete_post_meta( $id, '_eqw_doc_lang' );
		delete_post_meta( $id, '_eqw_pages' );
		delete_post_meta( $id, '_eqw_summary_after' );
		delete_post_meta( $id, '_eqw_report_after' );
		delete_post_meta( $id, self::META_REM_DOC_ID );
		delete_post_meta( $id, '_eqw_new_attachment' );
		// The applied-timestamp (and the backup flag) must be cleared on EVERY
		// restore path, not only the one that consumed a backup: restore_backup()
		// returns early with eqw_no_backup after a save-as-new, so a restored row
		// would otherwise keep counting as a remediation in the yearly report
		// forever. Both deletes are idempotent — the backup path already cleared
		// them.
		delete_post_meta( $id, EQW_File_Manager::META_REMEDIATED );
		delete_post_meta( $id, EQW_File_Manager::META_BACKUP );
		$title = EQW_Admin_Page::get_doc_title( $id );
		$lang  = EQW_Admin_Page::get_doc_lang( $id );

		wp_send_json_success( array(
			'state'   => 'checked',
			'message' => __( 'The original file was restored from backup.', 'equalweb' ),
			'title'   => '' !== $title ? $title : get_the_title( $id ),
			'lang'    => $lang,
		) );
	}

	/** Save the personal API key and audit defaults; validate key via balance. */
	public static function handle_save_settings() {
		self::guard( 'manage_options', false );

		if ( isset( $_POST['audit_defaults'] ) && is_array( $_POST['audit_defaults'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via self::guard() (check_ajax_referer).
			$in   = array_map( 'sanitize_text_field', wp_unslash( $_POST['audit_defaults'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via self::guard() (check_ajax_referer).
			$days = isset( $in['auto_remove'] ) ? (int) $in['auto_remove'] : 30;
			update_option( EQW_API_Client::OPT_AUDIT_DEFAULTS, array(
				'add_signature' => ! empty( $in['add_signature'] ),
				'fix_contrast'  => ! empty( $in['fix_contrast'] ),
				// Whether EqualWeb ever removes the document (the legacy key
				// name, see EQW_API_Client::audit_defaults()). It pairs with
				// auto_remove, which only says after how long.
				'delete_after_audit' => ! empty( $in['delete_after_audit'] ),
				// Only a day count the dropdown offers is stored, so what the
				// screen shows is exactly what the API is later told.
				'auto_remove'  => in_array( $days, EQW_API_Client::AUTO_REMOVE_DAYS, true ) ? $days : 30,
				'auto_replace' => ! empty( $in['auto_replace'] ),
			), false );
		}

		$key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via self::guard() (check_ajax_referer).

		// A value consisting solely of dots means "keep the existing key".
		if ( '' !== $key && preg_match( '/^\x{2022}+$/u', $key ) ) {
			wp_send_json_success( array( 'message' => __( 'Settings saved.', 'equalweb' ) ) );
		}

		if ( '' === $key ) {
			// Removing a key only deletes an option — it can never fail.
			EQW_API_Client::set_personal_key( '' );
			wp_send_json_success( array( 'message' => __( 'API key removed.', 'equalweb' ) ) );
		}

		// Validate BEFORE saving — an invalid key is never stored.
		$balance = EQW_API_Client::validate_key( $key );
		if ( is_wp_error( $balance ) ) {
			wp_send_json_error( array(
				/* translators: %s: error message returned by EqualWeb explaining why the API key is invalid */
				'message' => sprintf( __( 'Invalid API key. It was not saved (%s). Check the key and try again.', 'equalweb' ), $balance->get_error_message() ),
			) );
		}

		// Storing can REFUSE (no OpenSSL = no secure storage), and the key must
		// then not be written at all — surface that instead of reporting success.
		self::fail_if_error( EQW_API_Client::set_personal_key( $key ) );
		wp_send_json_success( array(
			/* translators: %s: remaining EqualWeb credit balance */
			'message' => sprintf( __( 'API key verified and saved. Credit balance: %s', 'equalweb' ), $balance ),
			'balance' => $balance,
		) );
	}

	/** Current credit balance for the personal key (settings screen). */
	public static function handle_balance() {
		self::guard( 'manage_options', false );
		$client  = self::fail_if_error( EQW_API_Client::for_remediation() );
		$balance = self::fail_if_error( $client->balance() );
		wp_send_json_success( array( 'balance' => $balance ) );
	}

	/**
	 * Register the site with the free-check proxy: "Try again" (trigger
	 * 'button'), the automatic attempt after the page rendered ('page') or the
	 * pre-flight before the first check ('check').
	 *
	 * Answers with the classified message and the re-rendered registration box
	 * body (html), so the page updates the notice in place. An automatic
	 * trigger only asks the service when the retry policy says it is due
	 * (EQWPDF_Registration::auto_due()); otherwise the state is reported as it
	 * is, so two tabs or a quick second click never spend a second attempt.
	 */
	public static function handle_register() {
		self::guard( 'manage_options', false );
		$trigger = isset( $_POST['trigger'] ) ? sanitize_key( wp_unslash( $_POST['trigger'] ) ) : 'button'; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via self::guard() (check_ajax_referer).
		if ( ! in_array( $trigger, array( 'page', 'button', 'check' ), true ) ) {
			$trigger = 'button';
		}

		if ( 'button' !== $trigger && ! EQWPDF_Registration::auto_due() ) {
			// Another tab or a recent attempt got there first, or the retry
			// policy says to wait: report the state as it is, without asking
			// the service again.
			$state = EQWPDF_Registration::state();
			if ( $state['registered'] ) {
				$res = true;
			} elseif ( null !== $state['failure'] ) {
				$res = new WP_Error( 'eqw_register_failed', EQWPDF_Registration::why( $state['failure'] ) );
			} else {
				$res = new WP_Error( 'eqw_register_pending', __( 'This site registers with the EqualWeb check service automatically. It takes up to 30 seconds.', 'equalweb' ) );
			}
		} else {
			// An automatic attempt for a site whose token was issued for another
			// host (it moved, or it is a copy) tells the service it is a host change.
			if ( 'button' !== $trigger && null !== EQWPDF_Registration::moved_from() ) {
				$trigger = 'host_change';
			}
			$res = EQW_API_Client::register_site( $trigger );
		}

		$html = EQW_Admin_Page::registration_notice_body();
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message(), 'html' => $html, 'registered' => false ) );
		}
		wp_send_json_success( array(
			'message'    => __( 'This site is now registered for free accessibility checks.', 'equalweb' ),
			'html'       => $html,
			'registered' => true,
		) );
	}

	/** Save the role/capability matrix from the Permissions tab. */
	public static function handle_save_permissions() {
		self::guard( 'manage_options', false );
		$matrix = isset( $_POST['matrix'] ) && is_array( $_POST['matrix'] ) ? map_deep( wp_unslash( $_POST['matrix'] ), 'sanitize_text_field' ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified via self::guard() (check_ajax_referer).
		EQW_Capabilities::save_matrix( $matrix );
		wp_send_json_success( array( 'message' => __( 'Permissions saved.', 'equalweb' ) ) );
	}

	/* ------------------------------------------------------------------ */
	/* Classification (triage) actions — all admin-only                    */
	/* ------------------------------------------------------------------ */

	/**
	 * Validated category from the request, or a JSON error + exit. There is
	 * deliberately no "exempt" — an unknown category is rejected outright.
	 *
	 * @return string
	 */
	private static function cls_cat() {
		$cat  = isset( $_REQUEST['cat'] ) ? sanitize_key( wp_unslash( $_REQUEST['cat'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() (check_ajax_referer).
		$cats = EQW_Classification::cats();
		if ( ! isset( $cats[ $cat ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown category.', 'equalweb' ) ), 400 );
		}
		return $cat;
	}

	/**
	 * Location prefix from the request, validated against the locations that
	 * actually exist for the caller-visible files — an arbitrary prefix can
	 * neither be planted nor probed.
	 *
	 * @return string
	 */
	private static function cls_prefix() {
		$prefix = isset( $_REQUEST['prefix'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['prefix'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() (check_ajax_referer).
		foreach ( EQW_Admin_Page::all_doc_posts() as $post ) {
			$uri = EQW_Classification::uri( $post->ID );
			if ( EQW_Classification::top_location( $uri ) === $prefix || EQW_Classification::dir_prefix( $uri ) === $prefix ) {
				return $prefix;
			}
		}
		wp_send_json_error( array( 'message' => __( 'Unknown location.', 'equalweb' ) ), 400 );
	}

	/**
	 * Selected attachment ids from the request, deduped, capped at 200 and
	 * filtered to documents the caller may actually see. An empty result is a
	 * JSON error + exit.
	 *
	 * @return int[]
	 */
	private static function cls_ids() {
		$raw = isset( $_REQUEST['fids'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_REQUEST['fids'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() (check_ajax_referer).
		$ids = array_slice( array_unique( array_map( 'intval', $raw ) ), 0, 200 );
		$ok  = array();
		foreach ( $ids as $id ) {
			if ( $id && 'attachment' === get_post_type( $id )
				&& array_key_exists( (string) get_post_mime_type( $id ), EQW_Admin_Page::DOC_MIMES )
				&& EQW_Capabilities::current_user_can_scope( $id ) ) {
				$ok[] = $id;
			}
		}
		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => __( 'No valid documents selected.', 'equalweb' ) ), 400 );
		}
		return $ok;
	}

	/** Tag a location (folder); every file under it inherits. */
	public static function handle_cls_tag_location() {
		self::guard( 'manage_options', false );
		$cat    = self::cls_cat();
		$prefix = self::cls_prefix();
		EQW_Classification::tag_location( $prefix, $cat );
		wp_send_json_success( array( 'prefix' => $prefix, 'cat' => $cat ) );
	}

	/** Remove a location tag (falls back to automatic rules). */
	public static function handle_cls_clear_location() {
		self::guard( 'manage_options', false );
		$prefix = isset( $_REQUEST['prefix'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['prefix'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() (check_ajax_referer).
		if ( ! array_key_exists( $prefix, EQW_Classification::location_tags() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown location.', 'equalweb' ) ), 400 );
		}
		EQW_Classification::clear_location( $prefix );
		wp_send_json_success( array( 'prefix' => $prefix ) );
	}

	/** Per-file admin tag for the selection (beats location + auto rules). */
	public static function handle_cls_bulk_tag() {
		self::guard( 'manage_options', false );
		$cat  = self::cls_cat();
		$ids  = self::cls_ids();
		$cats = EQW_Classification::cats();
		$by   = wp_get_current_user()->display_name;
		foreach ( $ids as $id ) {
			update_post_meta( $id, EQW_Classification::META_CAT, $cat );
			update_post_meta( $id, EQW_Classification::META_PRIO, (int) $cats[ $cat ]['prio'] );
			update_post_meta( $id, EQW_Classification::META_SRC, 'user' );
			update_post_meta( $id, EQW_Classification::META_RULES, array( 'Tagged by ' . $by . ' on ' . date_i18n( 'M j, Y' ) . ' (overrides auto rules)' ) );
		}
		wp_send_json_success( array( 'count' => count( $ids ), 'cat' => $cat ) );
	}

	/**
	 * Remove the per-file admin tag for the selection — the files fall back to
	 * their location tag (if any) or the automatic rules. "Back to automatic".
	 */
	public static function handle_cls_bulk_untag() {
		self::guard( 'manage_options', false );
		$ids = self::cls_ids();
		foreach ( $ids as $id ) {
			delete_post_meta( $id, EQW_Classification::META_CAT );
			delete_post_meta( $id, EQW_Classification::META_PRIO );
			delete_post_meta( $id, EQW_Classification::META_SRC );
			delete_post_meta( $id, EQW_Classification::META_RULES );
		}
		wp_send_json_success( array( 'count' => count( $ids ) ) );
	}

	/**
	 * Hide the selection from the inventory. Logged and reversible; ignored
	 * files are hidden, never exempted — restore brings them back exactly.
	 */
	public static function handle_cls_ignore() {
		self::guard( 'manage_options', false );
		$ids = self::cls_ids();
		$by  = wp_get_current_user()->display_name;
		foreach ( $ids as $id ) {
			update_post_meta( $id, EQW_Classification::META_IGNORED, 1 );
			update_post_meta( $id, EQW_Classification::META_IGNORED_BY, $by );
			update_post_meta( $id, EQW_Classification::META_IGNORED_AT, time() );
		}
		wp_send_json_success( array( 'count' => count( $ids ) ) );
	}

	/** Restore every ignored document. */
	public static function handle_cls_unignore() {
		self::guard( 'manage_options', false );
		$count = 0;
		foreach ( EQW_Admin_Page::all_doc_posts() as $post ) {
			if ( get_post_meta( $post->ID, EQW_Classification::META_IGNORED, true ) ) {
				delete_post_meta( $post->ID, EQW_Classification::META_IGNORED );
				delete_post_meta( $post->ID, EQW_Classification::META_IGNORED_BY );
				delete_post_meta( $post->ID, EQW_Classification::META_IGNORED_AT );
				$count++;
			}
		}
		wp_send_json_success( array( 'count' => $count ) );
	}

	/**
	 * Set the auto-archive age threshold (years). Age never overrides active
	 * use — a file referenced by published content stays out of Archive at any
	 * age — so raising/lowering this only moves UNLINKED files.
	 */
	public static function handle_cls_set_archive_years() {
		self::guard( 'manage_options', false );
		$years = isset( $_REQUEST['years'] ) ? (int) $_REQUEST['years'] : -1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified via self::guard() (check_ajax_referer).
		if ( $years < 0 || $years > 15 ) {
			wp_send_json_error( array( 'message' => __( 'Years must be between 0 (off) and 15.', 'equalweb' ) ), 400 );
		}
		EQW_Classification::set_archive_years( $years );
		wp_send_json_success( array( 'years' => $years ) );
	}
}
