<?php

namespace Mpdf\Tag;

class TocIndexTagsTest extends BaseTagTestCase
{
	public function testToc_Open()
	{
		$tag = $this->createTag(Toc::class);

		$attr = [];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// TOC is a marker tag, doesn't create blocks
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testToc_WithAttributes()
	{
		$tag = $this->createTag(Toc::class);

		$attr = ['PAGING' => 'true', 'LINKS' => 'true'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// TOC with attributes is still a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testTocEntry_Open()
	{
		$tag = $this->createTag(TocEntry::class);

		$attr = ['CONTENT' => 'Test Entry'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// TOC entry is a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testTocEntry_WithLevel()
	{
		$tag = $this->createTag(TocEntry::class);

		$attr = ['CONTENT' => 'Test Entry', 'LEVEL' => '2'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// TOC entry with level is still a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testIndexEntry_Open()
	{
		$tag = $this->createTag(IndexEntry::class);

		$attr = ['CONTENT' => 'Test Index'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Index entry is a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testIndexInsert_Open()
	{
		$tag = $this->createTag(IndexInsert::class);

		$attr = [];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Index insert is a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}
}
