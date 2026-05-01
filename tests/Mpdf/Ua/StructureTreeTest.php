<?php

namespace Mpdf\Ua;

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Unit tests for StructureTree — element stack, MCID allocation, artifact scoping,
 * role mappings, and annotation struct parent allocation.
 *
 * No mPDF instantiation required for most cases; tests operate on plain
 * StructureTree / StructureElement instances.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.7.2 — Structure Hierarchy
 *   - ISO 32000-1:2008 §14.7.4.4 — ParentTree; dense MCID arrays
 *   - ISO 32000-1:2008 §14.8.2.2 — Real Content vs Artifacts
 *   - ISO 32000-1:2008 §14.7.3 — RoleMap (custom → standard type mapping)
 *
 * @group pdfua
 */
class StructureTreeTest extends TestCase
{

	/** @var StructureTree */
	private $tree;

	protected function set_up()
	{
		$this->tree = new StructureTree();
	}

	/**
	 * After construction the root element has type Document and is the current top.
	 */
	public function testOpenCreatesRootDocument()
	{
		$this->assertSame('Document', $this->tree->getRoot()->getType());
		$this->assertSame($this->tree->getRoot(), $this->tree->getCurrent());
	}

	/**
	 * open() adds a child element under the current top and makes it current.
	 */
	public function testOpenPushesChildElement()
	{
		$this->tree->open('P');
		$current = $this->tree->getCurrent();
		$this->assertSame('P', $current->getType());
		$children = $this->tree->getRoot()->getChildren();
		$this->assertCount(1, $children);
		$this->assertSame($current, $children[0]);
	}

	/**
	 * close() pops the top element and restores the previous one.
	 */
	public function testClosePoppsStack()
	{
		$this->tree->open('P');
		$this->tree->close();
		$this->assertSame($this->tree->getRoot(), $this->tree->getCurrent());
	}

	/**
	 * close() when only the Document root is on the stack is a no-op (no underflow).
	 */
	public function testCloseWhenOnlyRootOnStackIsNoop()
	{
		$this->tree->close();
		$this->assertSame($this->tree->getRoot(), $this->tree->getCurrent());
	}

	/**
	 * addContent() returns a non-negative MCID integer.
	 */
	public function testAddContentReturnsMcid()
	{
		$this->tree->open('P');
		$mcid = $this->tree->addContent(0);
		$this->assertGreaterThanOrEqual(0, $mcid);
	}

	/**
	 * Two addContent() calls for the same /StructParents page return distinct MCIDs.
	 */
	public function testMcidIsUnique()
	{
		$this->tree->open('P');
		$mcid1 = $this->tree->addContent(0);
		$this->tree->open('P');
		$mcid2 = $this->tree->addContent(0);
		$this->assertNotSame($mcid1, $mcid2);
	}

	/**
	 * MCID resets to 0 for each new /StructParents key — counters are per-page.
	 *
	 * ISO 32000-1 §14.7.4.4 — MCIDs within one content stream must be dense
	 * and 0-based; each /StructParents key has its own independent counter.
	 */
	public function testMcidResetsToZeroPerPage()
	{
		$this->tree->open('P');
		$mcid0a = $this->tree->addContent(0);  // page 0, MCID 0
		$mcid0b = $this->tree->addContent(0);  // page 0, MCID 1

		$mcid1a = $this->tree->addContent(1);  // page 1 — new key, should restart at 0

		$this->assertSame(0, $mcid0a);
		$this->assertSame(1, $mcid0b);
		$this->assertSame(0, $mcid1a);
	}

	/**
	 * addArtifact() returns the sentinel -1.
	 */
	public function testAddArtifactReturnsMinusOne()
	{
		$this->assertSame(-1, $this->tree->addArtifact());
	}

	/**
	 * ParentTree is populated after addContent().
	 */
	public function testParentTreeIsPopulated()
	{
		$this->tree->open('P');
		$mcid = $this->tree->addContent(0);
		$pt = $this->tree->getParentTree();
		$this->assertArrayHasKey(0, $pt);
		$this->assertArrayHasKey($mcid, $pt[0]);
		$this->assertSame($this->tree->getCurrent(), $pt[0][$mcid]);
	}

	/**
	 * The struct element itself records the MCID in its getMcids() array.
	 */
	public function testMcidsRecordedOnElement()
	{
		$this->tree->open('P');
		$elem = $this->tree->getCurrent();
		$mcid = $this->tree->addContent(0);
		$mcids = $elem->getMcids();
		$this->assertCount(1, $mcids);
		$this->assertSame(0, $mcids[0]['page']);
		$this->assertSame($mcid, $mcids[0]['mcid']);
	}

	/**
	 * openArtifact() suppresses struct element creation — open() becomes a no-op.
	 */
	public function testOpenArtifactSuppressesStructElements()
	{
		$this->tree->openArtifact();
		$this->tree->open('P');
		$this->assertSame($this->tree->getRoot(), $this->tree->getCurrent());
		$this->assertCount(0, $this->tree->getRoot()->getChildren());
	}

	/**
	 * addContent() in artifact scope returns -1 (Artifact sentinel).
	 */
	public function testAddContentInArtifactContextReturnsMinusOne()
	{
		$this->tree->openArtifact();
		$mcid = $this->tree->addContent(0);
		$this->assertSame(-1, $mcid);
	}

	/**
	 * closeArtifact() restores normal behaviour — open() works again.
	 */
	public function testCloseArtifactRestoresNormalBehaviour()
	{
		$this->tree->openArtifact();
		$this->tree->closeArtifact();
		$this->tree->open('P');
		$this->assertSame('P', $this->tree->getCurrent()->getType());
	}

	/**
	 * close() in artifact scope is a no-op — since open() was also a no-op,
	 * nothing was pushed so nothing should be popped.
	 */
	public function testCloseInArtifactContextIsNoop()
	{
		$this->tree->openArtifact();
		$this->tree->open('P');  // no-op — nothing pushed
		$this->tree->close();    // no-op — nothing to pop
		$this->assertSame($this->tree->getRoot(), $this->tree->getCurrent());
		$this->tree->closeArtifact();
	}

	/**
	 * Extra closeArtifact() calls are clamped at 0 and do NOT make
	 * isInArtifact() return true after a matching open.
	 */
	public function testCloseArtifactWhenDepthIsZeroIsNoop()
	{
		$this->assertFalse($this->tree->isInArtifact());
		$this->tree->closeArtifact(); // extra close — should be a no-op
		$this->assertFalse($this->tree->isInArtifact());
	}

	/**
	 * addContentForElement() in artifact scope returns -1 and does not modify
	 * the target element.
	 */
	public function testAddContentForElementInArtifactContextReturnsMinusOne()
	{
		$this->tree->open('P');
		$elem = $this->tree->getCurrent();
		$this->tree->close();

		$this->tree->openArtifact();
		$result = $this->tree->addContentForElement($elem, 0);

		$this->assertSame(-1, $result);
		$this->assertCount(0, $elem->getMcids());
	}

	/**
	 * First addRoleMapping() call wins; a second call with a different type
	 * is silently ignored (prevents conflicting RoleMap entries in veraPDF).
	 */
	public function testAddRoleMappingDuplicateFirstWins()
	{
		$this->tree->addRoleMapping('CustomBox', 'Div');
		$this->tree->addRoleMapping('CustomBox', 'Sect');
		$mappings = $this->tree->getRoleMappings();
		$this->assertSame('Div', $mappings['CustomBox']);
	}

	/**
	 * isInArtifact() tracks the depth counter correctly across nested scopes.
	 */
	public function testIsInArtifactNested()
	{
		$this->assertFalse($this->tree->isInArtifact());
		$this->tree->openArtifact();
		$this->assertTrue($this->tree->isInArtifact());
		$this->tree->openArtifact();  // nested
		$this->assertTrue($this->tree->isInArtifact());
		$this->tree->closeArtifact(); // back to depth 1
		$this->assertTrue($this->tree->isInArtifact());
		$this->tree->closeArtifact(); // back to depth 0
		$this->assertFalse($this->tree->isInArtifact());
	}
}
