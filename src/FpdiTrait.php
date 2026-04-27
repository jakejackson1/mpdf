<?php

namespace Mpdf;

use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;
use setasign\Fpdi\PdfParser\Filter\AsciiHex;
use setasign\Fpdi\PdfParser\Type\PdfArray;
use setasign\Fpdi\PdfParser\Type\PdfDictionary;
use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObject;
use setasign\Fpdi\PdfParser\Type\PdfIndirectObjectReference;
use setasign\Fpdi\PdfParser\Type\PdfName;
use setasign\Fpdi\PdfParser\Type\PdfNull;
use setasign\Fpdi\PdfParser\Type\PdfNumeric;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;
use setasign\Fpdi\PdfParser\Type\PdfTypeException;
use setasign\Fpdi\PdfReader\DataStructure\Rectangle;
use setasign\Fpdi\PdfReader\PageBoundaries;

/**
 * @mixin Mpdf
 */
trait FpdiTrait
{
	use \setasign\Fpdi\FpdiTrait {
		writePdfType as fpdiWritePdfType;
		useImportedPage as fpdiUseImportedPage;
		importPage as fpdiImportPage;
	}

	protected $k = Mpdf::SCALE;

	/**
	 * The currently used object number.
	 *
	 * @var int
	 */
	public $currentObjectNumber;

	/**
	 * A counter for template ids.
	 *
	 * @var int
	 */
	protected $templateId = 0;

	protected function setPageFormat($format, $orientation)
	{
		// in mPDF this needs to be "P" (why ever)
		$orientation = 'P';
		$this->_setPageSize([$format['width'], $format['height']], $orientation);

		if ($orientation != $this->DefOrientation) {
			$this->OrientationChanges[$this->page] = true;
		}

		$this->wPt = $this->fwPt;
		$this->hPt = $this->fhPt;
		$this->w = $this->fw;
		$this->h = $this->fh;

		$this->CurOrientation = $orientation;
		$this->ResetMargins();
		$this->pgwidth = $this->w - $this->lMargin - $this->rMargin;
		$this->PageBreakTrigger = $this->h - $this->bMargin;

		$this->pageDim[$this->page]['w'] = $this->w;
		$this->pageDim[$this->page]['h'] = $this->h;
	}

	/**
	 * Set the minimal PDF version.
	 *
	 * @param string $pdfVersion
	 */
	protected function setMinPdfVersion($pdfVersion)
	{
		if (\version_compare($pdfVersion, $this->pdf_version, '>')) {
			$this->pdf_version = $pdfVersion;
		}
	}

	/**
	 * Return a PdfReader for an already-opened source file by its reader id.
	 *
	 * Wraps the vendor trait's protected getPdfReader() so that collaborators
	 * outside the class hierarchy (e.g. FpdiStructMerger) can access the reader
	 * without calling a protected method directly.
	 *
	 * @param  string $readerId  reader id obtained from $importedPages[$pageId]['readerId']
	 * @return \setasign\Fpdi\PdfReader\PdfReader
	 */
	public function getSourcePdfReader($readerId)
	{
		return $this->getPdfReader($readerId);
	}

	/**
	 * Get the next template id.
	 *
	 * @return int
	 */
	protected function getNextTemplateId()
	{
		return $this->templateId++;
	}

	/**
	 * Draws an imported page or a template onto the page or another template.
	 *
	 * Omit one of the size parameters (width, height) to calculate the other one automatically in view to the aspect
	 * ratio.
	 *
	 * @param mixed $tpl The template id
	 * @param float|int|array $x The abscissa of upper-left corner. Alternatively you could use an assoc array
	 *                           with the keys "x", "y", "width", "height", "adjustPageSize".
	 * @param float|int $y The ordinate of upper-left corner.
	 * @param float|int|null $width The width.
	 * @param float|int|null $height The height.
	 * @param bool $adjustPageSize
	 * @return array The size
	 * @see Fpdi::getTemplateSize()
	 */
	public function useTemplate($tpl, $x = 0, $y = 0, $width = null, $height = null, $adjustPageSize = false)
	{
		return $this->useImportedPage($tpl, $x, $y, $width, $height, $adjustPageSize);
	}

	/**
	 * Draws an imported page onto the page.
	 *
	 * Omit one of the size parameters (width, height) to calculate the other one automatically in view to the aspect
	 * ratio.
	 *
	 * @param mixed $pageId The page id
	 * @param float|int|array $x The abscissa of upper-left corner. Alternatively you could use an assoc array
	 *                           with the keys "x", "y", "width", "height", "adjustPageSize".
	 * @param float|int $y The ordinate of upper-left corner.
	 * @param float|int|null $width The width.
	 * @param float|int|null $height The height.
	 * @param bool $adjustPageSize
	 * @return array The size.
	 * @see Fpdi::getTemplateSize()
	 */
	public function useImportedPage($pageId, $x = 0, $y = 0, $width = null, $height = null, $adjustPageSize = false)
	{
		if ($this->state == 0) {
			$this->AddPage();
		}

		/* Extract $x if an array */
		if (is_array($x)) {
			unset($x['pageId']);
			extract($x, EXTR_IF_EXISTS);
			if (is_array($x)) {
				$x = 0;
			}
		}

		// PDF/UA-1 — two-tier treatment for imported PDF pages.
		//
		// Tier 1 (untagged source): The Do operator emitted by FPDI is bracketed with
		//   /Artifact <</Type /Layout>> BDC … EMC
		// (ISO 14289-1:2014 §7.1; Matterhorn 01-007; ISO 32000-1:2008 §14.7.4.4 Table 324).
		// A diagnostic warning is emitted via getPdfUaWarnings().
		//
		// Tier 2 (tagged source): The source PDF's struct subtree is cloned into the host
		// StructureTree via FpdiStructMerger::mergePageStructSubtree(). The Form XObject
		// receives a /StructParents entry at write time (see writeImportedPagesAndResolvedObjects).
		// No Artifact wrap is emitted; the cloned struct elements provide the tagging.
		//
		// The readerId is read from importedPages because $this->currentReaderId is null
		// at this point in the call stack (it is only set during writeImportedPagesAndResolvedObjects).
		if ($this->PDFUA && isset($this->importedPages[$pageId])) {
			$pdfuaMerger = $this->ua->getFpdiStructMerger();
			$readerId    = $this->importedPages[$pageId]['readerId'];

			if ($pdfuaMerger->sourceIsTagged($readerId)) {
				// Tier 2: merge struct subtree. Object numbers are not yet known at
				// render time (they are allocated in writeImportedPagesAndResolvedObjects),
				// so pass 0 for foXObjectObjNum and hostPageObjNum; the actual numbers
				// are patched by patchMergedSubtreeObjectNumbers() at write time.
				$pdfuaMerger->mergePageStructSubtree($pageId, 0, 0);
				// Record this host page so patchMergedSubtreeObjectNumbers() can resolve
				// pageDim[$hostPage]['n'] after writePages() has run. Called on every
				// placement (first use + all SetPageTemplate reuses).
				$pdfuaMerger->recordHostPage($pageId, $this->page);
			} else {
				// Tier 1: wrap Do as Artifact.
				$this->writer->write('/Artifact <</Type /Layout>> BDC');
			}
		} else {
			$pdfuaMerger = null;
		}

		$newSize = $this->fpdiUseImportedPage($pageId, $x, $y, $width, $height, $adjustPageSize);

		if ($this->PDFUA && $pdfuaMerger !== null) {
			$readerId = $this->importedPages[$pageId]['readerId'];
			if ($pdfuaMerger->sourceIsTagged($readerId)) {
				// Tier 2: nothing to close; the struct elements carry the tagging.
				// No EMC needed because no BDC was emitted.
			} else {
				// Tier 1: close the Artifact sequence.
				$this->writer->write('EMC');
				$pdfuaMerger->addUntaggedWarning(
					'Imported PDF page treated as untagged and wrapped as /Artifact <</Type /Layout>> — '
					. 'content is not tagged with a struct element. '
					. 'Matterhorn 01-007: use a workflow that preserves struct tagging to avoid this warning.'
				);

				// Flush accumulated warnings into UaState so they appear in getPdfUaWarnings().
				foreach ($pdfuaMerger->getUntaggedWarnings() as $w) {
					$this->ua->addWarning($w);
				}
			}
		}

		$this->setImportedPageLinks($pageId, $x, $y, $newSize);

		return $newSize;
	}

	/**
	 * Imports a page.
	 *
	 * @param int $pageNumber The page number.
	 * @param string $box The page boundary to import. Default set to PageBoundaries::CROP_BOX.
	 * @param bool $groupXObject Define the form XObject as a group XObject to support transparency (if used).
	 * @return string A unique string identifying the imported page.
	 * @throws CrossReferenceException
	 * @throws FilterException
	 * @throws PdfParserException
	 * @throws PdfTypeException
	 * @throws PdfReaderException
	 * @see PageBoundaries
	 */
	public function importPage($pageNumber, $box = PageBoundaries::CROP_BOX, $groupXObject = true)
	{
		$pageId = $this->fpdiImportPage($pageNumber, $box, $groupXObject);

		$this->importedPages[$pageId]['externalLinks'] = $this->getImportedExternalPageLinks($pageNumber);

		return $pageId;
	}

	/**
	 * Imports the external page links
	 *
	 * @param int $pageNumber The page number.
	 * @return array
	 * @throws CrossReferenceException
	 * @throws PdfTypeException
	 * @throws \setasign\Fpdi\PdfParser\PdfParserException
	 */
	public function getImportedExternalPageLinks($pageNumber)
	{
		$links = [];

		$reader = $this->getPdfReader($this->currentReaderId);
		$parser = $reader->getParser();

		$page = $reader->getPage($pageNumber);
		$page->getPageDictionary();

		$annotations = $page->getAttribute('Annots');
		if ($annotations instanceof PdfIndirectObjectReference) {
			$annotations = PdfType::resolve($parser->getIndirectObject($annotations->value), $parser);
		}

		if ($annotations instanceof PdfArray) {
			$annotations = PdfType::resolve($annotations, $parser);

			foreach ($annotations->value as $annotation) {
				try {
					$annotation = PdfType::resolve($annotation, $parser);

					$type = PdfName::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'Type'), $parser));
					$subtype = PdfName::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'Subtype'), $parser));
					$link = PdfDictionary::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'A'), $parser));

					/* Skip over annotations that aren't links */
					if ($type->value !== 'Annot' || $subtype->value !== 'Link') {
						continue;
					}

					/* Calculate the link positioning */
					$position = PdfArray::ensure(PdfType::resolve(PdfDictionary::get($annotation, 'Rect'), $parser), 4);
					$rect = Rectangle::byPdfArray($position, $parser);
					$uri = PdfString::ensure(PdfType::resolve(PdfDictionary::get($link, 'URI'), $parser));

					$links[] = [
						'x' => $rect->getLlx() / Mpdf::SCALE,
						'y' => $rect->getLly() / Mpdf::SCALE,
						'width' => $rect->getWidth() / Mpdf::SCALE,
						'height' => $rect->getHeight() / Mpdf::SCALE,
						'url' => $uri->value
					];
				} catch (PdfTypeException $e) {
					continue;
				}
			}
		}

		return $links;
	}

	/**
	 * @param mixed $pageId The page id
	 * @param int|float $x The abscissa of upper-left corner.
	 * @param int|float $y The ordinate of upper-right corner.
	 * @param array $newSize The size.
	 */
	public function setImportedPageLinks($pageId, $x, $y, $newSize)
	{
		$originalSize = $this->getTemplateSize($pageId);
		$pageHeightDifference = $this->h - $newSize['height'];

		/* Handle different aspect ratio */
		$widthRatio = $newSize['width'] / $originalSize['width'];
		$heightRatio = $newSize['height'] / $originalSize['height'];

		foreach ($this->importedPages[$pageId]['externalLinks'] as $item) {

			$item['x'] *= $widthRatio;
			$item['width'] *= $widthRatio;

			$item['y'] *= $heightRatio;
			$item['height'] *= $heightRatio;

			$this->Link(
				$item['x'] + $x,
				/* convert Y to be measured from the top of the page */
				$this->h - $item['y'] - $item['height'] - $pageHeightDifference + $y,
				$item['width'],
				$item['height'],
				$item['url']
			);
		}
	}

	/**
	 * Get the size of an imported page or template.
	 *
	 * Omit one of the size parameters (width, height) to calculate the other one automatically in view to the aspect
	 * ratio.
	 *
	 * @param mixed $tpl The template id
	 * @param float|int|null $width The width.
	 * @param float|int|null $height The height.
	 * @return array|bool An array with following keys: width, height, 0 (=width), 1 (=height), orientation (L or P)
	 */
	public function getTemplateSize($tpl, $width = null, $height = null)
	{
		return $this->getImportedPageSize($tpl, $width, $height);
	}

	/**
	 * @throws CrossReferenceException
	 * @throws PdfTypeException
	 * @throws \setasign\Fpdi\PdfParser\PdfParserException
	 */
	public function writeImportedPagesAndResolvedObjects()
	{
		$this->currentReaderId = null;

		foreach ($this->importedPages as $key => $pageData) {
			$this->writer->object();
			$foXObjectObjNum                           = $this->n;
			$this->importedPages[$key]['objectNumber'] = $foXObjectObjNum;
			$this->currentReaderId = $pageData['readerId'];

			// PDF/UA-1 Tier 2 — inject /StructParents into the Form XObject dict so
			// the ParentTree NumTree back-reference from the Form XObject to its struct
			// elements resolves correctly (ISO 32000-1:2008 §14.7.4.4).
			if ($this->PDFUA) {
				$merger         = $this->ua->getFpdiStructMerger();
				$structParentsN = $merger->getFormXObjectStructParents($key);
				if ($structParentsN >= 0 && $pageData['stream'] instanceof PdfStream) {
					$pageData['stream']->value->value['StructParents'] = PdfNumeric::create($structParentsN);
				}

				// Patch MCR placeholder entries (pageRef=0, stm=0) with real object
				// numbers now that both the Form XObject and page dicts have been
				// written (writePages runs before writeImportedPagesAndResolvedObjects).
				$merger->patchMergedSubtreeObjectNumbers($key, $foXObjectObjNum);
			}

			$this->writePdfType($pageData['stream']);
			$this->_put('endobj');
		}

		foreach (\array_keys($this->readers) as $readerId) {
			$parser = $this->getPdfReader($readerId)->getParser();
			$this->currentReaderId = $readerId;

			while (($objectNumber = \array_pop($this->objectsToCopy[$readerId])) !== null) {
				try {
					$object = $parser->getIndirectObject($objectNumber);

				} catch (CrossReferenceException $e) {
					if ($e->getCode() === CrossReferenceException::OBJECT_NOT_FOUND) {
						$object = PdfIndirectObject::create($objectNumber, 0, new PdfNull());
					} else {
						throw $e;
					}
				}

				$this->writePdfType($object);
			}
		}

		$this->currentReaderId = null;
	}

	public function getImportedPages()
	{
		return $this->importedPages;
	}

	protected function _put($s, $newLine = true)
	{
		$this->buffer->append($s, $newLine);
	}

	/**
	 * Writes a PdfType object to the resulting buffer.
	 *
	 * @param PdfType $value
	 * @throws PdfTypeException
	 */
	public function writePdfType(PdfType $value)
	{
		if (!$this->encrypted) {
			if ($value instanceof PdfIndirectObject) {
				/**
				 * @var $value PdfIndirectObject
				 */
				$n = $this->objectMap[$this->currentReaderId][$value->objectNumber];
				$this->writer->object($n);
				$this->writePdfType($value->value);
				$this->_put('endobj');
				return;
			}

			$this->fpdiWritePdfType($value);
			return;
		}

		if ($value instanceof PdfString) {
			$string = PdfString::unescape($value->value);
			$string = $this->protection->rc4($this->protection->objectKey($this->currentObjectNumber), $string);
			$value->value = $this->writer->escape($string);

		} elseif ($value instanceof PdfHexString) {
			$filter = new AsciiHex();
			$string = $filter->decode($value->value);
			$string = $this->protection->rc4($this->protection->objectKey($this->currentObjectNumber), $string);
			$value->value = $filter->encode($string, true);

		} elseif ($value instanceof PdfStream) {
			$stream = $value->getStream();
			$stream = $this->protection->rc4($this->protection->objectKey($this->currentObjectNumber), $stream);
			$dictionary = $value->value;
			$dictionary->value['Length'] = PdfNumeric::create(\strlen($stream));
			$value = PdfStream::create($dictionary, $stream);

		} elseif ($value instanceof PdfIndirectObject) {
			/**
			 * @var $value PdfIndirectObject
			 */
			$this->currentObjectNumber = $this->objectMap[$this->currentReaderId][$value->objectNumber];
			/**
			 * @var $value PdfIndirectObject
			 */
			$n = $this->objectMap[$this->currentReaderId][$value->objectNumber];
			$this->writer->object($n);
			$this->writePdfType($value->value);
			$this->_put('endobj');
			return;
		}

		$this->fpdiWritePdfType($value);
	}
}
