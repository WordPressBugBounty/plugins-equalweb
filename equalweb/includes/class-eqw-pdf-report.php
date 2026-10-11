<?php
/**
 * Dependency-free generator for the yearly "Executive report" PDF
 * (Analytics tab download). Emits a complete, valid, TAGGED PDF 1.4 file
 * as a string — the report of an accessibility product must itself be
 * accessible:
 *
 *  - Catalog: /Lang, /MarkInfo /Marked true, /StructTreeRoot,
 *    /ViewerPreferences /DisplayDocTitle true, XMP /Metadata.
 *  - Document Info /Title + XMP dc:title / dc:language.
 *  - Full structure tree in logical reading order (Document -> Figure/H1/
 *    P/H2 …), every figure with a meaningful /Alt that conveys the data,
 *    decorative rects marked as /Artifact.
 *  - Content stream fully wrapped in marked content (/Tag << /MCID n >>
 *    BDC … EMC), page /StructParents + /Tabs /S, /ParentTree number tree
 *    mapping MCIDs to their StructElems.
 *
 * The class is framework-agnostic: everything WordPress-specific (site name,
 * host, user, scope) is passed in as plain parameters. Fonts are the Type1
 * base-14 Helvetica pair (WinAnsiEncoding) with real AFM width tables for
 * alignment; the logo JPEG is embedded as-is via /Filter /DCTDecode; the
 * xref offsets are accumulated with strlen() while the body is written —
 * the part that MUST be byte-exact or the file won't open. Text is
 * ASCII/Latin1 only and every string is escaped for \ ( ).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class EQW_PDF_Report {

	/**
	 * Producer string, stamped into BOTH the Info dictionary and the XMP packet.
	 * Derived from the single plugin version constant: the two emitters carried
	 * a hardcoded version each and had silently drifted a release behind.
	 */
	const PRODUCER = 'EqualWeb PDF Accessibility ' . EQW_PDF_VERSION;

	/** Page geometry (A4 portrait, points). */
	const W = 595;
	const H = 842;
	const MARGIN = 40;

	/** Palette (mirrors assets/admin.css). */
	const INK = '1d2327';
	const GRAY = '50575e';
	const GREEN = '0d9668';
	const RED = 'a12727';
	const BAR_GRAY = '9aa0a6';
	const TILE_BG = 'f0f0f1';
	const TILE_LINE = 'dcdcde';
	const HEADER_SUB = 'c9cdd1';

	/**
	 * Green for TEXT (--eqw-green-dark). The brand green #0d9668 is only
	 * 3.8:1 against white — fine for bar fills (graphics), but text must meet
	 * WCAG 1.4.3's 4.5:1, and #0a7350 gives 5.9:1.
	 */
	const GREEN_TEXT = '0a7350';

	/**
	 * Glyph widths for chars 32..126 (thousandths of an em). These are the
	 * Helvetica AFM values AND (verified by parsing the TTFs' cmap+hmtx) the
	 * exact Liberation Sans values — Liberation is Arial-metric, Arial is
	 * Helvetica-metric — so one table serves the /Widths array of the
	 * embedded font, the base-14 fallback, and text alignment alike.
	 */
	const W_REG = array(
		278, 278, 355, 556, 556, 889, 667, 191, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 278, 278, 584, 584, 584, 556,
		1015, 667, 667, 722, 722, 667, 611, 778, 722, 278, 500, 667, 556, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 278, 278, 278, 469, 556,
		333, 556, 556, 500, 556, 556, 278, 556, 556, 222, 222, 500, 222, 833, 556, 556,
		556, 556, 333, 500, 278, 556, 500, 722, 500, 500, 500, 334, 260, 334, 584,
	);

	/** Helvetica-Bold / Liberation Sans Bold glyph widths for chars 32..126. */
	const W_BOLD = array(
		278, 333, 474, 556, 556, 889, 722, 238, 333, 333, 389, 584, 278, 333, 278, 278,
		556, 556, 556, 556, 556, 556, 556, 556, 556, 556, 333, 333, 584, 584, 584, 611,
		975, 722, 722, 722, 722, 667, 611, 778, 722, 278, 556, 722, 611, 833, 722, 778,
		667, 778, 722, 667, 611, 722, 667, 944, 667, 667, 611, 333, 278, 333, 584, 556,
		333, 556, 611, 556, 611, 556, 333, 611, 611, 278, 278, 556, 278, 889, 611, 611,
		611, 611, 389, 556, 333, 611, 556, 778, 556, 556, 500, 389, 280, 389, 584,
	);

	/** Accumulated page content stream. */
	private $c = '';

	/** Whether the logo JPEG was readable (drawing + XObject skipped otherwise). */
	private $hasLogo = false;

	/** Next marked-content ID (sequential from 0 — the ParentTree indexes by it). */
	private $nextMcid = 0;

	/**
	 * Ordered structure elements (children of the Document element), each:
	 * ['s' => 'H1'|'H2'|'P'|'Figure', 'k' => [mcids], 'alt' => ?string,
	 *  'bbox' => ?[x0,y0,x1,y1]].
	 */
	private $elems = array();

	/**
	 * Build the report for one year.
	 *
	 * @param int    $year     The report year (already validated to have events).
	 * @param array  $s        Aggregates from EQW_Admin_Page::yearly_stats(): checked,
	 *                         remediated, pages, fixed, avg_before (int|null),
	 *                         avg_after (int|null), months_checked[12],
	 *                         months_remediated[12].
	 * @param string $logoPath Absolute path to the plugin's logowb.jpg.
	 * @param string $siteName Human site name (for the meta line and the document title).
	 * @param string $host     Host the report was generated on.
	 * @param string $userName Display name of the user who generated the report.
	 * @param bool   $scopeAll True when the user sees all site documents, false for
	 *                         own-only scope.
	 *
	 * @return string The complete PDF file bytes.
	 */
	public static function render( $year, array $s, $logoPath, $siteName = '', $host = '', $userName = '', $scopeAll = true ) {
		return ( new self() )->build( (int) $year, $s, (string) $logoPath, (string) $siteName, (string) $host, (string) $userName, (bool) $scopeAll );
	}

	private function build( $year, array $s, $logoPath, $siteName, $host, $userName, $scopeAll ) {
		$jpeg = is_file( $logoPath ) ? (string) @file_get_contents( $logoPath ) : '';
		$dim = $jpeg !== '' ? @getimagesize( $logoPath ) : false;
		// Read the REAL pixel dimensions — /Width and /Height must match the JPEG.
		$imgW = $dim ? (int) $dim[0] : 0;
		$imgH = $dim ? (int) $dim[1] : 0;
		$this->hasLogo = $jpeg !== '' && $imgW > 0 && $imgH > 0;

		$siteName = $this->latin1( $siteName );
		$host = $this->latin1( $host );
		$userName = $this->latin1( $userName );

		$this->drawHeader( $year, $siteName, $host, $userName, $scopeAll );
		$this->drawKpis( $s );
		$this->drawScores( $year, $s );
		$this->drawMonths( $year, $s );
		$this->drawFooter();

		$title = 'PDF Accessibility - Executive Report ' . $year . ( $siteName !== '' ? ' - ' . $siteName : '' );

		// Embedded fonts (accessibility: screen readers / other devices must not
		// depend on locally-installed fonts). Liberation Sans ships with the
		// plugin (SIL OFL, fonts/LICENSE) and is metrically identical to the
		// Helvetica tables used for alignment. Missing files fall back to the
		// non-embedded base-14 pair — the report still renders.
		$fontDir = EQW_PDF_DIR . 'fonts';
		$regTtf = is_file( $fontDir . '/LiberationSans-Regular.ttf' ) ? (string) @file_get_contents( $fontDir . '/LiberationSans-Regular.ttf' ) : '';
		$boldTtf = is_file( $fontDir . '/LiberationSans-Bold.ttf' ) ? (string) @file_get_contents( $fontDir . '/LiberationSans-Bold.ttf' ) : '';
		$embed = $regTtf !== '' && $boldTtf !== '';

		// ---- Object numbering (allocated first, bodies reference by number). --
		$n = 1;
		$catalogN = $n++;
		$pagesN = $n++;
		$pageN = $n++;
		$f1N = $n++;
		$f2N = $n++;
		$fd1N = $fd2N = $ff1N = $ff2N = 0;
		if ( $embed ) {
			$fd1N = $n++;
			$ff1N = $n++;
			$fd2N = $n++;
			$ff2N = $n++;
		}
		$imgN = $this->hasLogo ? $n++ : 0;
		$contentN = $n++;
		$structRootN = $n++;
		$docN = $n++;
		$elemNs = array();
		foreach ( array_keys( $this->elems ) as $i ) {
			$elemNs[ $i ] = $n++;
		}
		$parentTreeN = $n++;
		$infoN = $n++;
		$metaN = $n++;

		$objects = array();
		$objects[ $catalogN ] = '<< /Type /Catalog /Pages ' . $pagesN . ' 0 R /Lang (en-US)'
			. ' /MarkInfo << /Marked true >> /StructTreeRoot ' . $structRootN . ' 0 R'
			. ' /ViewerPreferences << /DisplayDocTitle true >> /Metadata ' . $metaN . ' 0 R >>';
		$objects[ $pagesN ] = '<< /Type /Pages /Kids [' . $pageN . ' 0 R] /Count 1 >>';
		$xobject = $this->hasLogo ? ' /XObject << /Im1 ' . $imgN . ' 0 R >>' : '';
		$objects[ $pageN ] = '<< /Type /Page /Parent ' . $pagesN . ' 0 R /MediaBox [0 0 ' . self::W . ' ' . self::H . ']'
			. ' /Resources << /Font << /F1 ' . $f1N . ' 0 R /F2 ' . $f2N . ' 0 R >>' . $xobject
			. ' /ProcSet [/PDF /Text /ImageC] >> /Contents ' . $contentN . ' 0 R /StructParents 0 /Tabs /S >>';
		if ( $embed ) {
			$faces = array(
				array( $f1N, $fd1N, $ff1N, 'LiberationSans', self::W_REG, '[-544 -303 1302 980]', 88, $regTtf ),
				array( $f2N, $fd2N, $ff2N, 'LiberationSans-Bold', self::W_BOLD, '[-482 -376 1304 1033]', 140, $boldTtf ),
			);
			foreach ( $faces as $face ) {
				list( $fN, $fdN, $ffN, $name, $widths, $bbox, $stemV, $ttf ) = $face;
				$objects[ $fN ] = '<< /Type /Font /Subtype /TrueType /BaseFont /' . $name
					. ' /FirstChar 32 /LastChar 126 /Widths [' . implode( ' ', $widths ) . ']'
					. ' /Encoding /WinAnsiEncoding /FontDescriptor ' . $fdN . ' 0 R >>';
				// Metrics parsed from the shipped TTFs (head/hhea/OS2, 1000/em).
				$objects[ $fdN ] = '<< /Type /FontDescriptor /FontName /' . $name . ' /Flags 32 /FontBBox ' . $bbox
					. ' /ItalicAngle 0 /Ascent 905 /Descent -212 /CapHeight 688 /StemV ' . $stemV
					. ' /MissingWidth 500 /FontFile2 ' . $ffN . ' 0 R >>';
				$flate = function_exists( 'gzcompress' );
				$body = $flate ? gzcompress( $ttf, 9 ) : $ttf;
				$objects[ $ffN ] = '<< /Length1 ' . strlen( $ttf ) . ( $flate ? ' /Filter /FlateDecode' : '' )
					. ' /Length ' . strlen( $body ) . " >>\nstream\n" . $body . "\nendstream";
			}
		} else {
			$objects[ $f1N ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
			$objects[ $f2N ] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
		}
		if ( $this->hasLogo ) {
			$objects[ $imgN ] = '<< /Type /XObject /Subtype /Image /Width ' . $imgW . ' /Height ' . $imgH
				. ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen( $jpeg ) . " >>\nstream\n"
				. $jpeg . "\nendstream";
		}
		$objects[ $contentN ] = '<< /Length ' . strlen( $this->c ) . " >>\nstream\n" . $this->c . "\nendstream";

		// ---- Structure tree. -------------------------------------------------
		$objects[ $structRootN ] = '<< /Type /StructTreeRoot /K ' . $docN . ' 0 R /ParentTree ' . $parentTreeN
			. ' 0 R /ParentTreeNextKey 1 >>';
		$kids = implode( ' ', array_map( static function ( $num ) {
			return $num . ' 0 R';
		}, $elemNs ) );
		$objects[ $docN ] = '<< /Type /StructElem /S /Document /P ' . $structRootN . ' 0 R /K [' . $kids . '] >>';
		$parentByMcid = array();
		foreach ( $this->elems as $i => $e ) {
			$k = count( $e['k'] ) === 1 ? (string) $e['k'][0] : '[' . implode( ' ', $e['k'] ) . ']';
			$body = '<< /Type /StructElem /S /' . $e['s'] . ' /P ' . $docN . ' 0 R /Pg ' . $pageN . ' 0 R /K ' . $k;
			if ( $e['alt'] !== null ) {
				$body .= ' /Alt (' . $this->esc( $e['alt'] ) . ')';
			}
			if ( $e['bbox'] !== null ) {
				$body .= ' /A << /O /Layout /BBox [' . implode( ' ', array_map( array( $this, 'n' ), $e['bbox'] ) ) . '] /Placement /Block >>';
			}
			$objects[ $elemNs[ $i ] ] = $body . ' >>';
			foreach ( $e['k'] as $mcid ) {
				$parentByMcid[ $mcid ] = $elemNs[ $i ];
			}
		}
		// ParentTree: /StructParents 0 -> array indexed by MCID.
		$refs = array();
		for ( $m = 0; $m < $this->nextMcid; $m++ ) {
			$refs[] = ( isset( $parentByMcid[ $m ] ) ? $parentByMcid[ $m ] : $docN ) . ' 0 R';
		}
		$objects[ $parentTreeN ] = '<< /Nums [0 [' . implode( ' ', $refs ) . ']] >>';

		// ---- Document metadata. ----------------------------------------------
		$objects[ $infoN ] = '<< /Title ' . $this->pdfString( $title )
			. ' /Creator (EqualWeb PDF Accessibility for WordPress)'
			. ' /Producer (' . $this->esc( self::PRODUCER ) . ')'
			. ' /CreationDate (D:' . gmdate( 'YmdHis' ) . 'Z) >>';
		$xmp = $this->xmp( $title );
		$objects[ $metaN ] = '<< /Type /Metadata /Subtype /XML /Length ' . strlen( $xmp ) . " >>\nstream\n" . $xmp . "\nendstream";

		// ---- Serialize with byte-exact offsets (strings are byte arrays). ----
		ksort( $objects );
		$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
		$offsets = array();
		foreach ( $objects as $num => $body ) {
			$offsets[ $num ] = strlen( $pdf );
			$pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
		}
		$xref = strlen( $pdf );
		$count = count( $objects ) + 1;
		$pdf .= "xref\n0 " . $count . "\n0000000000 65535 f \n";
		foreach ( $offsets as $off ) {
			$pdf .= sprintf( '%010d', $off ) . " 00000 n \n";
		}
		$id = md5( $title . strlen( $pdf ) . gmdate( 'YmdHis' ) );
		$pdf .= "trailer\n<< /Size " . $count . ' /Root ' . $catalogN . ' 0 R /Info ' . $infoN . ' 0 R'
			. ' /ID [<' . $id . '> <' . $id . ">] >>\nstartxref\n" . $xref . "\n%%EOF";
		return $pdf;
	}

	/* ---------------------------------------------------------------- */
	/* Drawing sections                                                  */
	/* ---------------------------------------------------------------- */

	/** Black band: logo, bold white title, big year; meta line underneath. */
	private function drawHeader( $year, $siteName, $host, $userName, $scopeAll ) {
		$this->beginArtifact();
		$this->rect( 0, self::H - 60, self::W, 60, '000000' );
		$this->endArtifact();

		if ( $this->hasLogo ) {
			// ~46pt square, vertically centered in the 60pt band.
			$m = $this->mc( 'Figure' );
			$this->c .= 'q 46 0 0 46 ' . self::MARGIN . ' ' . ( self::H - 53 ) . " cm /Im1 Do Q\n";
			$this->endMc();
			$this->addElem( 'Figure', array( $m ), 'EqualWeb logo', array( self::MARGIN, self::H - 53, self::MARGIN + 46, self::H - 7 ) );
		}
		$tx = $this->hasLogo ? self::MARGIN + 58 : self::MARGIN;

		// H1 = title + the big year (one heading, two marked-content runs).
		$m1 = $this->mc( 'H1' );
		$this->text( $tx, self::H - 27, 15, true, 'ffffff', 'PDF Accessibility - Executive Report' );
		$this->endMc();
		$m2 = $this->mc( 'H1' );
		$this->textRight( self::W - self::MARGIN, self::H - 42, 30, true, 'ffffff', (string) $year );
		$this->endMc();
		$this->addElem( 'H1', array( $m1, $m2 ) );

		$m = $this->mc( 'P' );
		$this->text( $tx, self::H - 42, 9, false, self::HEADER_SUB, 'Yearly summary of accessibility checks and remediations' );
		$this->endMc();
		$this->addElem( 'P', array( $m ) );

		// Meta line: which site, who generated it, what scope. Auto-shrinks a
		// little when a long site/user name would overflow the content width.
		$scope = $scopeAll ? 'all site documents' : 'documents uploaded by ' . $userName;
		$meta = trim( $siteName . ( $host !== '' ? ' - ' . $host : '' ), ' -' )
			. '  |  Generated by ' . $userName . ' on ' . wp_date( 'Y-m-d' )
			. '  |  Scope: ' . $scope;
		$size = 9.0;
		$maxW = self::W - 2 * self::MARGIN;
		if ( $this->width( $meta, $size, false ) > $maxW ) {
			$size = max( 6.5, $size * $maxW / $this->width( $meta, $size, false ) );
		}
		$m = $this->mc( 'P' );
		$this->text( self::MARGIN, 764, $size, false, self::GRAY, $meta );
		$this->endMc();
		$this->addElem( 'P', array( $m ) );
	}

	/** Row of 4 KPI tiles: big bold number + small caption, plugin tile colors. */
	private function drawKpis( array $s ) {
		$m = $this->mc( 'H2' );
		$this->text( self::MARGIN, 738, 12, true, self::INK, 'Key metrics' );
		$this->endMc();
		$this->addElem( 'H2', array( $m ) );

		$tiles = array(
			array( number_format( (int) $s['checked'] ), 'Documents checked' ),
			array( number_format( (int) $s['remediated'] ), 'Documents remediated' ),
			array( number_format( (int) $s['pages'] ), 'Pages remediated' ),
			array( number_format( (int) $s['fixed'] ), 'Failed checks resolved' ),
		);
		$gap = 12;
		$w = ( self::W - 2 * self::MARGIN - 3 * $gap ) / 4;
		$y = 648;
		foreach ( $tiles as $i => $tileDef ) {
			list( $value, $caption ) = $tileDef;
			$x = self::MARGIN + $i * ( $w + $gap );
			$this->beginArtifact();
			$this->rect( $x, $y, $w, 80, self::TILE_BG, self::TILE_LINE, 1 );
			$this->endArtifact();
			$mv = $this->mc( 'P' );
			$this->textCenter( $x + $w / 2, $y + 44, 22, true, self::INK, $value );
			$this->endMc();
			$mcap = $this->mc( 'P' );
			$this->textCenter( $x + $w / 2, $y + 20, 8, false, self::GRAY, $caption );
			$this->endMc();
			$this->addElem( 'P', array( $mv, $mcap ) );
		}
	}

	/** Average score before (red, gray bar) vs. after (green, green bar), 0-100. */
	private function drawScores( $year, array $s ) {
		$top = 616;
		$m = $this->mc( 'H2' );
		$this->text( self::MARGIN, $top, 12, true, self::INK, 'Average accessibility score' );
		$this->endMc();
		$this->addElem( 'H2', array( $m ) );

		$before = isset( $s['avg_before'] ) ? $s['avg_before'] : null;
		$after = isset( $s['avg_after'] ) ? $s['avg_after'] : null;
		if ( $before === null || $after === null ) {
			$m = $this->mc( 'P' );
			$this->text( self::MARGIN, $top - 20, 9, false, self::GRAY,
				'No documents were remediated in ' . $year . ' - remediate files to track before/after scores.' );
			$this->endMc();
			$this->addElem( 'P', array( $m ) );
			return;
		}
		$before = max( 0, min( 100, (int) $before ) );
		$after = max( 0, min( 100, (int) $after ) );

		$m = $this->mc( 'P' );
		$this->text( self::MARGIN, $top - 15, 8.5, false, self::GRAY,
			'Documents remediated in ' . $year . ': score before vs. after remediation (0-100 scale).' );
		$this->endMc();
		$this->addElem( 'P', array( $m ) );

		$m = $this->mc( 'P' );
		$this->textRight( self::W - self::MARGIN, $top, 10, true, self::GREEN_TEXT,
			'+' . number_format( max( 0, $after - $before ) ) . ' points' );
		$this->endMc();
		$this->addElem( 'P', array( $m ) );

		// The whole two-bar visualization is ONE figure; /Alt carries the data.
		$fig = $this->mc( 'Figure' );
		$tx = 110;
		$tw = self::W - self::MARGIN - $tx - 45;
		$rows = array(
			array( 'Before', $before, self::BAR_GRAY, self::RED, $top - 44 ),
			array( 'After', $after, self::GREEN, self::GREEN_TEXT, $top - 68 ),
		);
		foreach ( $rows as $rowDef ) {
			list( $label, $value, $barColor, $valueColor, $y ) = $rowDef;
			$this->text( self::MARGIN, $y + 3.5, 9, false, self::GRAY, $label );
			$this->rect( $tx, $y, $tw, 14, self::TILE_BG, self::TILE_LINE, 0.8 );
			if ( $value > 0 ) {
				$this->rect( $tx, $y, $tw * $value / 100, 14, $barColor );
			}
			$this->text( $tx + $tw + 8, $y + 3.5, 10, true, $valueColor, $value . '/100' );
		}
		// Scale ticks + labels under the bottom bar.
		$yTick = $top - 68;
		foreach ( array( 0, 25, 50, 75, 100 ) as $t ) {
			$x = $tx + $tw * $t / 100;
			$this->rect( $x - 0.4, $yTick - 5, 0.8, 4, self::TILE_LINE );
			$this->textCenter( $x, $yTick - 15, 7, false, self::GRAY, (string) $t );
		}
		$this->endMc();
		$this->addElem( 'Figure', array( $fig ),
			'Bar chart: average accessibility score before remediation ' . $before
			. ' out of 100, after remediation ' . $after . ' out of 100.',
			array( self::MARGIN - 2, $top - 85, self::W - self::MARGIN + 2, $top - 26 ) );
	}

	/** 12 months x (checks gray | remediations green) vertical bars + initials. */
	private function drawMonths( $year, array $s ) {
		$top = 494;
		$m = $this->mc( 'H2' );
		$this->text( self::MARGIN, $top, 12, true, self::INK, 'Monthly activity' );
		$this->endMc();
		$this->addElem( 'H2', array( $m ) );

		$checks = array_pad( array_values( isset( $s['months_checked'] ) ? $s['months_checked'] : array() ), 12, 0 );
		$rems = array_pad( array_values( isset( $s['months_remediated'] ) ? $s['months_remediated'] : array() ), 12, 0 );

		// Legend + gridlines + bars + initials are ONE figure; the /Alt spells
		// out every month with activity so no data is lost to a reader.
		$fig = $this->mc( 'Figure' );
		$items = array( array( 'Checks', self::BAR_GRAY ), array( 'Remediations', self::GREEN ) );
		$total = 0;
		foreach ( $items as $item ) {
			$total += 8 + 4 + $this->width( $item[0], 8, false ) + 12;
		}
		$x = self::W - self::MARGIN - ( $total - 12 );
		foreach ( $items as $item ) {
			list( $label, $color ) = $item;
			$this->rect( $x, $top - 1, 8, 8, $color );
			$x += 12;
			$this->text( $x, $top, 8, false, self::GRAY, $label );
			$x += $this->width( $label, 8, false ) + 12;
		}

		$base = 338;
		$maxH = 133;
		$max = max( 1, max( $checks ), max( $rems ) );

		// Faint gridlines with count labels at the LABELED value's exact height.
		$gridValues = $max >= 2 ? array( $max, (int) round( $max / 2 ) ) : array( $max );
		foreach ( $gridValues as $value ) {
			$gy = $base + $maxH * $value / $max;
			$this->rect( self::MARGIN, $gy, self::W - 2 * self::MARGIN, 0.5, self::TILE_LINE );
			$this->textRight( self::MARGIN - 5, $gy - 2.5, 7, false, self::GRAY, (string) $value );
		}
		$this->textRight( self::MARGIN - 5, $base - 2, 7, false, self::GRAY, '0' );
		$this->rect( self::MARGIN, $base - 1, self::W - 2 * self::MARGIN, 1, self::TILE_LINE );

		$initials = array( 'J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D' );
		$gw = ( self::W - 2 * self::MARGIN ) / 12;
		for ( $i = 0; $i < 12; $i++ ) {
			$gx = self::MARGIN + $i * $gw;
			$bx = $gx + ( $gw - 29 ) / 2;
			if ( $checks[ $i ] > 0 ) {
				$this->rect( $bx, $base, 13, max( 1.5, $checks[ $i ] / $max * $maxH ), self::BAR_GRAY );
			}
			if ( $rems[ $i ] > 0 ) {
				$this->rect( $bx + 16, $base, 13, max( 1.5, $rems[ $i ] / $max * $maxH ), self::GREEN );
			}
			$this->textCenter( $gx + $gw / 2, $base - 15, 8, false, self::GRAY, $initials[ $i ] );
		}
		$this->endMc();

		$names = array( 'January', 'February', 'March', 'April', 'May', 'June', 'July',
			'August', 'September', 'October', 'November', 'December' );
		$parts = array();
		for ( $i = 0; $i < 12; $i++ ) {
			if ( $checks[ $i ] > 0 || $rems[ $i ] > 0 ) {
				$parts[] = $names[ $i ] . ' - checks: ' . number_format( $checks[ $i ] ) . ', remediations: ' . number_format( $rems[ $i ] );
			}
		}
		$alt = 'Monthly activity chart for ' . $year . ': checks and remediations per month. '
			. ( $parts ? implode( '; ', $parts ) . '.' : 'No activity recorded.' )
			. ' Months without activity are omitted.';
		$this->addElem( 'Figure', array( $fig ), $alt, array( 20, $base - 24, self::W - self::MARGIN + 2, $top + 9 ) );
	}

	/** Thin green rule (artifact) + small gray brand line. */
	private function drawFooter() {
		$this->beginArtifact();
		$this->rect( self::MARGIN, 70, self::W - 2 * self::MARGIN, 2, self::GREEN );
		$this->endArtifact();
		$m = $this->mc( 'P' );
		$this->text( self::MARGIN, 58, 8, false, self::GRAY, 'EqualWeb PDF Accessibility - www.equalweb.com' );
		$this->endMc();
		$this->addElem( 'P', array( $m ) );
	}

	/* ---------------------------------------------------------------- */
	/* Tagging helpers                                                   */
	/* ---------------------------------------------------------------- */

	/** Open a marked-content run; returns its MCID. */
	private function mc( $tag ) {
		$id = $this->nextMcid++;
		$this->c .= '/' . $tag . ' << /MCID ' . $id . " >> BDC\n";
		return $id;
	}

	/** Close the current marked-content run. */
	private function endMc() {
		$this->c .= "EMC\n";
	}

	/** Open a decorative (artifact) run — excluded from the structure tree. */
	private function beginArtifact() {
		$this->c .= "/Artifact BMC\n";
	}

	/** Close the decorative run. */
	private function endArtifact() {
		$this->c .= "EMC\n";
	}

	/** Register a structure element (child of Document, in reading order). */
	private function addElem( $type, array $mcids, $alt = null, $bbox = null ) {
		$this->elems[] = array( 's' => $type, 'k' => $mcids, 'alt' => $alt, 'bbox' => $bbox );
	}

	/* ---------------------------------------------------------------- */
	/* Metadata helpers                                                  */
	/* ---------------------------------------------------------------- */

	/** XMP packet: dc:title, dc:language, PDF/UA identifier. */
	private function xmp( $title ) {
		$t = htmlspecialchars( $title, ENT_QUOTES | ENT_XML1, 'UTF-8' );
		return '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
			. '<x:xmpmeta xmlns:x="adobe:ns:meta/">' . "\n"
			. ' <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
			. '  <rdf:Description rdf:about="" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:pdf="http://ns.adobe.com/pdf/1.3/">' . "\n"
			. '   <dc:title><rdf:Alt><rdf:li xml:lang="x-default">' . $t . '</rdf:li></rdf:Alt></dc:title>' . "\n"
			. '   <dc:language><rdf:Bag><rdf:li>en-US</rdf:li></rdf:Bag></dc:language>' . "\n"
			. '   <dc:format>application/pdf</dc:format>' . "\n"
			. '   <pdf:Producer>' . htmlspecialchars( self::PRODUCER, ENT_QUOTES | ENT_XML1, 'UTF-8' ) . '</pdf:Producer>' . "\n"
			. '  </rdf:Description>' . "\n"
			. '  <rdf:Description rdf:about="" xmlns:pdfuaid="http://www.aiim.org/pdfua/ns/id/">' . "\n"
			. '   <pdfuaid:part>1</pdfuaid:part>' . "\n"
			. '  </rdf:Description>' . "\n"
			. ' </rdf:RDF>' . "\n"
			. '</x:xmpmeta>' . "\n"
			. '<?xpacket end="w"?>';
	}

	/** PDF string: literal for ASCII, UTF-16BE hex string otherwise. */
	private function pdfString( $s ) {
		if ( ! preg_match( '/[^\x20-\x7E]/', $s ) ) {
			return '(' . $this->esc( $s ) . ')';
		}
		if ( function_exists( 'mb_convert_encoding' ) ) {
			return '<' . strtoupper( bin2hex( "\xFE\xFF" . mb_convert_encoding( $s, 'UTF-16BE', 'UTF-8' ) ) ) . '>';
		}
		return '(' . $this->esc( preg_replace( '/[^\x20-\x7E]/', '?', $s ) ) . ')';
	}

	/** Best-effort ASCII transliteration for drawable text (WinAnsi font). */
	private function latin1( $s ) {
		if ( ! preg_match( '/[^\x20-\x7E]/', $s ) ) {
			return $s;
		}
		if ( function_exists( 'iconv' ) ) {
			$out = @iconv( 'UTF-8', 'ASCII//TRANSLIT//IGNORE', $s );
			if ( $out !== false && $out !== '' ) {
				return preg_replace( '/[^\x20-\x7E]/', '?', $out );
			}
		}
		return preg_replace( '/[^\x20-\x7E]/', '?', $s );
	}

	/* ---------------------------------------------------------------- */
	/* Content-stream helpers                                            */
	/* ---------------------------------------------------------------- */

	/** Escape \ ( ) for a PDF literal string. */
	private function esc( $t ) {
		return str_replace( array( '\\', '(', ')' ), array( '\\\\', '\\(', '\\)' ), $t );
	}

	/** Compact decimal for the content stream (no trailing zeros, dot-decimal). */
	private function n( $v ) {
		$s = rtrim( rtrim( sprintf( '%.3F', $v ), '0' ), '.' );
		return $s === '-0' ? '0' : $s;
	}

	/** 'rrggbb' hex -> "r g b" components scaled 0..1. */
	private function col( $hex ) {
		return $this->n( hexdec( substr( $hex, 0, 2 ) ) / 255 ) . ' '
			. $this->n( hexdec( substr( $hex, 2, 2 ) ) / 255 ) . ' '
			. $this->n( hexdec( substr( $hex, 4, 2 ) ) / 255 );
	}

	/** Filled and/or stroked rectangle. */
	private function rect( $x, $y, $w, $h, $fill, $stroke = null, $lineWidth = 1.0 ) {
		$ops = '';
		if ( $fill !== null ) {
			$ops .= $this->col( $fill ) . ' rg ';
		}
		if ( $stroke !== null ) {
			$ops .= $this->col( $stroke ) . ' RG ' . $this->n( $lineWidth ) . ' w ';
		}
		$paint = $fill !== null && $stroke !== null ? 'B' : ( $fill !== null ? 'f' : 'S' );
		$this->c .= $ops . $this->n( $x ) . ' ' . $this->n( $y ) . ' ' . $this->n( $w ) . ' ' . $this->n( $h ) . ' re ' . $paint . "\n";
	}

	/** Single line of text at a baseline position. */
	private function text( $x, $y, $size, $bold, $rgb, $str ) {
		$this->c .= 'BT /' . ( $bold ? 'F2' : 'F1' ) . ' ' . $this->n( $size ) . ' Tf ' . $this->col( $rgb ) . ' rg '
			. $this->n( $x ) . ' ' . $this->n( $y ) . ' Td (' . $this->esc( $str ) . ') Tj ET' . "\n";
	}

	/** Text centered on $cx. */
	private function textCenter( $cx, $y, $size, $bold, $rgb, $str ) {
		$this->text( $cx - $this->width( $str, $size, $bold ) / 2, $y, $size, $bold, $rgb, $str );
	}

	/** Text right-aligned to $rx. */
	private function textRight( $rx, $y, $size, $bold, $rgb, $str ) {
		$this->text( $rx - $this->width( $str, $size, $bold ), $y, $size, $bold, $rgb, $str );
	}

	/** Rendered width in points (AFM widths; non-ASCII falls back to 556). */
	private function width( $str, $size, $bold ) {
		$table = $bold ? self::W_BOLD : self::W_REG;
		$units = 0;
		for ( $i = 0, $len = strlen( $str ); $i < $len; $i++ ) {
			$o = ord( $str[ $i ] );
			$units += ( $o >= 32 && $o <= 126 ) ? $table[ $o - 32 ] : 556;
		}
		return $units * $size / 1000;
	}

}
