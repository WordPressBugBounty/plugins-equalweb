/* global eqwPdf, jQuery */
( function ( $ ) {
	'use strict';

	var i18n = eqwPdf.i18n;
	var caps = eqwPdf.caps || {};
	var POLL_INTERVAL = 5000;

	// Inline SVG UI icons (~12px, stroke follows the text color, decorative).
	// IDENTICAL markup to EQW_Admin_Page::icon() — PHP-rendered and
	// JS-rendered links must look the same; change both together.
	var ICONS = {
		external: '<svg class="eqw-i" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M9 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-3"/></svg>',
		download: '<svg class="eqw-i" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M12 4v11"/><path d="M6 10l6 6 6-6"/><path d="M4 20h16"/></svg>'
	};

	// The external icon is decorative (aria-hidden); this visually-hidden text
	// makes screen readers announce that the link opens an external site. Emit
	// it immediately BEFORE ICONS.external on every external link.
	var EXT_SR = '<span class="screen-reader-text"> (' + i18n.externalLink + ')</span>';

	function ajax( action, data ) {
		return $.post( eqwPdf.ajaxUrl, $.extend( {
			action: 'eqw_' + action,
			_ajax_nonce: eqwPdf.nonce
		}, data || {} ) );
	}

	/**
	 * Error text for a FAILED request. jQuery routes every non-2xx response to
	 * .fail(), where res.data.message is never read — so the deliberate server
	 * messages sent with 400/403/429 (insufficient permissions, invalid
	 * document, rate limit) were replaced by a bare "HTTP 403". Read the
	 * envelope here and fall back to the status code only when there is none.
	 */
	function failMessage( xhr ) {
		var body = xhr && xhr.responseJSON;
		var msg = body && ( ( body.data && body.data.message ) || body.message );
		return msg || ( 'HTTP ' + ( xhr && xhr.status ? xhr.status : '?' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Row rendering                                                       */
	/* ------------------------------------------------------------------ */

	function scoreBadge( score ) {
		// Coerce and clamp: the value can come straight from the API report, so
		// forcing it to a 0–100 number keeps it safe to drop into HTML/CSS.
		score = Math.max( 0, Math.min( 100, Math.round( Number( score ) || 0 ) ) );
		// Color scale: 90-100 green, 70-89 orange, 0-69 red.
		var cls = score >= 90 ? 'eqw-ok' : ( score >= 70 ? 'eqw-warn' : 'eqw-bad' );
		return '<span class="eqw-score"><span class="eqw-badge ' + cls + '" title="' + i18n.score + '">' + score + '/100</span>' +
			'<span class="eqw-meter" aria-hidden="true"><i class="' + cls + '-fill" style="width:' + score + '%"></i></span></span>';
	}

	function scoreCell( score, reportUrl ) {
		// The score and the report link are independent: a remediation can finish
		// and produce a report at EqualWeb even when the score fetch failed, so a
		// missing score renders the placeholder but the "View report" link is still
		// shown whenever a reportUrl is present. (A score of 0 is a real score,
		// rendered by scoreBadge below, never treated as missing.)
		var missing = score === '' || score === undefined || score === null;
		var html = missing ? '<span class="eqw-empty">—</span>' : scoreBadge( Number( score ) );
		if ( reportUrl ) {
			html += ' <a class="eqw-viewlink" href="' + reportUrl + '" target="_blank" rel="noopener" title="' +
				i18n.viewReport + '">' + i18n.viewReport + EXT_SR + ICONS.external + '</a>';
		}
		return html;
	}

	// SERIAL CHECK LOCK (nir 2026-07-31, ported from Shopify): one MANUAL check at a
	// time. While a check is submitting or running, every OTHER row's check button is
	// disabled with a tooltip. The BULK check button is exempt (deliberate multi-send).
	// Two signals cover the whole window: rows the server already shows as 'checking',
	// and rows whose check submission is still in flight (closes the click-race gap
	// before the row state catches up).
	var checkSubmitting = {};
	function anyCheckActive() {
		return $( '.eqw-files tr[data-state="checking"]' ).length > 0 ||
			Object.keys( checkSubmitting ).length > 0;
	}
	function applyCheckLock() {
		var $btns = $( '.eqw-files button[data-action="check"]' );
		if ( anyCheckActive() ) {
			// Only mark buttons WE lock (data-checklock), so unlocking never re-enables
			// a button disabled for another reason.
			$btns.not( '[data-checklock]' ).attr( 'data-checklock', '1' )
				.prop( 'disabled', true ).attr( 'title', i18n.checkLockTitle );
		} else {
			$btns.filter( '[data-checklock]' ).removeAttr( 'data-checklock' )
				.prop( 'disabled', false ).removeAttr( 'title' );
		}
	}

	function renderRow( $row ) {
		var state = $row.data( 'state' );
		var score = $row.data( 'score' );
		var scoreAfter = $row.data( 'score-after' );
		var url = $row.attr( 'data-report-url' );
		var urlAfter = $row.attr( 'data-report-url-after' );
		var $before = $row.find( '.eqw-before-cell' ).html( scoreCell( score, url ) );
		var $after = $row.find( '.eqw-after-cell' ).html( scoreCell( scoreAfter, urlAfter ) );
		var $status = $row.find( '.eqw-status-cell' ).empty();
		var $actions = $row.find( '.eqw-actions-cell' ).empty();

		function btn( action, label, primary ) {
			return $( '<button type="button" class="button ' + ( primary ? 'button-primary' : '' ) + '"></button>' )
				.attr( 'data-action', action ).text( label );
		}

		// Office documents (Word/PowerPoint/Excel) have their own, smaller
		// state machine: no checker, no replace, no restore — remediation
		// always returns a NEW accessible PDF. PDF rows keep the exact
		// behavior below, untouched.
		var doctype = $row.attr( 'data-doctype' ) || 'pdf';
		if ( doctype !== 'pdf' ) {
			switch ( state ) {
				case 'remediating':
					if ( caps.check || caps.remediate ) {
						$status.html( '<span class="spinner is-active eqw-spinner"></span> ' + i18n.remediating );
						startPolling( $row, 'remediate' );
					} else {
						$status.html( '<span class="eqw-badge eqw-plain">' + i18n.inProgress + '</span>' );
					}
					break;

				case 'remediated_ready':
					$status.html( '<span class="eqw-badge eqw-warn">' + i18n.ready + '</span>' );
					// Save-as-new needs the replace capability (same per-action
					// gate as PDF apply); Download is view-level.
					var $oReady = $( '<div class="eqw-actions-row"/>' );
					if ( caps.replace ) {
						$oReady.append( btn( 'apply-new', i18n.saveAsPdf, true ) ).append( ' ' );
					}
					$oReady.append( btn( 'download', i18n.download ) );
					$actions.append( $( '<div class="eqw-choice"/>' )
						.append( $( '<p/>' ).text( i18n.officeReady ) )
						.append( $oReady ) );
					break;

				case 'remediated':
					// The accessible PDF was saved to the media library (the
					// auto-save at poll time, or apply-new via the fallback
					// choice box): offer it for viewing and for download.
					$status.html( '<span class="eqw-badge eqw-ok">✓ ' + i18n.accessible + '</span>' );
					if ( $row.attr( 'data-new-url' ) ) {
						$actions.append(
							$( '<a class="button" target="_blank" rel="noopener"></a>' )
								.attr( 'href', $row.attr( 'data-new-url' ) )
								.text( i18n.openAccessiblePdf ).append( EXT_SR + ICONS.external )
						).append( ' ' ).append(
							$( '<a class="button"></a>' )
								.attr( 'href', $row.attr( 'data-new-url' ) )
								.attr( 'download', '' )
								.text( i18n.downloadPdf ).append( ICONS.download )
						);
					}
					break;

				default:
					// 'none', a reset row ('checked') and anything unknown all
					// offer the single entry point: send for remediation. The
					// button is rendered DISABLED (with a tooltip) when no
					// personal API key is configured — free mode only checks
					// PDFs.
					$status.html( '<span class="eqw-badge eqw-plain">' + i18n.notRemediated + '</span>' );
					if ( caps.remediate ) {
						var $send = btn( 'remediate', i18n.sendRemediation, true );
						if ( ! eqwPdf.hasApiKey ) {
							$send.prop( 'disabled', true ).attr( 'title', i18n.keyRequired );
						}
						$actions.append( $send );
					}
			}
			return;
		}

		switch ( state ) {
			case 'checking':
			case 'remediating':
				// Polling (both modes) rides check OR remediate (any-of) — a
				// remediate-only role must be able to poll its own paid run to
				// completion. With neither, a static badge avoids a guaranteed
				// 403 poll loop; the row refreshes on the next page load.
				if ( caps.check || caps.remediate ) {
					$status.html( '<span class="spinner is-active eqw-spinner"></span> ' +
						( state === 'checking' ? i18n.checking : i18n.remediating ) );
					startPolling( $row, state === 'remediating' ? 'remediate' : 'check' );
				} else {
					$status.html( '<span class="eqw-badge eqw-plain">' + i18n.inProgress + '</span>' );
				}
				break;

			case 'checked':
				$status.html( '<span class="eqw-badge eqw-warn">' + i18n.notRemediated + '</span>' );
				$actions.append( btn( 'report', i18n.details ) );
				if ( caps.remediate ) {
					$actions.append( ' ' ).append( btn( 'remediate', i18n.remediate, true ) );
				}
				break;

			case 'remediated_ready':
				$status.html( '<span class="eqw-badge eqw-warn">' + i18n.ready + '</span>' );
				// Download is a read — any viewer gets it; replace/save-as-new
				// publish to the site and need the replace capability.
				var $choiceRow = $( '<div class="eqw-actions-row"/>' );
				if ( caps.replace ) {
					$choiceRow.append( btn( 'apply-replace', i18n.applyReplace, true ) ).append( ' ' )
						.append( btn( 'apply-new', i18n.applyNew ) ).append( ' ' );
				}
				$choiceRow.append( btn( 'download', i18n.applyDownload ) );
				$actions.append( $( '<div class="eqw-choice"/>' )
					.append( $( '<p/>' ).text( i18n.remediateReady ) )
					.append( $choiceRow ) );
				break;

			case 'remediated':
				$status.html( '<span class="eqw-badge eqw-ok">✓ ' + i18n.accessible + '</span>' );
				$actions.append( btn( 'report', i18n.details ) );
				// Restore is offered regardless of backup presence: after
				// save-as-new there is no backup, and the server's restore
				// handler then just clears the remediation state.
				if ( caps.replace ) {
					$actions.append( ' ' ).append( btn( 'restore', i18n.restore ) );
				}
				if ( $row.attr( 'data-new-url' ) ) {
					$actions.append( ' ' ).append(
						$( '<a class="button" target="_blank" rel="noopener"></a>' )
							.attr( 'href', $row.attr( 'data-new-url' ) )
							.text( i18n.openCopy ).append( EXT_SR + ICONS.external )
					);
				}
				break;

			default:
				$status.html( '<span class="eqw-badge eqw-plain">' + i18n.notChecked + '</span>' );
				if ( caps.check ) {
					$actions.append( btn( 'check', i18n.check, true ) ).append( ' ' );
				}
				if ( caps.remediate ) {
					$actions.append( btn( 'remediate', i18n.remediate ) );
				}
		}
		// Re-evaluate the serial check lock after every row (re-)render.
		applyCheckLock();
	}

	function setState( $row, state, score, hasBackup, scoreAfter ) {
		$row.data( 'state', state ).attr( 'data-state', state );
		if ( score !== undefined && score !== null ) {
			$row.data( 'score', score ).attr( 'data-score', score );
		}
		if ( scoreAfter !== undefined && scoreAfter !== null ) {
			$row.data( 'score-after', scoreAfter ).attr( 'data-score-after', scoreAfter );
		}
		if ( hasBackup !== undefined ) {
			$row.data( 'has-backup', hasBackup ? 1 : 0 );
		}
		renderRow( $row );
	}

	/** Refresh the title and language cells from an AJAX response. */
	function updateFileMeta( $row, data ) {
		if ( data.title ) {
			var $link = $row.find( '.eqw-col-file > a' );
			$link.contents().filter( function () { return this.nodeType === 3; } ).remove();
			// Text node — PDF metadata is attacker-controlled, never parse it as HTML.
			$link.append( document.createTextNode( ' ' + data.title ) );
			var filename = $row.find( '.eqw-filename' ).text() || '';
			$row.attr( 'data-search', ( data.title + ' ' + filename ).toLowerCase() );
		}
		if ( data.lang !== undefined ) {
			$row.find( '.eqw-lang-cell' ).text( data.lang || '—' );
		}
		if ( data.new_url ) {
			$row.attr( 'data-new-url', data.new_url );
		}
	}

	function showError( $row, message ) {
		$row.find( '.eqw-status-cell' ).html(
			'<span class="eqw-badge eqw-bad">' + i18n.error + '</span> <small></small>'
		).find( 'small' ).text( message || '' );
	}

	/* ------------------------------------------------------------------ */
	/* Polling                                                             */
	/* ------------------------------------------------------------------ */

	function startPolling( $row, mode ) {
		if ( $row.data( 'polling' ) ) {
			return;
		}
		$row.data( 'polling', true );
		var failures = 0;

		function tick() {
			ajax( 'poll', { attachment_id: $row.data( 'id' ), mode: mode } )
				.done( function ( res ) {
					if ( ! res.success ) {
						// Transient upstream hiccups are retried before giving up.
						failures++;
						if ( failures < 4 ) {
							setTimeout( tick, POLL_INTERVAL * 2 );
							return;
						}
						$row.data( 'polling', false );
						showError( $row, res.data && res.data.message );
						return;
					}
					failures = 0;
					if ( res.data.state === 'processing' ) {
						setTimeout( tick, POLL_INTERVAL );
						return;
					}
					$row.data( 'polling', false );
					var score = res.data.summary ? res.data.summary.score : undefined;
					var after = res.data.summary_after ? res.data.summary_after.score : undefined;
					if ( res.data.report_url ) {
						$row.attr( 'data-report-url', res.data.report_url );
					}
					if ( res.data.report_url_after ) {
						$row.attr( 'data-report-url-after', res.data.report_url_after );
					}
					updateFileMeta( $row, res.data );
					// Landing on 'remediated' straight from a poll means PDF
					// auto-replace (backup kept) — except office rows, whose
					// auto-save never touches the original (no backup).
					var polledBackup = ( res.data.state === 'remediated' &&
						( $row.attr( 'data-doctype' ) || 'pdf' ) === 'pdf' ) ? true : undefined;
					setState( $row, res.data.state, score, polledBackup, after );
				} )
				.fail( function ( xhr ) {
					// Transport-level failures (network drop, or a 403 once the
					// admin nonce expires in a long-lived tab) must give up like
					// the .done error path does — otherwise the tab polls forever.
					failures++;
					if ( failures < 4 ) {
						setTimeout( tick, POLL_INTERVAL * 2 );
						return;
					}
					$row.data( 'polling', false );
					showError( $row, failMessage( xhr ) );
				} );
		}
		setTimeout( tick, POLL_INTERVAL );
	}

	/* ------------------------------------------------------------------ */
	/* Report modal                                                        */
	/* ------------------------------------------------------------------ */

	function openModal( html, fullscreen ) {
		$( '#eqw-modal .eqw-modal-content' ).html( html );
		$( '#eqw-modal .eqw-modal-box' ).toggleClass( 'eqw-modal-full', !! fullscreen );
		$( '#eqw-modal' ).prop( 'hidden', false );
	}

	function summaryLine( label, report ) {
		var issues = report.totalIssues || {};
		// A score of 0 is a real score (an untagged PDF scores exactly 0); null
		// means "unknown". `== null` catches null AND undefined, so an unknown
		// score renders the empty placeholder instead of a red 0/100 badge.
		var score = report.remediatedScore != null ? report.remediatedScore
			: ( report.checkerScore != null ? report.checkerScore : null );
		// Force the counts to numbers — they originate from the API report and
		// are interpolated straight into HTML.
		var passed = Number( issues.passed ) || 0;
		var failed = Number( issues.failed ) || 0;
		var warn   = Number( issues.warning ) || 0;
		return '<p class="eqw-report-summary"><span class="eqw-ba-label">' + label + '</span> ' +
			( score == null ? '<span class="eqw-empty">—</span>' : scoreBadge( score ) ) +
			' <span class="eqw-badge eqw-ok">' + i18n.passed + ': ' + passed + '</span>' +
			' <span class="eqw-badge eqw-bad">' + i18n.failed + ': ' + failed + '</span>' +
			' <span class="eqw-badge eqw-warn">' + i18n.warnings + ': ' + warn + '</span></p>';
	}

	function issuesTable( report ) {
		var rows = ( report.checkerReport || [] ).filter( function ( r ) {
			return r.error > 0 || r.warning > 0;
		} );
		if ( ! rows.length ) {
			return '<p>' + i18n.noIssues + '</p>';
		}
		var html = '<table class="widefat striped"><thead><tr>' +
			'<th scope="col">' + i18n.reportColStatus + '</th>' +
			'<th scope="col">' + i18n.reportColIssue + '</th></tr></thead><tbody>';
		rows.sort( function ( a, b ) {
			// Missing error/severity would make the subtraction NaN and the sort
			// order undefined; default them to 0.
			return ( ( Number( b.error ) || 0 ) - ( Number( a.error ) || 0 ) ) ||
				( ( Number( b.severity ) || 0 ) - ( Number( a.severity ) || 0 ) );
		} );
		rows.forEach( function ( r ) {
			var errCount = Number( r.error ) || 0;
			var badge = errCount > 0
				? '<span class="eqw-badge eqw-bad">' + i18n.failed + ( errCount > 1 ? ' ×' + errCount : '' ) + '</span>'
				: '<span class="eqw-badge eqw-warn">' + i18n.warnings + '</span>';
			html += '<tr><td class="eqw-report-badge">' + badge + '</td><td><strong>' +
				$( '<i>' ).text( r.name ).html() + '</strong>' +
				( r.pdf_ua_ref ? ' <code>' + $( '<i>' ).text( r.pdf_ua_ref ).html() + '</code>' : '' ) +
				'<br><small>' + $( '<i>' ).text( r.explanation || r.description || '' ).html() + '</small></td></tr>';
		} );
		return html + '</tbody></table>';
	}

	function reportHtml( before, after ) {
		var html = '<h2>' + i18n.reportTitle + '</h2>';
		if ( before ) {
			html += summaryLine( i18n.before, before );
		}
		if ( after ) {
			html += summaryLine( i18n.after, after );
			html += issuesTable( after );
		} else if ( before ) {
			html += issuesTable( before );
		}
		return html;
	}

	/**
	 * The three-score strip + "why this rank" for the report modal. Usage is
	 * honest about platform reality: WordPress has no per-file view counter, so
	 * it shows "not tracked" instead of a fake number.
	 */
	function clsStrip( $tr ) {
		var prio = $tr.attr( 'data-prio' ) || '';
		var rules = $tr.attr( 'data-cls-rules' ) || '';
		var acc = $tr.attr( 'data-score-after' ) || $tr.attr( 'data-score' ) || '';
		var catLabel = ( ( $tr.find( '.eqw-cat-cell .eqw-tagchip' ).clone()
			.find( '[aria-hidden]' ).remove().end().text() ) || '' ).trim();
		var prioColor = prio !== '' && prio >= 85 ? '#a12727' : ( prio >= 60 ? '#9a4b00' : '#787c82' );
		return '<div class="eqw-scores3">' +
			'<div class="eqw-sc3"><small>' + i18n.clsAccessibility + '</small><b>' +
				( acc === '' ? '—' : acc + '/100' ) + '</b><span>' + i18n.clsMeasured + '</span></div>' +
			'<div class="eqw-sc3"><small>' + i18n.clsLegal + '</small><b style="color:' + prioColor + '">' +
				( prio === '' ? '—' : prio + '/100' ) + '</b><span>' +
				$( '<i>' ).text( catLabel ).html() + ' — ' + i18n.clsLegalSub + '</span></div>' +
			'<div class="eqw-sc3"><small>' + i18n.clsUsage + '</small><b>—</b><span>' + i18n.clsUsageNA + '</span></div>' +
		'</div>' +
		( rules ? '<div class="eqw-whyrank"><b>' + i18n.clsWhy + '</b><p>' +
			$( '<i>' ).text( rules ).html() + '</p></div>' : '' );
	}

	/* ------------------------------------------------------------------ */
	/* Free-check registration                                             */
	/* ------------------------------------------------------------------ */

	// PHP renders the notice (#eqw-regnotice), and the server answers every
	// attempt with the re-rendered notice body, so all wording comes from the
	// server (the generic error label aside); messages are written as text
	// nodes only. The click handler is delegated (see the ready block) because
	// each answer replaces the body and its button.
	var registering = null;
	function register( trigger ) {
		if ( registering ) {
			return registering;
		}
		var $box = $( '#eqw-regnotice' );
		$box.attr( 'aria-busy', 'true' );
		$box.find( '#eqw-register' ).prop( 'disabled', true );
		$box.find( '.eqw-regnotice-error' ).prop( 'hidden', true ).text( '' );
		$box.find( '.eqw-regnotice-busy' ).prop( 'hidden', false );
		registering = ajax( 'register', { trigger: trigger } ).done( function ( res ) {
			var data = ( res && res.data ) || {};
			if ( typeof data.registered === 'boolean' ) {
				eqwPdf.registered = data.registered;
			}
			if ( typeof data.html === 'string' && data.html !== '' ) {
				$box.find( '.eqw-regnotice-body' ).replaceWith( $.parseHTML( data.html ) );
			} else {
				$box.find( '#eqw-register' ).prop( 'disabled', false );
				$box.find( '.eqw-regnotice-busy' ).prop( 'hidden', true );
			}
			$box.find( '.eqw-regnotice-live' ).text( data.message || '' );
		} ).fail( function ( xhr ) {
			// A refused request (an expired nonce 403, a PHP fatal, a gateway
			// timeout) must never leave the button disabled with no message.
			var msg = i18n.error + ': ' + failMessage( xhr );
			$box.find( '#eqw-register' ).prop( 'disabled', false );
			$box.find( '.eqw-regnotice-busy' ).prop( 'hidden', true );
			$box.find( '.eqw-regnotice-error' ).text( msg ).prop( 'hidden', false );
			$box.find( '.eqw-regnotice-live' ).text( msg );
		} ).always( function () {
			registering = null;
			$box.removeAttr( 'aria-busy' );
			if ( trigger === 'button' ) {
				// Focus follows the result, so keyboard and screen reader users
				// land on what changed.
				$box.find( '#eqw-regnotice-title' ).trigger( 'focus' );
			}
		} );
		return registering;
	}

	// Before the first check on a site that is not registered: ONE registration
	// (administrators only; the server applies the retry policy), so the rows of
	// a bulk check do not all fail while the first one is still registering.
	// Always resolves: the rows then report the outcome in their own words.
	function ensureRegistered() {
		var done = $.Deferred();
		if ( eqwPdf.registered || eqwPdf.hasApiKey || ! eqwPdf.isAdmin ) {
			return done.resolve().promise();
		}
		register( 'check' ).always( function () {
			done.resolve();
		} );
		return done.promise();
	}

	/* ------------------------------------------------------------------ */
	/* Events                                                              */
	/* ------------------------------------------------------------------ */

	/** Kick off a check or remediation for one row (used by row and bulk buttons). */
	function startProcess( $row, action ) {
		var state = $row.data( 'state' );
		// Never restart work in progress, never clobber a finished remediation
		// waiting for the user's apply decision, and never re-process an
		// already-remediated file (a bulk action must not silently re-check it
		// or re-spend credits). Matches the server-side guards.
		if ( state === 'checking' || state === 'remediating' ||
			state === 'remediated_ready' || state === 'remediated' ) {
			return;
		}
		// Serial check lock: record this manual check as in-flight so a second one is
		// blocked (and every other row's check button disabled) until it settles.
		var rowId = String( $row.data( 'id' ) );
		if ( action === 'check' ) {
			checkSubmitting[ rowId ] = true;
			applyCheckLock();
		}
		$row.find( '.eqw-actions-cell button' ).prop( 'disabled', true );
		var send = function () {
			ajax( action, { attachment_id: $row.data( 'id' ) } )
				.done( function ( res ) {
					if ( action === 'check' ) { delete checkSubmitting[ rowId ]; }
					if ( ! res.success ) {
						renderRow( $row ); // Re-enable buttons first… (renderRow re-applies the lock)
						showError( $row, res.data && res.data.message ); // …then keep the error visible.
						return;
					}
					setState( $row, res.data.state );
				} )
				.fail( function ( xhr ) {
					if ( action === 'check' ) { delete checkSubmitting[ rowId ]; }
					renderRow( $row );
					showError( $row, failMessage( xhr ) );
				} );
		};
		// A free check on an unregistered site registers first (once); the lock
		// above already covers the wait.
		if ( action === 'check' ) {
			ensureRegistered().always( send );
		} else {
			send();
		}
	}

	function openNeedKeyModal() {
		// Non-admins cannot open Settings or register an account for the
		// site — tell them to ask an administrator instead.
		if ( ! eqwPdf.isAdmin ) {
			openModal( '<h2>' + i18n.needKeyTitle + '</h2><p>' + i18n.needKeyBodyNoAdmin + '</p>' );
			return;
		}
		openModal(
			'<h2>' + i18n.needKeyTitle + '</h2>' +
			'<p>' + i18n.needKeyBody + '</p>' +
			'<p class="eqw-report-links">' +
			'<a class="button button-primary" href="' + eqwPdf.signupUrl + '" target="_blank" rel="noopener">' +
				i18n.buyCredits + EXT_SR + ICONS.external + '</a> ' +
			'<a class="button" href="' + eqwPdf.settingsUrl + '">' + i18n.openSettings + '</a>' +
			'</p>'
		);
	}

	/* ------------------------------------------------------------------ */
	/* Client-side search / filter / sort / pagination                     */
	/* ------------------------------------------------------------------ */

	var PER_PAGE = 10;
	var listPage = 1;
	// Document-type pill filter ('' = All) — combined with search + status.
	var typeFilter = '';

	/** Single source of truth for the search + status + type filter. */
	function rowMatches( $tr, query, filter ) {
		var state = String( $tr.attr( 'data-state' ) || 'none' );
		if ( typeFilter && String( $tr.attr( 'data-doctype' ) || 'pdf' ) !== typeFilter ) {
			return false;
		}
		if ( query && String( $tr.attr( 'data-search' ) || '' ).indexOf( query ) === -1 ) {
			return false;
		}
		// Classification filter (the category panel routes through this too).
		if ( filter && filter.indexOf( 'cls_' ) === 0 ) {
			return String( $tr.attr( 'data-cat' ) || '' ) === filter.slice( 4 );
		}
		switch ( filter ) {
			case 'unchecked':      return state === 'none';
			case 'checked':        return state === 'checked';
			case 'in_progress':    return state === 'checking' || state === 'remediating';
			case 'ready':          return state === 'remediated_ready';
			case 'remediated':     return state === 'remediated';
			case 'not_remediated': return state !== 'remediated';
			case 'replaced':       return String( $tr.data( 'has-backup' ) ) === '1';
			case 'not_replaced':   return String( $tr.data( 'has-backup' ) ) !== '1';
		}
		return true;
	}

	function applyListView() {
		var $tbody = $( '.eqw-files tbody' );
		if ( ! $tbody.length ) {
			return;
		}
		var query = ( $( '#eqw-q' ).val() || '' ).toLowerCase();
		var filter = $( '#eqw-filter' ).val() || '';
		var sort = $( '#eqw-sort' ).val() || 'newest';
		var $all = $tbody.find( 'tr' );

		var visible = $all.get().filter( function ( tr ) {
			return rowMatches( $( tr ), query, filter );
		} );

		if ( sort === 'prio_desc' ) {
			visible.sort( function ( a, b ) {
				return ( parseInt( $( b ).attr( 'data-prio' ), 10 ) || 0 ) -
					( parseInt( $( a ).attr( 'data-prio' ), 10 ) || 0 );
			} );
			visible.forEach( function ( tr ) { $tbody.append( tr ); } );
		} else if ( sort === 'score_asc' || sort === 'score_desc' ) {
			visible.sort( function ( a, b ) {
				var sa = $( a ).attr( 'data-score' ), sb = $( b ).attr( 'data-score' );
				var na = sa === '' || sa === undefined, nb = sb === '' || sb === undefined;
				if ( na || nb ) {
					return ( na ? 1 : 0 ) - ( nb ? 1 : 0 ); // Unchecked last.
				}
				return sort === 'score_asc' ? sa - sb : sb - sa;
			} );
			visible.forEach( function ( tr ) { $tbody.append( tr ); } );
		} else {
			// Newest first = original render order (kept via data-order).
			visible.sort( function ( a, b ) {
				return $( a ).data( 'order' ) - $( b ).data( 'order' );
			} );
			visible.forEach( function ( tr ) { $tbody.append( tr ); } );
		}

		var pages = Math.max( 1, Math.ceil( visible.length / PER_PAGE ) );
		if ( listPage > pages ) {
			listPage = pages;
		}
		$all.hide();
		visible.slice( ( listPage - 1 ) * PER_PAGE, listPage * PER_PAGE ).forEach( function ( tr ) {
			$( tr ).show();
		} );

		$( '#eqw-no-match' ).prop( 'hidden', visible.length > 0 );

		var $pager = $( '#eqw-pager' ).empty();
		if ( pages > 1 ) {
			$pager.append( $( '<span class="eqw-pager-count"/>' ).text( visible.length + ' ' + i18n.items ) );
			var mk = function ( label, page, disabled, current ) {
				return $( '<button type="button" class="eqw-pbtn"/>' )
					.text( label ).prop( 'disabled', !! disabled )
					.toggleClass( 'eqw-pbtn-current', !! current )
					.attr( 'data-page', page );
			};
			$pager.append( mk( '‹', listPage - 1, listPage === 1 ) );
			for ( var i = 1; i <= pages; i++ ) {
				$pager.append( mk( i, i, false, i === listPage ) );
			}
			$pager.append( mk( '›', listPage + 1, listPage === pages ) );
		}
	}

	$( function () {
		/* Theme toggle — persisted per browser. */
		function applyTheme( theme ) {
			var dark = theme === 'dark';
			$( '.eqw-wrap, #eqw-modal' ).toggleClass( 'eqw-dark', dark );
			$( '#eqw-theme-toggle' ).attr( 'aria-pressed', dark ? 'true' : 'false' );
		}
		try {
			applyTheme( window.localStorage.getItem( 'eqwPdfTheme' ) );
		} catch ( e ) {}
		$( '#eqw-theme-toggle' ).on( 'click', function () {
			var next = $( '.eqw-wrap' ).hasClass( 'eqw-dark' ) ? 'light' : 'dark';
			applyTheme( next );
			try {
				window.localStorage.setItem( 'eqwPdfTheme', next );
			} catch ( e ) {}
		} );

		$( '.eqw-files tbody tr' ).each( function ( idx ) {
			$( this ).data( 'order', idx );
			renderRow( $( this ) );
		} );
		applyListView();

		function setBulkNotice( msg ) {
			$( '#eqw-bulk-notice' ).text( msg || '' ).prop( 'hidden', ! msg );
		}

		// Changing the search/filter clears the selection so bulk actions
		// never operate on rows that are no longer in view.
		function clearSelection() {
			$( '#eqw-check-all' ).prop( 'checked', false );
			$( '.eqw-table .eqw-row-cb' ).prop( 'checked', false );
			refreshBulkState();
			// A notice about a selection that is gone would be a lie.
			setBulkNotice( '' );
		}

		$( '#eqw-q' ).on( 'input', function () { listPage = 1; clearSelection(); applyListView(); } );
		$( '.eqw-typepills' ).on( 'click', '.eqw-pill', function () {
			var $pill = $( this );
			typeFilter = $pill.attr( 'data-doctype' ) || '';
			$( '.eqw-typepills .eqw-pill' ).removeClass( 'eqw-pill-active' ).attr( 'aria-pressed', 'false' );
			$pill.addClass( 'eqw-pill-active' ).attr( 'aria-pressed', 'true' );
			listPage = 1;
			clearSelection();
			applyListView();
		} );
		$( '#eqw-filter' ).on( 'change', function () { listPage = 1; clearSelection(); applyListView(); syncCatPanel(); } );
		$( '#eqw-sort' ).on( 'change', function () { listPage = 1; clearSelection(); applyListView(); } );
		$( '#eqw-pager' ).on( 'click', '.eqw-pbtn', function () {
			listPage = parseInt( $( this ).attr( 'data-page' ), 10 );
			clearSelection();
			applyListView();
		} );

		/* ---------------- Multi-select + bulk actions ---------------- */

		function selectedRows() {
			return $( '.eqw-files tbody tr' ).filter( function () {
				return $( this ).find( '.eqw-row-cb' ).prop( 'checked' );
			} );
		}

		function refreshBulkState() {
			var count = selectedRows().length;
			$( '#eqw-selected-count' ).text( count ? count + ' ' + i18n.selected : '' );
			$( '#eqw-bulk-check, #eqw-bulk-remediate' ).prop( 'disabled', ! count );
			$( '#eqw-bulk-tag, #eqw-bulk-ignore' ).prop( 'disabled', ! count );
		}

		// "Check all" selects only the rows visible on the current page
		// (after search/filter/pagination) — never hidden ones.
		$( '#eqw-check-all' ).on( 'change', function () {
			var checked = this.checked;
			$( '.eqw-table .eqw-row-cb' ).prop( 'checked', false );
			if ( checked ) {
				$( '.eqw-files tbody tr:visible .eqw-row-cb' ).prop( 'checked', true );
			}
			refreshBulkState();
		} );

		$( '.eqw-files' ).on( 'change', '.eqw-row-cb', refreshBulkState );

		// A row may be sent only from a state the server would accept: a check
		// needs a never-checked row, a remediation also takes an already-checked
		// one. Work in progress, a remediation waiting for its apply decision and
		// an already-remediated file are all refused. Matches the server guards.
		function bulkEligible( $row, action ) {
			var state = String( $row.attr( 'data-state' ) || 'none' );
			if ( action === 'check' ) {
				return state === 'none';
			}
			return state === 'none' || state === 'checked';
		}

		function runBulk( action ) {
			// Only remediation needs the personal API key — free checks run
			// through the shared proxy.
			if ( action === 'remediate' && ! eqwPdf.hasApiKey ) {
				openNeedKeyModal();
				return;
			}
			var $rows = selectedRows();
			// The checker is PDF-only, so office rows are not eligible for a bulk
			// check. They are counted, not dropped silently: a vanishing row looks
			// like a broken button.
			var skippedOffice = 0;
			if ( action === 'check' ) {
				var $pdfs = $rows.filter( function () {
					return ( $( this ).attr( 'data-doctype' ) || 'pdf' ) === 'pdf';
				} );
				skippedOffice = $rows.length - $pdfs.length;
				$rows = $pdfs;
			}
			// Drop already-processed rows BEFORE sending. The server refuses them
			// anyway, but only after painting an error badge on a row whose only
			// sin is being finished already.
			var $ready = $rows.filter( function () {
				return bulkEligible( $( this ), action );
			} );
			var skippedDone = $rows.length - $ready.length;
			$rows = $ready;

			var parts = [];
			if ( $rows.length ) {
				parts.push( $rows.length + ' ' + ( $rows.length === 1 ? i18n.bulkSentOne : i18n.bulkSentMany ) );
			}
			if ( skippedDone ) {
				parts.push( skippedDone + ' ' +
					( action === 'check' ? i18n.bulkSkippedChecked : i18n.bulkSkippedProcessed ) );
			}
			if ( skippedOffice ) {
				parts.push( skippedOffice + ' ' + i18n.bulkSkippedOffice );
			}
			if ( ! $rows.length ) {
				clearSelection();
				setBulkNotice( parts.length ?
					i18n.bulkNothingSent + ' ' + parts.join( ' ' ) : i18n.bulkNothingToSend );
				return;
			}
			// Office documents come back as a converted PDF — the single-row path
			// spells that out before spending credits, so a bulk that includes any
			// office row must ask exactly the same question (once).
			if ( action === 'remediate' ) {
				var hasOffice = $rows.filter( function () {
					return ( $( this ).attr( 'data-doctype' ) || 'pdf' ) !== 'pdf';
				} ).length > 0;
				if ( hasOffice && ! window.confirm( i18n.officeConfirm ) ) {
					return;
				}
			}
			var launch = function () {
				// Stagger the launches so uploads don't hammer the server at once.
				$rows.each( function ( idx ) {
					var $row = $( this );
					setTimeout( function () {
						startProcess( $row, action );
					}, idx * 800 );
				} );
				clearSelection();
				// clearSelection() resets the notice, so the outcome is set after it.
				setBulkNotice( parts.join( ' ' ) );
			};
			if ( action !== 'check' ) {
				launch();
				return;
			}
			// A bulk check on an unregistered site registers ONCE first. If the
			// site is still not registered afterwards, nothing is sent: every
			// row would only repeat the same message, which the notice says once.
			ensureRegistered().always( function () {
				if ( ! eqwPdf.registered && ! eqwPdf.hasApiKey ) {
					clearSelection();
					setBulkNotice( eqwPdf.isAdmin ? i18n.regRowAdmin : i18n.regRowUser );
					return;
				}
				launch();
			} );
		}

		$( '#eqw-bulk-check' ).on( 'click', function () { runBulk( 'check' ); } );
		$( '#eqw-bulk-remediate' ).on( 'click', function () { runBulk( 'remediate' ); } );

		$( '.eqw-files' ).on( 'click', 'button[data-action]', function () {
			var $btn = $( this );
			var $row = $btn.closest( 'tr' );
			var id = $row.data( 'id' );
			var action = $btn.data( 'action' );

			if ( action === 'remediate' && ! eqwPdf.hasApiKey ) {
				openNeedKeyModal();
				return;
			}

			// Office documents are converted to a PDF by the remediation —
			// make that explicit before spending credits. PDF rows keep the
			// existing no-confirm flow.
			if ( action === 'remediate' && ( $row.attr( 'data-doctype' ) || 'pdf' ) !== 'pdf' &&
					! window.confirm( i18n.officeConfirm ) ) {
				return;
			}

			if ( action === 'check' || action === 'remediate' ) {
				// Serial check lock: block a manual check that races in before the other
				// rows' buttons were disabled. This row's own running check is caught by
				// startProcess's state guard; bulk sends bypass this handler entirely.
				if ( action === 'check' && anyCheckActive() && $row.data( 'state' ) !== 'checking' ) {
					return;
				}
				startProcess( $row, action );
				return;
			}

			if ( action === 'apply-replace' || action === 'apply-new' ) {
				if ( action === 'apply-replace' && ! window.confirm( i18n.confirmReplace ) ) {
					return;
				}
				$row.find( '.eqw-actions-cell button' ).prop( 'disabled', true );
				ajax( 'apply', { attachment_id: id, how: action === 'apply-replace' ? 'replace' : 'new' } )
					.done( function ( res ) {
						if ( ! res.success ) {
							renderRow( $row );
							showError( $row, res.data && res.data.message );
							return;
						}
						updateFileMeta( $row, res.data );
						setState( $row, res.data.state, undefined, action === 'apply-replace' );
						if ( res.data.message ) {
							openModal( '<p>' + $( '<i>' ).text( res.data.message ).html() + '</p>' );
						}
					} )
					.fail( function ( xhr ) {
						renderRow( $row );
						showError( $row, failMessage( xhr ) );
					} );
				return;
			}

			if ( action === 'download' ) {
				window.location = eqwPdf.ajaxUrl + '?action=eqw_download_file&attachment_id=' + id +
					'&_ajax_nonce=' + eqwPdf.nonce;
				return;
			}

			if ( action === 'restore' ) {
				if ( ! window.confirm( i18n.confirmRestore ) ) {
					return;
				}
				ajax( 'restore', { attachment_id: id } ).done( function ( res ) {
					if ( res.success ) {
						updateFileMeta( $row, res.data );
						$row.attr( 'data-score-after', '' ).data( 'score-after', '' );
						$row.attr( 'data-report-url-after', '' );
						// The server dropped the pointer to the accessible copy, so
						// the row must too: kept, it would resurface as an "Open
						// accessible copy" link to the PREVIOUS run's discarded file
						// after the next remediate + apply.
						$row.attr( 'data-new-url', '' );
						setState( $row, res.data.state, undefined, false );
					} else {
						showError( $row, res.data && res.data.message );
					}
				} ).fail( function ( xhr ) {
					showError( $row, failMessage( xhr ) );
				} );
				return;
			}

			if ( action === 'report' ) {
				ajax( 'report', { attachment_id: id } ).done( function ( res ) {
					if ( res.success ) {
						var links = '';
						var u = $row.attr( 'data-report-url' );
						var ua = $row.attr( 'data-report-url-after' );
						if ( u ) {
							links += '<a class="button eqw-viewbtn" href="' + u + '" target="_blank" rel="noopener">' +
								i18n.viewReport + ' (' + i18n.before + ')' + ICONS.external + '</a> ';
						}
						if ( ua ) {
							links += '<a class="button eqw-viewbtn" href="' + ua + '" target="_blank" rel="noopener">' +
								i18n.viewReport + ' (' + i18n.after + ')' + ICONS.external + '</a>';
						}
						openModal( reportHtml( res.data.report, res.data.report_after ) +
							clsStrip( $row ) +
							( links ? '<p class="eqw-report-links">' + links + '</p>' : '' ) );
					} else {
						openModal( '<p>' + $( '<i>' ).text( res.data && res.data.message || i18n.error ).html() + '</p>' );
					}
				} ).fail( function ( xhr ) {
					openModal( '<p>' + $( '<i>' ).text( failMessage( xhr ) ).html() + '</p>' );
				} );
			}
		} );

		$( '#eqw-modal' ).on( 'click', function ( e ) {
			if ( e.target === this || $( e.target ).is( '.eqw-modal-close' ) ) {
				$( this ).prop( 'hidden', true );
			}
		} );
		$( document ).on( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				$( '#eqw-modal' ).prop( 'hidden', true );
			}
		} );

		/* ---------------- Classification (triage) ---------------- */

		// Outcome messages must survive the reload that follows every
		// classification action — otherwise a successful action looks like
		// nothing happened. sessionStorage carries the message across.
		function reloadWithNotice( msg ) {
			try {
				window.sessionStorage.setItem( 'eqwClsNotice', msg );
			} catch ( e ) { /* private mode — reload without the notice */ }
			window.location.reload();
		}
		try {
			var pendingCls = window.sessionStorage.getItem( 'eqwClsNotice' );
			if ( pendingCls ) {
				window.sessionStorage.removeItem( 'eqwClsNotice' );
				var $clsNotice = $( '#eqw-bulk-notice' );
				if ( ! $clsNotice.length ) {
					$clsNotice = $( '<div class="eqw-bulk-notice notice notice-info" role="status"></div>' )
						.prependTo( $( '.eqw-cls' ).length ? '.eqw-cls' : '.eqw-wrap' );
				}
				$clsNotice.text( pendingCls ).prop( 'hidden', false );
			}
		} catch ( e ) { /* sessionStorage unavailable */ }

		// Keep the panel's pressed/dimmed state in lockstep with the filter,
		// wherever the filter was changed from (panel click OR the select).
		function syncCatPanel() {
			var val = $( '#eqw-filter' ).val() || '';
			var filtered = val.indexOf( 'cls_' ) === 0;
			$( '.eqw-catpanel .eqw-catrow' ).each( function () {
				var on = val === 'cls_' + $( this ).attr( 'data-cat' );
				$( this ).toggleClass( 'eqw-catrow-active', on ).attr( 'aria-pressed', on ? 'true' : 'false' )
					.toggleClass( 'eqw-catrow-dim', filtered && ! on );
			} );
		}
		syncCatPanel();

		$( '.eqw-catpanel' ).on( 'click', '.eqw-catrow', function () {
			var val = 'cls_' + $( this ).attr( 'data-cat' );
			var $f = $( '#eqw-filter' );
			$f.val( $f.val() === val ? '' : val ).trigger( 'change' );
		} );

		$( '.eqw-catpanel' ).on( 'click', '#eqw-cls-unignore', function ( e ) {
			e.stopPropagation();
			var $btn = $( this ).prop( 'disabled', true );
			ajax( 'cls_unignore' ).done( function ( res ) {
				if ( res.success ) {
					reloadWithNotice( ( res.data.count || 0 ) + ' ' + i18n.clsRestored );
				} else {
					$btn.prop( 'disabled', false );
					window.alert( res.data && res.data.message || i18n.error );
				}
			} ).fail( function ( xhr ) {
				$btn.prop( 'disabled', false );
				window.alert( failMessage( xhr ) );
			} );
		} );

		function selectedFids() {
			return selectedRows().get().map( function ( tr ) {
				return $( tr ).data( 'id' );
			} );
		}

		// The change-classification modal serves both the bulk button and a
		// single row's category chip. "Back to automatic" clears the per-file
		// tag so the location tag / automatic rules apply again.
		function openClsChoiceModal( fids ) {
			if ( ! fids.length ) {
				return;
			}
			// Category buttons are built from the panel rows — one source of
			// truth for labels and colors (PHP renders both).
			var buttons = '';
			$( '.eqw-catpanel .eqw-catrow' ).each( function () {
				var cat = $( this ).attr( 'data-cat' );
				// String.prototype.trim, NOT $.trim — jQuery 4 removed it.
				var label = ( $( this ).find( '.eqw-catname' ).text() || '' ).trim();
				var sw = $( this ).find( '.eqw-catsw' ).attr( 'style' ) || '';
				buttons += '<button type="button" class="button eqw-cls-choice" data-cat="' + cat + '">' +
					'<span class="eqw-catsw" style="' + sw.replace( /"/g, '&quot;' ) + '"></span> ' +
					$( '<i>' ).text( label ).html() + '</button>';
			} );
			buttons += '<button type="button" class="button eqw-cls-choice" data-cat="">🤖 ' +
				i18n.clsAuto + '</button>';
			openModal(
				'<h2>' + i18n.clsTitle + '</h2><p>' + i18n.clsBody + '</p>' +
				'<div class="eqw-cls-choices">' + buttons + '</div>'
			);
			$( '#eqw-modal .eqw-cls-choice' ).on( 'click', function () {
				var cat = $( this ).attr( 'data-cat' );
				$( '#eqw-modal .eqw-cls-choice' ).prop( 'disabled', true );
				var call = cat ?
					ajax( 'cls_bulk_tag', { fids: fids, cat: cat } ) :
					ajax( 'cls_bulk_untag', { fids: fids } );
				call.done( function ( res ) {
					if ( res.success ) {
						reloadWithNotice( fids.length + ' ' + ( cat ? i18n.clsTagged : i18n.clsAutoDone ) );
					} else {
						$( '#eqw-modal .eqw-cls-choice' ).prop( 'disabled', false );
						window.alert( res.data && res.data.message || i18n.error );
					}
				} ).fail( function ( xhr ) {
					$( '#eqw-modal .eqw-cls-choice' ).prop( 'disabled', false );
					window.alert( failMessage( xhr ) );
				} );
			} );
		}

		$( '#eqw-bulk-tag' ).on( 'click', function () {
			openClsChoiceModal( selectedFids() );
		} );

		$( '#eqw-bulk-ignore' ).on( 'click', function () {
			var fids = selectedFids();
			if ( ! fids.length || ! window.confirm( i18n.clsIgnoreConfirm ) ) {
				return;
			}
			ajax( 'cls_ignore', { fids: fids } ).done( function ( res ) {
				if ( res.success ) {
					reloadWithNotice( fids.length + ' ' + i18n.clsIgnored );
				} else {
					window.alert( res.data && res.data.message || i18n.error );
				}
			} ).fail( function ( xhr ) {
				window.alert( failMessage( xhr ) );
			} );
		} );

		// A row's category chip IS the control: admins click it to reclassify
		// that one file directly (the bulk path stays for many at once).
		if ( caps.admin ) {
			$( '.eqw-files' ).on( 'click', '.eqw-tagchip', function () {
				openClsChoiceModal( [ $( this ).closest( 'tr' ).data( 'id' ) ] );
			} );
			$( '.eqw-files .eqw-tagchip' ).addClass( 'eqw-tagchip-btn' )
				.attr( 'role', 'button' ).attr( 'tabindex', '0' )
				.attr( 'title', function ( i, t ) {
					return ( t ? t + ' — ' : '' ) + i18n.clsChipHint;
				} );
		}

		/* ---------------- Classification screen (locations) ----------- */

		function clsLocationCall( actionName, $rowEl, extra ) {
			var data = $.extend( { prefix: $rowEl.attr( 'data-prefix' ) }, extra || {} );
			$rowEl.find( 'button, select' ).prop( 'disabled', true );
			ajax( actionName, data ).done( function ( res ) {
				if ( res.success ) {
					reloadWithNotice( actionName === 'cls_clear_location' ? i18n.clsLocCleared : i18n.clsLocTagged );
				} else {
					$rowEl.find( 'button, select' ).prop( 'disabled', false );
					window.alert( res.data && res.data.message || i18n.error );
				}
			} ).fail( function ( xhr ) {
				$rowEl.find( 'button, select' ).prop( 'disabled', false );
				window.alert( failMessage( xhr ) );
			} );
		}

		$( '.eqw-cls-list' ).on( 'change', '.eqw-cls-select', function () {
			var cat = $( this ).val();
			if ( cat ) {
				clsLocationCall( 'cls_tag_location', $( this ).closest( '.eqw-clsrow' ), { cat: cat } );
			}
		} );
		$( '.eqw-cls-list' ).on( 'click', '.eqw-cls-accept', function () {
			clsLocationCall( 'cls_tag_location', $( this ).closest( '.eqw-clsrow' ),
				{ cat: $( this ).attr( 'data-cat' ) } );
		} );
		$( '.eqw-cls-list' ).on( 'click', '.eqw-cls-clear', function () {
			clsLocationCall( 'cls_clear_location', $( this ).closest( '.eqw-clsrow' ) );
		} );
		// Auto-archive age threshold (Classification screen).
		$( '#eqw-cls-archive-years' ).on( 'change', function () {
			var $sel = $( this ).prop( 'disabled', true );
			ajax( 'cls_set_archive_years', { years: $sel.val() } ).done( function ( res ) {
				if ( res.success ) {
					reloadWithNotice( i18n.clsYearsSaved );
				} else {
					$sel.prop( 'disabled', false );
					window.alert( res.data && res.data.message || i18n.error );
				}
			} ).fail( function ( xhr ) {
				$sel.prop( 'disabled', false );
				window.alert( failMessage( xhr ) );
			} );
		} );

		/* Settings tab */
		// The day count only means anything while removal is enabled.
		$( '#eqw-ad-autoremove-enabled' ).on( 'change', function () {
			$( '#eqw-ad-autoremove' ).prop( 'disabled', ! $( this ).is( ':checked' ) );
		} );
		$( '#eqw-save-settings' ).on( 'click', function () {
			var $msg = $( '#eqw-settings-msg' ).text( '…' );
			ajax( 'save_settings', {
				api_key: $( '#eqw-api-key' ).val(),
				'audit_defaults[add_signature]': $( '#eqw-ad-signature' ).is( ':checked' ) ? 1 : '',
				'audit_defaults[fix_contrast]': $( '#eqw-ad-contrast' ).is( ':checked' ) ? 1 : '',
				'audit_defaults[delete_after_audit]': $( '#eqw-ad-autoremove-enabled' ).is( ':checked' ) ? 1 : '',
				'audit_defaults[auto_remove]': $( '#eqw-ad-autoremove' ).val() || 30,
				'audit_defaults[auto_replace]': $( '#eqw-ad-autoreplace' ).is( ':checked' ) ? 1 : ''
			} ).done( function ( res ) {
				$msg.text( res.data && res.data.message || '' )
					.toggleClass( 'eqw-msg-error', ! res.success );
			} ).fail( function ( xhr ) {
				// Without this the message stays on "…" forever when the request
				// is refused outright (an expired nonce 403 in a long-lived tab).
				$msg.text( failMessage( xhr ) ).addClass( 'eqw-msg-error' );
			} );
		} );
		$( '#eqw-remove-key' ).on( 'click', function () {
			if ( ! window.confirm( i18n.removeKeyConfirm ) ) {
				return;
			}
			ajax( 'save_settings', { api_key: '' } ).done( function () {
				window.location.reload();
			} );
		} );
		/* Free-check registration (the notice on every tab + the Settings tab). */
		/* -------------------- Welcome offer: copy the code -------------------- */
		// Clipboard first; when that is refused, the code is selected so a manual
		// copy works. The outcome is written as text into the status line.
		$( document ).on( 'click', '.eqw-promo-copy', function () {
			var $btn = $( this );
			var code = String( $btn.data( 'code' ) || '' );
			var $box = $btn.closest( '.eqw-promo, .eqw-offer' );
			var $status = $box.find( '.eqw-promo-status, .eqw-offer-status' ).first();
			var $code = $box.find( '.eqw-promo-code, .eqw-offer-code' ).first();
			var select = function () {
				try {
					var range = document.createRange();
					range.selectNodeContents( $code.get( 0 ) );
					var sel = window.getSelection();
					sel.removeAllRanges();
					sel.addRange( range );
				} catch ( e ) { /* nothing to select */ }
				$status.text( $btn.data( 'selected' ) || '' );
			};
			var done = function () { $status.text( $btn.data( 'copied' ) || '' ); };
			try {
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( code ).then( done, select );
				} else {
					select();
				}
			} catch ( e ) {
				select();
			}
		} );

		$( document ).on( 'click', '#eqw-register', function () {
			register( 'button' );
		} );
		// Automatic registration runs after the page has rendered, never
		// blocking it; the server decides when it is due (retry policy).
		if ( eqwPdf.regAuto ) {
			register( 'page' );
		}
		if ( $( '#eqw-balance' ).length ) {
			ajax( 'balance' ).done( function ( res ) {
				$( '#eqw-balance' ).text( res.success ? res.data.balance : ( res.data && res.data.message || '—' ) );
			} );
		}

		/* Permissions tab */
		// Ticking any action auto-ticks View — mirrors the server-side rule.
		$( '.eqw-perm-table' ).on( 'change', '.eqw-perm-cb', function () {
			var $cb = $( this );
			if ( $cb.prop( 'checked' ) && $cb.attr( 'data-cap' ) !== 'eqw_pdf_view' ) {
				$cb.closest( 'tr' ).find( '.eqw-perm-cb[data-cap="eqw_pdf_view"]' ).prop( 'checked', true );
			}
		} );
		// "All files" visibility auto-ticks View too — same server-side rule.
		$( '.eqw-perm-table' ).on( 'change', '.eqw-perm-scope', function () {
			var $sel = $( this );
			if ( $sel.val() === '1' ) {
				$sel.closest( 'tr' ).find( '.eqw-perm-cb[data-cap="eqw_pdf_view"]' ).prop( 'checked', true );
			}
		} );
		$( '#eqw-save-permissions' ).on( 'click', function () {
			var $msg = $( '#eqw-permissions-msg' ).text( '…' ).removeClass( 'eqw-msg-error' );
			var data = {};
			// Roles with nothing ticked are simply absent — the server treats
			// a missing role as "revoke everything".
			$( '.eqw-perm-table tbody tr' ).each( function () {
				var $tr = $( this );
				var role = $tr.attr( 'data-role' );
				if ( role === 'administrator' ) {
					return; // Locked row — the server refuses to modify it anyway.
				}
				$tr.find( '.eqw-perm-cb:checked' ).each( function () {
					data[ 'matrix[' + role + '][' + $( this ).attr( 'data-cap' ) + ']' ] = 1;
				} );
				if ( $tr.find( '.eqw-perm-scope' ).val() === '1' ) {
					data[ 'matrix[' + role + '][eqw_pdf_view_all]' ] = 1;
				}
			} );
			ajax( 'save_permissions', data ).done( function ( res ) {
				$msg.text( res.data && res.data.message || '' )
					.toggleClass( 'eqw-msg-error', ! res.success );
			} ).fail( function ( xhr ) {
				$msg.text( failMessage( xhr ) ).addClass( 'eqw-msg-error' );
			} );
		} );
	} );
} )( jQuery );

/* ====================================================================== */
/* Accessibility widget tab: live preview, colour helpers, the site check  */
/* ====================================================================== */
jQuery( function ( $ ) {
	var $tab = $( '.eqw-widget' );
	if ( ! $tab.length ) {
		return;
	}
	var i18n = ( window.eqwPdf && eqwPdf.i18n ) || {};
	var cdn = $tab.data( 'eqwCdn' ) || 'https://cdn.equalweb.com/';
	var coreUrl = $tab.data( 'eqwCore' ) || '';
	var integrity = $tab.data( 'eqwIntegrity' ) || '';
	var sitekey = String( $tab.data( 'eqwSitekey' ) || '' );
	var $form = $( '#eqw-design-form' );
	var $frame = $( '#eqw-preview' );

	// ---- the config, built the way the PHP side builds it (EQW_Widget::config) ----
	var val = function ( name ) { return $.trim( String( $form.find( '[name="eqw_btn[' + name + ']"]' ).val() || '' ) ); };
	var lenOk = function ( v ) { return v === '' || /^-?\d+(\.\d+)?(px|%|em|rem|vh|vw)?$/.test( v ); };
	var hexOk = function ( v ) { return v === '' || /^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i.test( v ); };
	var pair = function ( d, m ) {
		d = lenOk( d ) ? d : ''; m = lenOk( m ) ? m : '';
		return ( d === '' && m === '' ) ? null : [ d === '' ? null : d, m === '' ? null : m ];
	};
	var scale = function ( v ) { var f = parseFloat( v ); return ( v !== '' && f >= 0.1 && f <= 3 ) ? String( f ) : ''; };
	function config() {
		var c = { sitekey: sitekey, domains: { js: cdn, acc: 'https://access.equalweb.com/' } };
		var pos = val( 'position' ), lang = val( 'menulang' );
		if ( pos ) { c.Position = pos; }
		if ( lang ) { c.Menulang = lang; }
		c.draggable = $form.find( '[name="eqw_btn[draggable]"]' ).is( ':checked' );
		var style = {};
		var vp = pair( val( 'vposition_d' ), val( 'vposition_m' ) ); if ( vp ) { style.vPosition = vp; }
		var mg = pair( val( 'margin_d' ), val( 'margin_m' ) ); if ( mg ) { style.margin = mg; }
		var sc = pair( scale( val( 'scale_d' ) ), scale( val( 'scale_m' ) ) ); if ( sc ) { style.scale = sc; }
		var main = val( 'color_main' ), second = val( 'color_second' );
		main = hexOk( main ) ? main : ''; second = hexOk( second ) ? second : '';
		if ( main || second ) { style.color = { main: main || '#171d2d', second: second || '#ffffff' }; }
		var icon = { shape: val( 'icon_shape' ) || 'circle', outline: $( '#eqw-outline' ).is( ':checked' ) };
		if ( $form.find( '[name="eqw_btn[icon_mode]"]:checked' ).val() === 'text' ) {
			icon.type = val( 'icon_text' ).slice( 0, 20 ) || 'Accessibility';
		} else {
			icon.type = parseInt( $form.find( '[name="eqw_btn[icon_type]"]:checked' ).val(), 10 ) || 1;
		}
		var oc = val( 'outline_color' );
		if ( icon.outline && hexOk( oc ) && oc ) { icon.outlineColor = oc; }
		style.icon = icon;
		c.btnStyle = style;
		return c;
	}

	// ---- the preview: a page of its own with the real widget ----
	var timer = null;
	function render() {
		if ( ! $frame.length || ! coreUrl ) {
			return;
		}
		var cfg = JSON.stringify( config() ).replace( /<\//g, '<\\/' );
		var attrs = ' defer crossorigin="anonymous"' + ( integrity ? ' integrity="' + integrity + '"' : '' );
		var doc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			+ '<style>html,body{margin:0;height:100%;background:#f3f4f6;font:14px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#1d2327}'
			+ '.eqw-stage{padding:24px;max-width:36em}.eqw-stage h1{font-size:18px;margin:0 0 8px}.eqw-stage p{margin:0 0 8px;color:#50575e}</style></head>'
			+ '<body><div class="eqw-stage"><h1>' + ( i18n.widgetPreviewTitle || 'Your page' ) + '</h1><p>' + ( i18n.widgetPreviewText || 'The accessibility button appears where your visitors will see it.' ) + '</p></div>'
			+ '<script>window.interdeal = ' + cfg + ';</script>'
			+ '<script src="' + coreUrl.replace( /"/g, '' ) + '"' + attrs + '></script></body></html>';
		$frame.attr( 'srcdoc', doc );
	}
	function schedule() {
		clearTimeout( timer );
		timer = setTimeout( render, 400 );
	}
	// The icon choices look like the button itself: the current colours, shape, side and outline.
	function paintTiles() {
		var $g = $( '.eqw-wicons' );
		if ( ! $g.length ) { return; }
		var main = val( 'color_main' ), second = val( 'color_second' ), oc = val( 'outline_color' );
		var el = $g.get( 0 );
		el.style.setProperty( '--eqw-wmain', hexOk( main ) && main ? main : '#171d2d' );
		el.style.setProperty( '--eqw-wsecond', hexOk( second ) && second ? second : '#ffffff' );
		el.style.setProperty( '--eqw-woutline', $( '#eqw-outline' ).is( ':checked' ) ? '0 0 0 3px ' + ( hexOk( oc ) && oc ? oc : '#ffffff' ) : 'none' );
		$g.attr( 'class', 'eqw-wicons eqw-wshape-' + ( val( 'icon_shape' ) || 'circle' ) + ( val( 'position' ) === 'right' ? ' eqw-wright' : '' ) + ( $g.hasClass( 'eqw-wicons-off' ) ? ' eqw-wicons-off' : '' ) );
	}
	$form.on( 'input change', 'input, select', paintTiles );
	paintTiles();
	$form.on( 'input change', 'input, select', schedule );
	render();

	// ---- colour pickers <-> hex fields, and the contrast readout ----
	$.each( [ [ 'main', '#171d2d' ], [ 'second', '#ffffff' ], [ 'outline', '#ffffff' ] ], function ( _, item ) {
		var key = item[ 0 ];
		var $pick = $( '#eqw-' + ( key === 'outline' ? 'outline-color' : 'color-' + key ) + '-pick' );
		var $hex = $( '#eqw-' + ( key === 'outline' ? 'outline-color' : 'color-' + key ) );
		$pick.on( 'input change', function () { $hex.val( $pick.val() ).trigger( 'change' ); } );
		$hex.on( 'input change', function () {
			var v = $.trim( $hex.val() );
			if ( /^#[0-9a-f]{6}$/i.test( v ) ) { $pick.val( v ); }
			contrast();
		} );
	} );
	function luminance( hex ) {
		hex = hex.replace( '#', '' );
		if ( hex.length === 3 ) { hex = hex.replace( /(.)/g, '$1$1' ); }
		var rgb = [ 0, 2, 4 ].map( function ( i ) {
			var c = parseInt( hex.substr( i, 2 ), 16 ) / 255;
			return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
		} );
		return 0.2126 * rgb[ 0 ] + 0.7152 * rgb[ 1 ] + 0.0722 * rgb[ 2 ];
	}
	function contrast() {
		var $out = $( '#eqw-contrast' );
		var a = $.trim( $( '#eqw-color-main' ).val() ) || '#171d2d';
		var b = $.trim( $( '#eqw-color-second' ).val() ) || '#ffffff';
		if ( ! /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( a ) || ! /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test( b ) ) {
			$out.text( '' );
			return;
		}
		var l1 = luminance( a ), l2 = luminance( b );
		var ratio = ( Math.max( l1, l2 ) + 0.05 ) / ( Math.min( l1, l2 ) + 0.05 );
		var r = Math.round( ratio * 10 ) / 10;
		var ok = ratio >= 3;
		$out.text( ( i18n.widgetContrast || 'Icon to button contrast: %s' ).replace( '%s', r + ':1' ) + ' ' + ( ok ? ( i18n.widgetContrastOk || '(meets WCAG for graphics)' ) : ( i18n.widgetContrastLow || '(below 3:1, hard to see)' ) ) )
			.toggleClass( 'eqw-wcontrast-low', ! ok );
	}
	contrast();

	// ---- icon vs text ----
	function iconMode() {
		var text = $form.find( '[name="eqw_btn[icon_mode]"]:checked' ).val() === 'text';
		$( '.eqw-wicons' ).toggleClass( 'eqw-wicons-off', text );
		$( '.eqw-wicon-text' ).toggle( text );
	}
	$form.on( 'change', '[name="eqw_btn[icon_mode]"]', iconMode );
	iconMode();

	// ---- "Check on your site" ----
	$( '#eqw-widget-check' ).on( 'click', function () {
		var $btn = $( this ), $out = $( '.eqw-wcheck-result' );
		$btn.prop( 'disabled', true );
		$out.text( i18n.widgetChecking || 'Checking…' );
		$.post( eqwPdf.ajaxUrl, { action: 'eqw_widget_check', _ajax_nonce: eqwPdf.nonce } )
			.done( function ( res ) {
				var d = ( res && res.data ) || {};
				var texts = {
					ok: i18n.widgetCheckOk || 'The widget is on your site.',
					partial: i18n.widgetCheckPartial || 'Only part of the widget was found on your site.',
					missing: i18n.widgetCheckMissing || 'The widget was not found on your site. Purge your page cache and check again.',
					error: i18n.widgetCheckError || 'Your site could not be fetched.'
				};
				var cls = d.status === 'ok' ? 'eqw-ok' : ( d.status === 'error' ? 'eqw-bad' : 'eqw-warn' );
				$out.empty().append( $( '<span class="eqw-badge"/>' ).addClass( cls ).text( texts[ d.status ] || d.status || texts.error ) );
				if ( d.status === 'error' && d.detail ) { $out.append( ' ' ).append( $( '<code/>' ).text( d.detail ) ); }
			} )
			.fail( function () { $out.text( i18n.widgetCheckError || 'Your site could not be fetched.' ); } )
			.always( function () { $btn.prop( 'disabled', false ); } );
	} );
} );
