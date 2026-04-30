<?php

namespace Mpdf\Ua;

use Mpdf\Exception\InvalidArgumentException;

/**
 * A single node in the document's logical structure tree.
 *
 * One StructureElement exists per opened struct element (P, H1, Figure, Table,
 * TR, TD, Link, Note, etc.). Assembled by StructureTree during HTML parse,
 * serialised to a PDF /Type /StructElem object by StructureWriter at close.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — StructElem dict entries (/S, /P, /K, /A, /Alt, /ActualText, /Lang, /ID, /Pg)
 *   - ISO 32000-1:2008 §14.7.4.4 Table 324 — MCR dict (/Type /MCR /Pg /Stm /MCID) for content items spanning pages / Form XObjects
 *   - ISO 32000-1:2008 §14.7.4.4.2 Table 338 — OBJR dict (/Type /OBJR /Obj) for annotation references
 *
 * @see StructureTree   owns the stack / lifecycle
 * @see StructureWriter serialises this element to a PDF object
 */
class StructureElement
{

	/** @var string  Validated PDF struct type (e.g., 'P', 'H1', 'Figure'). */
	protected $type;

	/** @var StructureElement|null  Parent element, or null for the Document root. */
	protected $parent;

	/** @var StructureElement[]  Ordered list of child struct elements. */
	protected $children;

	/**
	 * @var array<int, array{page:int, mcid:int, pageRef:int, stm:int}>
	 *   Marked content references owned by this element. `page` is the
	 *   /StructParents integer; `mcid` is the BDC operator's /MCID; `pageRef`
	 *   is the PDF object number of the page (filled at write time by
	 *   StructureWriter looking up $mpdf->offsets); `stm` is the PDF object
	 *   number of the Form XObject stream containing the marked content (0 when
	 *   the content is directly on the page — ISO 32000-1:2008 §14.7.4.4 Table 324).
	 */
	protected $mcids;

	/**
	 * @var array<int, array{structParent:int, obj:int}>
	 *   Object references — annotations (links, widgets, notes, file attachments)
	 *   reached via /Type /OBJR kids in the struct element's /K array. One entry
	 *   per attached annotation; `structParent` is the annotation's /StructParent
	 *   integer, `obj` is the annotation's PDF object number.
	 */
	protected $objrefs;

	/**
	 * @var array<string, mixed>
	 *   StructElem attributes. Three categories:
	 *     - Direct dict keys (ISO 32000-1 Table 322): Alt, ActualText, Lang, E, T, ID
	 *     - /O /Layout attribute object (Table 344): Placement, BBox, WritingMode
	 *     - /O /Table attribute object (Table 349): Scope, ColSpan, RowSpan, Headers, Summary
	 *     - /O /List attribute object (Table 348): ListNumbering
	 *   StructureWriter splits them into direct keys vs /A attribute objects at emit time.
	 */
	protected $attributes;

	/**
	 * @var string|null  Globally unique /ID string, required for Note elements
	 *   (Matterhorn 09-002) and for TH cells referenced by TD /Headers.
	 */
	protected $id;

	/** @var int  PDF object number assigned by StructureWriter at serialisation time; 0 before write. */
	protected $objNum;

	/**
	 * Construct a struct element with a validated PDF struct type and an
	 * optional attribute map.
	 *
	 * The $attributes array is passed through to StructureWriter verbatim;
	 * keys are conventional PDF attribute names (not HTML) — e.g. pass
	 * ['Scope' => 'Row'] for a TH, NOT ['scope' => 'row'].
	 *
	 * @param  string $type        PDF struct type; validated via StructType::isValid()
	 * @param  array  $attributes  optional attribute map (see class docblock)
	 * @throws \Mpdf\Exception\InvalidArgumentException  if $type is not a standard PDF struct type
	 */
	public function __construct($type, $attributes = [])
	{
		// ISO 32000-1 §14.8 Table 333/334/335 — only standard struct types are
		// legal; non-standard custom roles must go through StructureTree::addRoleMapping().
		if (!StructType::isValid($type)) {
			throw new InvalidArgumentException(
				'Invalid PDF struct type: "' . $type . '"'
			);
		}
		$this->type       = $type;
		$this->attributes = $attributes;
		$this->parent     = null;
		$this->children   = [];
		$this->mcids      = [];
		$this->objrefs    = [];
		$this->id         = null;
		$this->objNum     = 0;
	}

	/** @return string validated PDF struct type. */
	public function getType()
	{
		return $this->type;
	}

	/** @return StructureElement|null parent element; null for the Document root. */
	public function getParent()
	{
		return $this->parent;
	}

	/** @return StructureElement[] ordered child elements. */
	public function getChildren()
	{
		return $this->children;
	}

	/** @return array<int, array{page:int, mcid:int, pageRef:int, stm:int}> MCR entries owned by this element. */
	public function getMcids()
	{
		return $this->mcids;
	}

	/** @return array<int, array{structParent:int, obj:int}> OBJR entries (annotations). */
	public function getObjrefs()
	{
		return $this->objrefs;
	}

	/** @return array<string,mixed> attributes map (see class docblock for categories). */
	public function getAttributes()
	{
		return $this->attributes;
	}

	/** @return string|null globally unique /ID, or null if none assigned. */
	public function getId()
	{
		return $this->id;
	}

	/** @return int PDF object number assigned by StructureWriter; 0 before serialisation. */
	public function getObjNum()
	{
		return $this->objNum;
	}

	// Package-internal mutators — used only by StructureTree (during parse) and
	// StructureWriter (during serialisation). Tag handlers should go through
	// StructureTree::open() / addContent() / addObjref() instead.

	/**
	 * Assign a globally unique /ID to this element.
	 *
	 * Required for Note elements (Matterhorn 09-002) and for TH elements that
	 * need to be referenced by a TD's /Headers attribute (Matterhorn 09-004/005).
	 *
	 * @param  string $id  globally unique identifier; caller must guarantee uniqueness
	 * @return void
	 */
	public function setId($id)
	{
		$this->id = $id;
	}

	/**
	 * Normalise an HTML id (or synthesised id) into a byte sequence that is
	 * legal in BOTH a PDF name object (`/foo`) and a PDF byte string (`(foo)`).
	 *
	 * The /ID entry on a TH StructElem (ISO 32000-1 Table 322) is a byte string,
	 * whereas the matching reference in a TD's /Headers array (ISO 32000-1
	 * Table 349) is a PDF name. Readers and assistive technology resolve the
	 * cross-reference by comparing the underlying bytes — so the bytes must
	 * match in both serialisations.
	 *
	 * Two normalisations are applied:
	 *   1. ASCII letters are lowercased. mPDF's HTML parser uppercases the value
	 *      of `id="..."` but leaves `headers="..."` (and aria-* references) at
	 *      their source case, so without this fold the TH /ID and TD /Headers
	 *      tokens land on different bytes and the cross-reference breaks even
	 *      when the source HTML is internally consistent.
	 *   2. Bytes outside `[a-z0-9_.-]` are #-escaped as `#xx`. The kept
	 *      characters are all members of the PDF name unrestricted-character
	 *      set (ISO 32000-1 §7.3.5), so the byte sequence is identical between
	 *      the byte-string and name-object serialisations.
	 *
	 * @param  string $id  raw HTML id (any byte sequence)
	 * @return string      sanitised id, byte-identical between name and string forms
	 */
	public static function sanitiseIdForPdf($id)
	{
		$id = (string) $id;
		$out = '';
		$len = strlen($id);
		for ($i = 0; $i < $len; $i++) {
			$ord = ord($id[$i]);
			if ($ord >= 0x41 && $ord <= 0x5A) {
				// A-Z → a-z so TH (uppercased by mPDF parser) and TD headers
				// (left at source case) normalise to the same byte sequence.
				$out .= chr($ord + 0x20);
			} elseif (($ord >= 0x30 && $ord <= 0x39) // 0-9
				|| ($ord >= 0x61 && $ord <= 0x7A) // a-z
				|| $ord === 0x5F // _
				|| $ord === 0x2D // -
				|| $ord === 0x2E // .
			) {
				$out .= $id[$i];
			} else {
				$out .= sprintf('#%02X', $ord);
			}
		}
		return $out;
	}

	/**
	 * Record the PDF object number assigned to this element at serialisation time.
	 *
	 * Called by StructureWriter after $mpdf->writer->object() reserves a number
	 * for this element's /Type /StructElem dict.
	 *
	 * @param  int $n
	 * @return void
	 */
	public function setObjNum($n)
	{
		$this->objNum = $n;
	}

	/**
	 * Set an attribute value on this element.
	 *
	 * Overwrites any previous value for the key. StructureWriter reads all
	 * attributes at emit time and splits them into direct dict keys vs /A
	 * attribute objects (see class docblock / Appendix A1).
	 *
	 * @param  string $key    attribute name (PDF convention, not HTML)
	 * @param  mixed  $value  attribute value
	 * @return void
	 */
	public function setAttribute($key, $value)
	{
		$this->attributes[$key] = $value;
	}

	/**
	 * Record one marked-content reference (MCR) owned by this element.
	 *
	 * Appended whenever a content item (a BDC/EMC pair) addressed to this
	 * element is emitted on a page. Multi-page elements accumulate multiple
	 * entries; StructureWriter emits them as a /K array of MCR dicts per
	 * ISO 32000-1 §14.7.4.4 Table 324.
	 *
	 * When $stm is non-zero the MCR dict must also include a /Stm entry
	 * (ISO 32000-1 §14.7.4.4 Table 324) pointing to the Form XObject that
	 * contains the marked content — used for FPDI Tier 2 tagged-source merges.
	 *
	 * @param  int $page     /StructParents integer of the host page or Form XObject
	 * @param  int $mcid     MCID integer embedded in the BDC operator's property dict
	 * @param  int $pageRef  PDF object number of the host page; 0 before write
	 * @param  int $stm      PDF object number of the Form XObject stream; 0 for direct page content
	 * @return void
	 */
	public function addMcid($page, $mcid, $pageRef = 0, $stm = 0)
	{
		$this->mcids[] = ['page' => $page, 'mcid' => $mcid, 'pageRef' => $pageRef, 'stm' => $stm];
	}

	/**
	 * Patch the pageRef and stm of an existing MCR entry by its array index.
	 *
	 * Called by FpdiStructMerger::patchMergedSubtreeObjectNumbers() after
	 * writePages() and writeImportedPagesAndResolvedObjects() have allocated
	 * the real PDF object numbers. At render time both values are 0 (unknown);
	 * this method writes back the resolved values so StructureWriter emits
	 * correct /MCR dicts with /Pg and /Stm.
	 *
	 * Only MCR slots that still carry both pageRef=0 and stm=0 are patched by
	 * the merger — MCRs added via the normal HTML tagging path may also have
	 * pageRef=0 at first but are never passed to this method.
	 *
	 * @param  int $idx      zero-based index into the $mcids array
	 * @param  int $pageRef  real PDF object number of the host page dict
	 * @param  int $stm      real PDF object number of the Form XObject stream
	 * @return void
	 */
	public function patchMcr($idx, $pageRef, $stm)
	{
		if (isset($this->mcids[$idx])) {
			$this->mcids[$idx]['pageRef'] = $pageRef;
			$this->mcids[$idx]['stm']     = $stm;
		}
	}

	/**
	 * Record one annotation object reference (OBJR) as a child kid of this element.
	 *
	 * Required for Link / Widget / Note / FileAttachment struct elements so the
	 * associated annotation is reachable from the struct tree (Matterhorn 02-003,
	 * 11-002). StructureWriter emits each entry as a <</Type /OBJR /Obj N 0 R>>
	 * kid alongside MCID integers.
	 *
	 * ISO 32000-1 §14.7.4.4.2 Table 338 — OBJR dict entries.
	 *
	 * @param  int $structParent  /StructParent integer on the annotation dict
	 * @param  int $obj           PDF object number of the annotation
	 * @return void
	 */
	public function addObjref($structParent, $obj)
	{
		$this->objrefs[] = ['structParent' => $structParent, 'obj' => $obj];
	}

	/**
	 * Append a child struct element and set its parent to $this.
	 *
	 * Called by StructureTree::open() when a new element is pushed onto the
	 * stack. Parent linkage is written directly (same-class protected access) so
	 * external callers can't bypass the parent/child invariant.
	 *
	 * @param  StructureElement $child
	 * @return void
	 */
	public function addChild(StructureElement $child)
	{
		$child->parent    = $this;   // direct write: same class
		$this->children[] = $child;
	}

	/**
	 * Remove the most recently added child from this element's children list.
	 *
	 * Used when a tag handler discovers it pushed the wrong struct type (e.g.
	 * Th::open() inherits Td's TD push and then needs to swap it for a TH).
	 * Without this method the discarded TD would remain in the parent's /K
	 * array, leaving a phantom cell that breaks ISO 14289-1 §7.2 test 43
	 * (table rows must have the same number of columns).
	 *
	 * Idempotent when no children exist.
	 *
	 * @return void
	 */
	public function popLastChild()
	{
		array_pop($this->children);
	}

	/**
	 * Record an ARIA relationship attribute value referencing another struct element.
	 *
	 * Used by AriaIdResolver to wire up resolved aria-labelledby, aria-describedby,
	 * aria-controls, aria-owns, and aria-flowto relationships as /A attribute
	 * entries that StructureWriter includes in the struct element dict.
	 *
	 * ISO 32000-2:2020 §14.7 — struct element relationships via /Ref entries.
	 * WAI-ARIA 1.1 §6.6 — ID reference attributes.
	 *
	 * @param  string           $ariaAttr  ARIA attribute name (e.g. 'aria-labelledby')
	 * @param  StructureElement $target    resolved target struct element
	 * @return void
	 */
	public function addRelationship($ariaAttr, StructureElement $target)
	{
		if (!isset($this->attributes['_aria_relationships'])) {
			$this->attributes['_aria_relationships'] = [];
		}
		$this->attributes['_aria_relationships'][] = ['attr' => $ariaAttr, 'target' => $target];
	}
}
