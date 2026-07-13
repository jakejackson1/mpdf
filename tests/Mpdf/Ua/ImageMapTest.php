<?php

namespace Mpdf\Ua;

/**
 * HTML image-map (<img usemap> + <map> + <area>) PDF/UA-1 tagging tests.
 *
 * Exercises:
 *   - Tag parsing for <map> and <area>, populating the registry on $mpdf.
 *   - Coord-shape conversion (rect/circle/poly/default) into PDF user units.
 *   - Link-annotation byte-level emission with /Subtype /Link, /Rect, /Contents.
 *   - Link struct element with /Alt and OBJR kid pointing at the annotation.
 *   - Strict vs auto mode policy for <area> missing alt (Matterhorn 28-002).
 *   - Internal #fragment vs external URI href routing.
 *   - Defensive cases: missing href, missing map, decorative image.
 *
 * Spec:
 *   - ISO 32000-1:2008 §12.5.6.5 — Link annotation /Rect /A /Contents.
 *   - ISO 32000-1:2008 §14.7.4.4.2 Table 338 — OBJR kid.
 *   - ISO 32000-1:2008 §14.8 Table 335 — Link inline-level structure element.
 *   - ISO 14289-1:2014 §7.18 — interactive content tagging.
 *   - Matterhorn Protocol 1.1 28-002 — Link annotation lacking text alternative.
 *   - HTML5 §4.8.13 (map) and §4.8.14 (area).
 *
 * @group pdfua
 */
class ImageMapTest extends PdfUaTestCase
{

	/**
	 * Common 1x1 red-pixel PNG data URI used as a stand-in for a real image
	 * (no filesystem dependency required by the test suite).
	 *
	 * @var string
	 */
	private $redPixelPng = 'data:image/png;base64,'
		. 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8'
		. 'z8BQDwADhQGAWjR9awAAAABJRU5ErkJggg==';

	private function imgUseMap($alt = 'Floor plan', $usemap = '#rooms', $extra = '')
	{
		return '<img src="' . $this->redPixelPng . '" alt="' . $alt . '" usemap="' . $usemap
			. '" width="200" height="200"' . ($extra ? ' ' . $extra : '') . '>';
	}

	/**
	 * <map> / <area> populate the ImageMapRegistry even when no <img> uses
	 * them.
	 *
	 * The registry is global to the document so an <img usemap> can reference
	 * a <map> declared either before or after it in source order (HTML5 §4.8.13).
	 */
	public function testMapAndAreaPopulateRegistry()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML(
			'<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="/lobby" alt="Lobby">'
			. '<area shape="circle" coords="200,200,40" href="/atrium" alt="Atrium">'
			. '</map>'
		);
		$registry = $mpdf->getPdfUaImageMapRegistry();
		$maps     = $registry->getMaps();
		$this->assertArrayHasKey('rooms', $maps);
		$this->assertCount(2, $maps['rooms']);
		$this->assertSame('rect', $maps['rooms'][0]['shape']);
		$this->assertSame('Lobby', $maps['rooms'][0]['alt']);
		$this->assertSame([10.0, 10.0, 100.0, 100.0], $maps['rooms'][0]['coords']);
		$this->assertSame('circle', $maps['rooms'][1]['shape']);
		$this->assertSame([200.0, 200.0, 40.0], $maps['rooms'][1]['coords']);
		$this->assertNull($registry->getCurrentMapName());
	}

	/**
	 * Map names are normalised to lowercase so case-insensitive HTML lookups work.
	 */
	public function testMapNameIsLowercased()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML(
			'<map name="Rooms"><area shape="rect" coords="0,0,10,10" href="/x" alt="x"></map>'
		);
		$this->assertArrayHasKey('rooms', $mpdf->getPdfUaImageMapRegistry()->getMaps());
	}

	/**
	 * A <area shape="rect"> emits a /Subtype /Link annotation pointing at the
	 * area's href. The annotation's /Contents carries the URL (mPDF's existing
	 * convention for link annotations); Matterhorn 28-002 is satisfied by
	 * /Alt on the surrounding Link struct element (asserted in
	 * testRectAreaProducesLinkStructWithAlt below).
	 */
	public function testRectAreaProducesLinkAnnotationWithAlt()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
		);
		$this->assertStringContainsString('/Subtype /Link', $pdf);
		// /A <</S /URI /URI (https://example.com/lobby)>> on the Link annotation.
		$this->assertStringContainsString('/URI (https://example.com/lobby)', $pdf);
	}

	/**
	 * The Link struct element carries /Alt populated from the area's alt
	 * attribute (Matterhorn 28-002).
	 */
	public function testRectAreaProducesLinkStructWithAlt()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
		);
		$this->assertStringContainsString('/S /Link', $pdf);
		// /Alt on the Link struct element (utf16-be encoded).
		$this->assertStringContainsString('feff004c006f00620062007929', bin2hex($pdf));
	}

	/**
	 * The Link struct element has an OBJR kid pointing back at the link
	 * annotation object (ISO 32000-1 §14.7.4.4.2 Table 338, Matterhorn 02-003).
	 */
	public function testLinkStructElementHasObjrKid()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
		);
		// OBJR appears as part of the Link struct's /K array.
		$this->assertMatchesRegularExpression('#/Type\s*/OBJR\s*/Obj\s+\d+\s+0\s+R#', $pdf);
	}

	/**
	 * The link annotation carries /StructParent (singular) integer associating
	 * it with the Link struct element via the ParentTree.
	 */
	public function testLinkAnnotationHasStructParent()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
		);
		// /StructParent on a /Subtype /Link annotation.
		$this->assertMatchesRegularExpression(
			'#/Subtype\s*/Link[\s\S]+?/StructParent\s+\d+#',
			$pdf
		);
	}

	/**
	 * <area shape="circle" coords="cx,cy,r"> emits a Link annotation whose
	 * /Rect is the bounding box of the disc.
	 */
	public function testCircleAreaProducesLinkAnnotation()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="circle" coords="100,100,40" href="/atrium" alt="Atrium">'
			. '</map>'
		);
		$this->assertStringContainsString('/Subtype /Link', $pdf);
		// "Atrium" /Alt on the Link struct element (utf16-be).
		// A=41 t=74 r=72 i=69 u=75 m=6D
		$this->assertStringContainsString('feff00410074007200690075006d', bin2hex($pdf));
	}

	/**
	 * <area shape="poly" coords="…"> emits a Link annotation with the polygon's
	 * bounding box as /Rect (PDF Link annotations are axis-aligned only).
	 */
	public function testPolyAreaProducesLinkAnnotation()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="poly" coords="100,50,150,150,50,150" href="/garden" alt="Garden">'
			. '</map>'
		);
		$this->assertStringContainsString('/Subtype /Link', $pdf);
	}

	/**
	 * <area shape="default"> (no coords) auto-claims the entire image rectangle.
	 */
	public function testDefaultShapeProducesLinkAnnotation()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="default" href="/whole" alt="Whole image">'
			. '</map>'
		);
		$this->assertStringContainsString('/Subtype /Link', $pdf);
	}

	/**
	 * <area href="#fragment"> produces an internal /Dest entry, not a /URI action.
	 */
	public function testInternalHrefProducesGoTo()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<h1 id="section2">Section 2</h1>'
			. '<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="0,0,50,50" href="#section2" alt="Jump to section 2">'
			. '</map>'
		);
		$this->assertMatchesRegularExpression('#/Subtype\s*/Link[\s\S]+?/Dest\s*\[#', $pdf);
		// Should NOT be a URI action.
		$this->assertStringNotContainsString('/A <</S /URI /URI (#section2)', $pdf);
	}

	/**
	 * <area href="https://…"> produces a /URI action.
	 */
	public function testExternalHrefProducesUriAction()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="0,0,50,50" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
		);
		$this->assertStringContainsString('/A <</S /URI /URI (https://example.com/lobby)', $pdf);
	}

	/**
	 * Strict mode (PDFUAauto=false): <area> missing alt with non-empty href
	 * throws MpdfException citing Matterhorn 28-002.
	 */
	public function testStrictModeAreaWithoutAltThrows()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);
		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessage('28-002');
		$mpdf->WriteHTML(
			'<map name="rooms">'
			. '<area shape="rect" coords="0,0,50,50" href="https://example.com/lobby">'
			. '</map>'
		);
	}

	/**
	 * Auto mode (PDFUAauto=true): <area> missing alt synthesises "Link to <href>"
	 * onto the Link struct element and records a warning.
	 */
	public function testAutoModeAreaWithoutAltSynthesisesAlt()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="https://example.com/lobby">'
			. '</map>'
		);
		$this->assertStringContainsString('/Subtype /Link', $pdf);
		// Synthesised alt = "Link to https://example.com/lobby"; encode "Link to" prefix.
		// L=4c i=69 n=6e k=6b (space)=20 t=74 o=6f → 004c 0069 006e 006b 0020 0074 006f
		$this->assertStringContainsString(
			'feff004c0069006e006b00200074006f',
			bin2hex($pdf)
		);
		// Warning recorded.
		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (strpos($w, 'synthesised') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Expected warning about synthesised alt was not recorded');
	}

	/**
	 * <area> without href (markup placeholder) is warn-and-skipped — no Link
	 * annotation is emitted because there is no clickable region to tag.
	 */
	public function testAreaWithoutHrefIsSkipped()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" alt="Inert">'
			. '</map>'
		);
		// The rendered content has no Link annotation (the surrounding test
		// document has none either).
		$this->assertStringNotContainsString('/Subtype /Link', $pdf);
	}

	/**
	 * Auto mode (PDFUAauto=true): <img usemap="#unknown"> with no matching <map>
	 * warns and renders the image without any Link annotations (audit E19 — the
	 * registry now honours the same strict/auto policy as the tag handlers).
	 */
	public function testImgUsemapWithNoMatchingMapWarns()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap('Plan', '#missing') . '</p>'
		);
		$this->assertStringNotContainsString('/Subtype /Link', $pdf);
		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (strpos($w, 'unknown map') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Expected unknown-map warning was not recorded');
	}

	/**
	 * Strict mode (PDFUAauto=false): <img usemap="#missing"> referencing an
	 * unknown <map> throws MpdfException naming the missing map rather than
	 * silently emitting no link annotations (audit E19). Before the fix the
	 * registry warned unconditionally, so strict mode never threw and the
	 * branch's strict/auto contract was violated.
	 */
	public function testStrictModeImgUsemapUnknownMapThrows()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => false]);
		$this->expectException(\Mpdf\MpdfException::class);
		$this->expectExceptionMessage('unknown map "missing"');
		$this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap('Plan', '#missing') . '</p>'
		);
	}

	/**
	 * Image map on a decorative image (<img alt="">) is ignored — markup
	 * contradiction (clickable region implies meaningful content).
	 */
	public function testImageMapOnDecorativeImageIsSkipped()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p><img src="' . $this->redPixelPng . '" alt="" usemap="#rooms" width="200" height="200"></p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="/lobby" alt="Lobby">'
			. '</map>'
		);
		$this->assertStringNotContainsString('/Subtype /Link', $pdf);
		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (strpos($w, 'decorative image') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Expected decorative-image warning was not recorded');
	}

	/**
	 * Two <img> can reference the same <map> — each gets its own Link set
	 * (positions differ because the images are placed at different points).
	 */
	public function testMultipleImagesShareOneMap()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap('First', '#shared') . '</p>'
			. '<p>' . $this->imgUseMap('Second', '#shared') . '</p>'
			. '<map name="shared">'
			. '<area shape="rect" coords="10,10,50,50" href="https://example.com/a" alt="A">'
			. '<area shape="rect" coords="60,60,100,100" href="https://example.com/b" alt="B">'
			. '</map>'
		);
		// Two images × two areas = four Link annotations.
		$count = preg_match_all('#/Subtype /Link#', $pdf);
		$this->assertSame(4, $count);
	}

	/**
	 * <area> outside any <map> produces a warning and is ignored entirely
	 * (no parser explosion, no registry entry).
	 */
	public function testAreaOutsideMapIsIgnored()
	{
		$mpdf = $this->makeMpdf();
		$mpdf->WriteHTML(
			'<area shape="rect" coords="0,0,10,10" href="/x" alt="x">'
		);
		$this->assertSame([], $mpdf->getPdfUaImageMapRegistry()->getMaps());
		$warnings = $mpdf->getPdfUaWarnings();
		$found = false;
		foreach ($warnings as $w) {
			if (strpos($w, '<area> outside') !== false) {
				$found = true;
				break;
			}
		}
		$this->assertTrue($found, 'Expected <area> outside warning');
	}

	/**
	 * Image-map hotspots must use the height of the page the host image is on,
	 * not the height of the final page. The deferred queue is drained at the end
	 * of WriteHTML(), when $mpdf->hPt holds the last page's height; Mpdf::Link()
	 * flips y with that live hPt. If the host image is on a portrait page but the
	 * document ends on a landscape page, the hotspot lands ~246pt off.
	 *
	 * Here the <img usemap> is on a portrait A4 page (hPt ~841.89), then a
	 * landscape page (hPt ~595.28) follows. The top-of-image hotspot's /Rect
	 * yTop must be well above the landscape height — only possible if the y-flip
	 * used the portrait page height captured at queue time.
	 */
	public function testHotspotUsesHostPageHeightAcrossOrientations()
	{
		$mpdf = $this->makeMpdf(['format' => 'A4']);
		$pdf = $this->getOutput(
			$mpdf,
			'<h1>Plan</h1>'
			. '<img src="' . $this->redPixelPng . '" usemap="#rooms" width="200" height="100" alt="Floor plan">'
			. '<map name="rooms">'
			. '<area shape="rect" coords="0,0,100,50" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
			. '<pagebreak orientation="L" />'
			. '<p>Landscape page.</p>'
		);

		$this->assertStringContainsString('/Subtype /Link', $pdf);

		$y1 = null;
		if (preg_match_all('/<<([^>]*\/Subtype \/Link[^>]*)>>/s', $pdf, $objs)) {
			foreach ($objs[1] as $obj) {
				if (preg_match('/\/Rect \[\s*[\d.\-]+\s+([\d.\-]+)/', $obj, $r)) {
					$y1 = (float) $r[1];
					break;
				}
			}
		}

		$this->assertNotNull($y1, 'expected a Link annotation with a /Rect');
		// A4 landscape height is 595.28pt. A hotspot at the top of an image on a
		// portrait page must flip to a y well above that; a value at or below it
		// means Link() used the final (landscape) page height.
		$this->assertGreaterThan(
			595.28,
			$y1,
			'image-map hotspot /Rect yTop must be flipped with the host (portrait) page height'
		);
	}

	/**
	 * A rotated host image emits its hotspots as /QuadPoints (a non-axis-aligned
	 * region) rather than the old warn-and-skip. The Link is tagged and no
	 * "not supported" warning is recorded. ISO 32000-1 §12.5.6.5.
	 */
	public function testRotatedImageMapEmitsQuadPoints()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap('Floor plan', '#rooms', 'rotate="90"') . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
		);
		$this->assertStringContainsString('/Subtype /Link', $pdf);
		$this->assertStringContainsString('/QuadPoints [', $pdf);
		$this->assertStringContainsString('/S /Link', $pdf);
		$this->assertStringNotContainsString('not supported', implode(' ', $mpdf->getPdfUaWarnings()));
	}

	/**
	 * An axis-aligned host image keeps the plain /Rect hotspot with no
	 * /QuadPoints — the rotated path must not change unrotated output.
	 */
	public function testAxisAlignedImageMapHasNoQuadPoints()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p>' . $this->imgUseMap() . '</p>'
			. '<map name="rooms">'
			. '<area shape="rect" coords="10,10,100,100" href="https://example.com/lobby" alt="Lobby">'
			. '</map>'
		);
		$this->assertStringContainsString('/Subtype /Link', $pdf);
		$this->assertStringNotContainsString('/QuadPoints', $pdf);
	}

	/**
	 * The emitted /QuadPoints of a full-image hotspot must land exactly on the
	 * four corners of the image as mPDF renders it. This composes the actual
	 * placement matrices from the content stream independently of the production
	 * code and compares — proving the hotspot tracks the rotation, which veraPDF
	 * (structure-only) cannot verify.
	 */
	public function testRotatedImageMapQuadAlignsWithRenderedImage()
	{
		$mpdf = $this->makeMpdf();
		$pdf = $this->getOutput(
			$mpdf,
			'<p><img src="' . $this->redPixelPng . '" alt="Photo" usemap="#m" width="120" height="60" rotate="90"></p>'
			. '<map name="m"><area shape="default" href="https://example.com/full" alt="Whole"></map>'
		);

		// Compose the actual CTM from every cm operator preceding "/I{ID} Do".
		$this->assertSame(1, preg_match('/\/I(\d+) Do/', $pdf, $idm));
		$doPos = strpos($pdf, '/I' . $idm[1] . ' Do');
		$seg   = substr($pdf, strrpos(substr($pdf, 0, $doPos), 'q'), $doPos);
		preg_match_all(
			'/(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+(-?\d+(?:\.\d+)?)\s+cm/',
			$seg,
			$cms,
			PREG_SET_ORDER
		);
		$ctm = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
		foreach ($cms as $c) {
			$ctm = $this->matmul(
				[(float) $c[1], (float) $c[2], (float) $c[3], (float) $c[4], (float) $c[5], (float) $c[6]],
				$ctm
			);
		}
		// Image unit-square corners in the same order emitForImage walks the
		// pixel corners: (0,0)->(0,1), (W,0)->(1,1), (W,H)->(1,0), (0,H)->(0,0).
		$expected = array_merge(
			$this->applyMatrix($ctm, 0.0, 1.0),
			$this->applyMatrix($ctm, 1.0, 1.0),
			$this->applyMatrix($ctm, 1.0, 0.0),
			$this->applyMatrix($ctm, 0.0, 0.0)
		);

		$this->assertSame(1, preg_match('/\/QuadPoints \[([^\]]+)\]/', $pdf, $qm));
		$quad = array_map('floatval', preg_split('/\s+/', trim($qm[1])));

		$this->assertCount(8, $quad);
		for ($i = 0; $i < 8; $i++) {
			$this->assertEqualsWithDelta($expected[$i], $quad[$i], 0.05, "quad coord $i");
		}
	}

	/**
	 * Multiply two affine matrices [a b c d e f] (row-vector convention).
	 *
	 * @param  float[] $a
	 * @param  float[] $b
	 * @return float[]
	 */
	private function matmul(array $a, array $b)
	{
		return [
			$a[0] * $b[0] + $a[1] * $b[2],
			$a[0] * $b[1] + $a[1] * $b[3],
			$a[2] * $b[0] + $a[3] * $b[2],
			$a[2] * $b[1] + $a[3] * $b[3],
			$a[4] * $b[0] + $a[5] * $b[2] + $b[4],
			$a[4] * $b[1] + $a[5] * $b[3] + $b[5],
		];
	}

	/**
	 * @param  float[] $m
	 * @param  float   $x
	 * @param  float   $y
	 * @return float[]  [x', y']
	 */
	private function applyMatrix(array $m, $x, $y)
	{
		return [$x * $m[0] + $y * $m[2] + $m[4], $x * $m[1] + $y * $m[3] + $m[5]];
	}
}
