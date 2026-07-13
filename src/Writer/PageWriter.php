<?php

namespace Mpdf\Writer;

use Mpdf\Strict;
use Mpdf\Mpdf;
use Mpdf\Form;

final class PageWriter
{

	use Strict;

	/**
	 * @var \Mpdf\Mpdf
	 */
	private $mpdf;

	/**
	 * @var \Mpdf\Form
	 */
	private $form;

	/**
	 * @var \Mpdf\Writer\BaseWriter
	 */
	private $writer;

	/**
	 * @var \Mpdf\Writer\MetadataWriter
	 */
	private $metadataWriter;

	public function __construct(Mpdf $mpdf, Form $form, BaseWriter $writer, MetadataWriter $metadataWriter)
	{
		$this->mpdf = $mpdf;
		$this->form = $form;
		$this->writer = $writer;
		$this->metadataWriter = $metadataWriter;
	}

	public function writePages() // _putpages
	{
		$nb = $this->mpdf->page;

		// PDF/X: drop prohibited annotations (file attachments) and any annotation whose
		// /Rect intersects the printable area (BleedBox, or TrimBox where there is no bleed)
		// before they are counted below, so annotation object numbering stays consistent.
		$this->filterPdfxAnnotations();

		// PDF/X (all parts) prohibits interactive form fields and the /AcroForm dictionary.
		// Drop any active-forms fields before they are counted/numbered below, so no
		// /Widget annotation or AcroForm is emitted.
		$this->form->stripActiveFormsForPdfx();

		$filter = $this->mpdf->compress ? '/Filter /FlateDecode ' : '';

		if ($this->mpdf->DefOrientation === 'P') {
			$defwPt = $this->mpdf->fwPt;
			$defhPt = $this->mpdf->fhPt;
		} else {
			$defwPt = $this->mpdf->fhPt;
			$defhPt = $this->mpdf->fwPt;
		}

		$annotid = (3 + 2 * $nb);

		// Active Forms
		$totaladdnum = 0;
		for ($n = 1; $n <= $nb; $n++) {
			if (isset($this->mpdf->PageLinks[$n])) {
				$totaladdnum += count($this->mpdf->PageLinks[$n]);
			}

			/* -- ANNOTATIONS -- */
			if (isset($this->mpdf->PageAnnots[$n])) {
				foreach ($this->mpdf->PageAnnots[$n] as $k => $pl) {
					if (!empty($pl['opt']['popup']) || !empty($pl['opt']['file'])) {
						$totaladdnum += 2;
					} else {
						$totaladdnum++;
					}
				}
			}
			/* -- END ANNOTATIONS -- */

			/* -- FORMS -- */
			if (count($this->form->forms) > 0) {
				$this->form->countPageForms($n, $totaladdnum);
			}
			/* -- END FORMS -- */
		}

		/* -- FORMS -- */
		// Make a note in the radio button group of the obj_id it will have
		$ctr = 0;
		if (count($this->form->form_radio_groups)) {
			foreach ($this->form->form_radio_groups as $name => $frg) {
				$this->form->form_radio_groups[$name]['obj_id'] = $annotid + $totaladdnum + $ctr;
				$ctr++;
			}
		}
		/* -- END FORMS -- */

		// PDF/X-4: reserve the object id for the ICC-based transparency-group blending
		// colour space. mPDF numbers the page/content objects 3..(2 + 2 * nb), then the
		// annotation/form-field objects, then the radio-button groups; the next free
		// object number is therefore ($annotid + $totaladdnum + $ctr) at this point
		// ($annotid is still the base 3 + 2 * nb here). The colour space is written by
		// writeTransparencyGroupColorSpace() immediately after writePages(), so reserving
		// its id here lets the page /Group dictionaries below forward-reference it (PDF
		// permits forward references) without disturbing the hardcoded page numbering.
		if ($this->mpdf->pdfxAllowsTransparency()) {
			$this->mpdf->transparencyGroupCsObjId = $annotid + $totaladdnum + $ctr;
		}

		// Select unused fonts (usually default font)
		$unused = [];
		foreach ($this->mpdf->fonts as $fk => $font) {
			if (isset($font['type']) && $font['type'] === 'TTF' && !$font['used']) {
				$unused[] = $fk;
			}
		}

		for ($n = 1; $n <= $nb; $n++) {

			$thispage = $this->mpdf->pages[$n];

			// Remove references to unused fonts (usually default font)
			foreach ($unused as $fk) {
				if ($this->mpdf->fonts[$fk]['sip'] || $this->mpdf->fonts[$fk]['smp']) {
					foreach ($this->mpdf->fonts[$fk]['subsetfontids'] as $k => $fid) {
						$thispage = preg_replace('/\s\/F' . $fid . ' \d[\d.]* Tf\s/is', ' ', $thispage);
					}
				} else {
					$thispage = preg_replace('/\s\/F' . $this->mpdf->fonts[$fk]['i'] . ' \d[\d.]* Tf\s/is', ' ', $thispage);
				}
			}

			// Clean up repeated /GS1 gs statements
			// For some reason using + for repetition instead of {2,20} crashes PHP Script Interpreter ???
			$thispage = preg_replace('/(\/GS1 gs\n){2,20}/', "/GS1 gs\n", $thispage);

			$thispage = preg_replace('/(\s*___BACKGROUND___PATTERNS' . $this->mpdf->uniqstr . '\s*)/', ' ', $thispage);
			$thispage = preg_replace('/(\s*___HEADER___MARKER' . $this->mpdf->uniqstr . '\s*)/', ' ', $thispage);
			$thispage = preg_replace('/(\s*___PAGE___START' . $this->mpdf->uniqstr . '\s*)/', ' ', $thispage);
			$thispage = preg_replace('/(\s*___TABLE___BACKGROUNDS' . $this->mpdf->uniqstr . '\s*)/', ' ', $thispage);

			// mPDF 5.7.3 TRANSFORMS
			while (preg_match('/(\% BTR(.*?)\% ETR)/is', $thispage, $m)) {
				$thispage = preg_replace('/(\% BTR.*?\% ETR)/is', '', $thispage, 1) . "\n" . $m[2];
			}

			// Page
			$this->writer->object();
			$this->writer->write('<</Type /Page');
			$this->writer->write('/Parent 1 0 R');

			// Single source of truth for the MediaBox/BleedBox/TrimBox geometry, shared
			// with the PDF/X annotation exclusion box (see getPageBoxesPt()).
			$boxes = $this->getPageBoxesPt($n);

			$this->writer->write(sprintf('/MediaBox [0 0 %.3F %.3F]', $boxes['media'][0], $boxes['media'][1]));

			// If a BleedBox is defined, it must be larger than the TrimBox, but smaller than the MediaBox
			if ($boxes['bleed'] !== null) {
				$this->writer->write(sprintf('/BleedBox [%.3F %.3F %.3F %.3F]', $boxes['bleed'][0], $boxes['bleed'][1], $boxes['bleed'][2], $boxes['bleed'][3]));
			}

			$this->writer->write(sprintf('/TrimBox [%.3F %.3F %.3F %.3F]', $boxes['trim'][0], $boxes['trim'][1], $boxes['trim'][2], $boxes['trim'][3]));

			if (isset($this->mpdf->OrientationChanges[$n]) && $this->mpdf->displayDefaultOrientation) {
				if ($this->mpdf->DefOrientation === 'P') {
					$this->writer->write('/Rotate 270');
				} else {
					$this->writer->write('/Rotate 90');
				}
			}

			$this->writer->write('/Resources 2 0 R');

			// Important to keep in RGB colorSpace when using transparency
			if (!$this->mpdf->PDFA && !$this->mpdf->PDFX) {
				if ($this->mpdf->restrictColorSpace === 3) {
					$this->writer->write('/Group << /Type /Group /S /Transparency /CS /DeviceCMYK >> ');
				} elseif ($this->mpdf->restrictColorSpace === 1) {
					$this->writer->write('/Group << /Type /Group /S /Transparency /CS /DeviceGray >> ');
				} else {
					$this->writer->write('/Group << /Type /Group /S /Transparency /CS /DeviceRGB >> ');
				}
			} elseif ($this->mpdf->pdfxAllowsTransparency()) {
				// PDF/X-4 permits transparency, but the page blending space must be
				// device-independent; use the ICC-based space backing the output intent.
				if ($this->mpdf->transparencyGroupCsObjId) {
					$this->writer->write('/Group << /Type /Group /S /Transparency /CS [/ICCBased ' . $this->mpdf->transparencyGroupCsObjId . ' 0 R] >> ');
				} else {
					$this->writer->write('/Group << /Type /Group /S /Transparency /CS /DeviceCMYK >> ');
				}
			}

			$annotsnum = 0;
			$embeddedfiles = []; // mPDF 5.7.2 /EmbeddedFiles

			if (isset($this->mpdf->PageLinks[$n])) {
				$annotsnum += count($this->mpdf->PageLinks[$n]);
			}

			if (isset($this->mpdf->PageAnnots[$n])) {
				foreach ($this->mpdf->PageAnnots[$n] as $k => $pl) {
					if (!empty($pl['opt']['file'])) {
						$embeddedfiles[$annotsnum + 1] = true;
					} // mPDF 5.7.2 /EmbeddedFiles
					if (!empty($pl['opt']['popup']) || !empty($pl['opt']['file'])) {
						$annotsnum += 2;
					} else {
						$annotsnum++;
					}
					$this->mpdf->PageAnnots[$n][$k]['pageobj'] = $this->mpdf->n;
				}
			}

			// Active Forms
			$formsnum = 0;
			if (count($this->form->forms) > 0) {
				foreach ($this->form->forms as $val) {
					if ($val['page'] == $n) {
						$formsnum++;
					}
				}
			}

			if ($annotsnum || $formsnum) {

				$s = '/Annots [ ';

				for ($i = 0; $i < $annotsnum; $i++) {
					if (!isset($embeddedfiles[$i])) {
						$s .= ($annotid + $i) . ' 0 R ';
					} // mPDF 5.7.2 /EmbeddedFiles
				}

				$annotid += $annotsnum;

				/* -- FORMS -- */
				if (count($this->form->forms) > 0) {
					$this->form->addFormIds($n, $s, $annotid);
				}
				/* -- END FORMS -- */

				$s .= '] ';
				$this->writer->write($s);
			}

			$this->writer->write('/Contents ' . ($this->mpdf->n + 1) . ' 0 R>>');
			$this->writer->write('endobj');

			// Page content
			$this->writer->object();
			$p = $this->mpdf->compress ? gzcompress($thispage) : $thispage;
			$this->writer->write('<<' . $filter . '/Length ' . strlen($p) . '>>');
			$this->writer->stream($p);
			$this->writer->write('endobj');
		}

		$this->metadataWriter->writeAnnotations(); // mPDF 5.7.2

		// Pages root
		$this->mpdf->offsets[1] = $this->mpdf->buffer->getLength();
		$this->writer->write('1 0 obj');
		$this->writer->write('<</Type /Pages');

		$kids = '/Kids [';

		for ($i = 0; $i < $nb; $i++) {
			$kids .= (3 + 2 * $i) . ' 0 R ';
		}

		$this->writer->write($kids . ']');
		$this->writer->write('/Count ' . $nb);
		$this->writer->write(sprintf('/MediaBox [0 0 %.3F %.3F]', $defwPt, $defhPt));
		$this->writer->write('>>');
		$this->writer->write('endobj');
	}

	/**
	 * PDF/X (all parts) requires every annotation to lie wholly outside the BleedBox (or
	 * TrimBox where there is no bleed) and forbids the /FileAttachment, /Sound, /Movie and
	 * /Screen subtypes. mPDF can emit /Link, /Text, /FileAttachment, /Popup and (for active
	 * forms) /Widget annotations; the interactive /Widget annotations and the /AcroForm are
	 * removed separately by Form::stripActiveFormsForPdfx(). This method removes any file
	 * attachment and any link/text annotation whose /Rect intersects the printable area from
	 * the source arrays before writePages() counts them (keeping the /Annots object
	 * references consistent). Strict mode records a warning that makes Output() throw; auto
	 * mode drops the annotation silently.
	 *
	 * @return void
	 */
	private function filterPdfxAnnotations()
	{
		if (!$this->mpdf->PDFX) {
			return;
		}

		$label = $this->mpdf->pdfxVersionLabel();
		$strict = !$this->mpdf->PDFXauto;
		$nb = $this->mpdf->page;

		for ($n = 1; $n <= $nb; $n++) {

			if (!isset($this->mpdf->pageDim[$n])) {
				continue;
			}

			$box = $this->getPdfxAnnotationExclusionBox($n);

			// Link annotations - /Rect is [x, top, x+w, top-h] in absolute page points
			if (isset($this->mpdf->PageLinks[$n])) {
				foreach ($this->mpdf->PageLinks[$n] as $key => $pl) {
					if (!isset($pl[0], $pl[1], $pl[2], $pl[3])) {
						continue;
					}
					$rect = [$pl[0], $pl[1] - $pl[3], $pl[0] + $pl[2], $pl[1]];
					if ($this->pdfxRectIntersectsBox($rect, $box)) {
						if ($strict) {
							$this->mpdf->PDFAXwarnings[] = 'Annotations must lie outside the TrimBox/BleedBox in ' . $label . ' files. (Link annotation removed)';
						}
						unset($this->mpdf->PageLinks[$n][$key]);
					}
				}
			}

			// Text / file-attachment annotations
			if (isset($this->mpdf->PageAnnots[$n])) {

				$wPt = $this->mpdf->pageDim[$n]['w'] * Mpdf::SCALE;
				$hPt = $this->mpdf->pageDim[$n]['h'] * Mpdf::SCALE;

				foreach ($this->mpdf->PageAnnots[$n] as $key => $pl) {

					// Prohibited subtype: /FileAttachment (only emitted when files are allowed)
					if (!empty($pl['opt']['file']) && $this->mpdf->allowAnnotationFiles) {
						if ($strict) {
							$this->mpdf->PDFAXwarnings[] = 'File attachment annotations are not permitted in ' . $label . ' files. (Annotation removed)';
						}
						unset($this->mpdf->PageAnnots[$n][$key]);
						continue;
					}

					// Text marker - mirror the /Rect math in MetadataWriter::writeAnnotations()
					$x = $pl['x'];
					if ($this->mpdf->annotMargin != 0 || $x == 0 || $x < 0) {
						$x = ($wPt / Mpdf::SCALE) - $this->mpdf->annotMargin;
					}
					$a = $x * Mpdf::SCALE;
					$b = $hPt - ($pl['y'] * Mpdf::SCALE);
					$rect = [$a, $b - 20, $a + 20, $b]; // text annotation marker is 20 x 20 pt

					if ($this->pdfxRectIntersectsBox($rect, $box)) {
						if ($strict) {
							$this->mpdf->PDFAXwarnings[] = 'Annotations must lie outside the TrimBox/BleedBox in ' . $label . ' files. (Annotation removed)';
						}
						unset($this->mpdf->PageAnnots[$n][$key]);
					}
				}
			}
		}
	}

	/**
	 * Computes the PDF page boxes for page $n (all values in absolute page points): the
	 * MediaBox width/height, the BleedBox (null when the page carries no bleed) and the
	 * TrimBox. This is the single source of truth for the box geometry, consumed by both
	 * writePages() (which serialises the boxes) and getPdfxAnnotationExclusionBox().
	 *
	 * @param int $n Page number
	 * @return array Keys: 'media' => [w, h], 'bleed' => [x0, y0, x1, y1]|null, 'trim' => [x0, y0, x1, y1]
	 */
	private function getPageBoxesPt($n)
	{
		if (isset($this->mpdf->OrientationChanges[$n])) {
			$hPt = $this->mpdf->pageDim[$n]['w'] * Mpdf::SCALE;
			$wPt = $this->mpdf->pageDim[$n]['h'] * Mpdf::SCALE;
			$owidthPt_LR = $this->mpdf->pageDim[$n]['outer_width_TB'] * Mpdf::SCALE;
			$owidthPt_TB = $this->mpdf->pageDim[$n]['outer_width_LR'] * Mpdf::SCALE;

			$media = [$hPt, $wPt];
			$trim = [$owidthPt_TB, $owidthPt_LR, $hPt - $owidthPt_TB, $wPt - $owidthPt_LR];
		} else {
			$wPt = $this->mpdf->pageDim[$n]['w'] * Mpdf::SCALE;
			$hPt = $this->mpdf->pageDim[$n]['h'] * Mpdf::SCALE;
			$owidthPt_LR = $this->mpdf->pageDim[$n]['outer_width_LR'] * Mpdf::SCALE;
			$owidthPt_TB = $this->mpdf->pageDim[$n]['outer_width_TB'] * Mpdf::SCALE;

			$media = [$wPt, $hPt];
			$trim = [$owidthPt_LR, $owidthPt_TB, $wPt - $owidthPt_LR, $hPt - $owidthPt_TB];
		}

		// A BleedBox is emitted only when a bleed margin is set and outer widths exist; it
		// is the TrimBox grown outwards by the bleed margin on every edge.
		$bleed = null;
		$bleedMargin = $this->mpdf->pageDim[$n]['bleedMargin'] * Mpdf::SCALE;
		if ($bleedMargin && ($owidthPt_TB || $owidthPt_LR)) {
			$bleed = [
				$trim[0] - $bleedMargin,
				$trim[1] - $bleedMargin,
				$trim[2] + $bleedMargin,
				$trim[3] + $bleedMargin,
			];
		}

		return ['media' => $media, 'bleed' => $bleed, 'trim' => $trim];
	}

	/**
	 * Returns the printable-area box [x0, y0, x1, y1] (in absolute page points) that PDF/X
	 * annotations must lie outside of: the BleedBox when a bleed is defined for the page,
	 * otherwise the TrimBox. Uses the shared geometry from getPageBoxesPt() so it can never
	 * drift from the boxes writePages() actually emits.
	 *
	 * @param int $n Page number
	 * @return float[]
	 */
	private function getPdfxAnnotationExclusionBox($n)
	{
		$boxes = $this->getPageBoxesPt($n);

		return $boxes['bleed'] !== null ? $boxes['bleed'] : $boxes['trim'];
	}

	/**
	 * True when the normalized annotation rect [x0, y0, x1, y1] overlaps the printable box.
	 *
	 * @param float[] $rect [x0, y0, x1, y1] with x0 <= x1 and y0 <= y1
	 * @param float[] $box  [x0, y0, x1, y1] with x0 <= x1 and y0 <= y1
	 * @return bool
	 */
	private function pdfxRectIntersectsBox($rect, $box)
	{
		return $rect[0] < $box[2] && $rect[2] > $box[0]
			&& $rect[1] < $box[3] && $rect[3] > $box[1];
	}

}
