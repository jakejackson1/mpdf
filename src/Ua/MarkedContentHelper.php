<?php

namespace Mpdf\Ua;

use Mpdf\Writer\BaseWriter;

/**
 * Emit BDC / EMC / BMC marked-content operators into the active PDF buffer.
 *
 * Tag handlers and writers route all BDC/EMC emission through this class so
 * the operators end up in the correct buffer (page body, header/footer,
 * column buffer, rotated-table buffer, …) via BaseWriter::write(). Direct
 * writes to $this->pages[$this->page] would bypass the buffer routing and
 * place operators in the wrong stream in four out of five rendering contexts.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.6 — Marked Content (BMC/BDC/EMC operators)
 *   - ISO 32000-1:2008 §14.7.4.4 — MCID in the BDC property dict
 *   - ISO 32000-1:2008 §14.8.2.2 — /Artifact BMC for non-structure content
 *
 * @see StructureTree::addContent()  allocates the MCID passed to begin()
 * @see BaseWriter::write()          buffer-routing target
 */
class MarkedContentHelper
{

	/** @var BaseWriter  buffer-routing writer; reached from ServiceFactory. */
	private $writer;

	/**
	 * @var int  BDC/EMC nesting depth. Incremented by begin(), decremented
	 *   by end(). Checked at _enddoc() time to detect unbalanced operators
	 *   that would produce invalid PDF.
	 */
	private $depth = 0;

	/**
	 * Construct with the buffer-routing writer.
	 *
	 * Called once by ServiceFactory during bootstrap and then passed into
	 * UaState's constructor.
	 *
	 * @param BaseWriter $writer
	 */
	public function __construct(BaseWriter $writer)
	{
		$this->writer = $writer;
	}

	/**
	 * Emit a BDC (or BMC for Artifact) marked-content operator.
	 *
	 * Two cases, discriminated by $mcid:
	 *   - $mcid >= 0 : emits `/<structType> <</MCID N>> BDC` — a real tagged
	 *     content item belonging to a struct element.
	 *   - $mcid === -1 : emits `/Artifact BMC` (no property dict) — decorative
	 *     / layout content outside the logical structure. `BMC` is used (not
	 *     `BDC`) because ISO 32000-1 §14.6 specifies BMC for property-less
	 *     sequences; BDC requires a property dict.
	 *
	 * Increments the depth counter; caller must pair with exactly one end().
	 *
	 * ISO 32000-1 §14.6 — BMC/BDC/EMC operators.
	 * ISO 32000-1 §14.8.2.2 — Artifact content sequences.
	 *
	 * @param  string   $structType  PDF struct type to tag (ignored when $mcid === -1)
	 * @param  int      $mcid        marked-content ID from StructureTree::addContent(); -1 for Artifact
	 * @param  string   $altText     reserved — alt text for inline Span wrappers; not currently emitted here
	 * @return void
	 */
	public function begin($structType, $mcid, $altText = null)
	{
		if ($mcid === -1) {
			// ISO 32000-1 §14.8.2.2 — Artifact sequences use BMC (no property dict).
			$this->writer->write('/Artifact BMC');
		} else {
			// ISO 32000-1 §14.7.4.4 — MCID is the integer the ParentTree
			// cross-references; must match the value StructureTree::addContent()
			// returned for the current struct element.
			$props = '/MCID ' . $mcid;
			$this->writer->write('/' . $structType . ' <<' . $props . '>> BDC');
		}
		$this->depth++;
	}

	/**
	 * Emit an EMC operator closing the most recent BDC / BMC.
	 *
	 * Guarded against underflow: if $depth is already 0, the call is a no-op
	 * rather than writing a stray EMC into the stream. That guard makes
	 * defensive unwinding in error paths safe.
	 *
	 * ISO 32000-1 §14.6 — EMC closes the most recently opened BDC or BMC.
	 *
	 * @return void
	 */
	public function end()
	{
		if ($this->depth <= 0) {
			return;
		}
		$this->writer->write('EMC');
		$this->depth--;
	}

	/**
	 * Current BDC/EMC nesting depth.
	 *
	 * Mpdf::_enddoc() reads this to assert BDC/EMC balance at document close;
	 * a non-zero value indicates a tag handler that opened without closing,
	 * which produces invalid PDF.
	 *
	 * @return int
	 */
	public function getDepth()
	{
		return $this->depth;
	}
}
