<?php

namespace Mpdf\Tag;

class StandardBlockTagsTest extends BaseTagTestCase
{
	/**
	 * @dataProvider blockTagsProvider
	 */
	public function testOpenAndClose_BlockTags($tagName, $className)
	{
		$tag = $this->createTag($className);

		$attr = ['ALIGN' => 'center'];
		$ahtml = [];
		$ihtml = 0;

		// Initial block level
		$initialBlkLvl = $this->mpdf->blklvl;

		// OPEN
		$tag->open($attr, $ahtml, $ihtml);

		// Verify block level increased
		$this->assertEquals($initialBlkLvl + 1, $this->mpdf->blklvl, "Block level should increment");
		
		// Verify tag name is stored
		$this->assertEquals($tagName, $this->mpdf->blk[$this->mpdf->blklvl]['tag']);
		
		// Verify align attribute is processed
		$this->assertEquals('C', $this->mpdf->blk[$this->mpdf->blklvl]['block-align']);
		
		// Verify InlineProperties are saved for the new block
		$this->assertArrayHasKey('InlineProperties', $this->mpdf->blk[$this->mpdf->blklvl]);
	}

	public function blockTagsProvider()
	{
		return [
			['ADDRESS', Address::class],
			['ARTICLE', Article::class],
			['ASIDE', Aside::class],
			['BLOCKQUOTE', BlockQuote::class],
			['CENTER', Center::class],
			['DETAILS', Details::class],
			['DIV', Div::class],
			['FIELDSET', FieldSet::class],
			['FIGCAPTION', FigCaption::class],
			['FIGURE', Figure::class],
			['FOOTER', Footer::class],
			['HEADER', Header::class],
			['HGROUP', HGroup::class],
			['MAIN', Main::class],
			['NAV', Nav::class],
			['SECTION', Section::class],
			['SUMMARY', Summary::class],
		];
	}

	public function testCenterTag_InTable()
	{
		$tag = $this->createTag(Center::class);

		$this->mpdf->tableLevel = 1;
		$this->mpdf->tdbegin = true;
		$this->mpdf->row = 0;
		$this->mpdf->col = 0;
		$this->mpdf->cell[$this->mpdf->row][$this->mpdf->col] = ['s' => 0];

		$attr = [];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);

		// Verify Center tag sets cell alignment to center
		$this->assertEquals('C', $this->mpdf->cell[$this->mpdf->row][$this->mpdf->col]['a']);
	}

	public function testBlockTag_WithDisplayNone()
	{
		$tag = $this->createTag(Div::class);

		$attr = ['STYLE' => 'display: none;'];
		$ahtml = [];
		$ihtml = 0;

		$initialBlkLvl = $this->mpdf->blklvl;

		$tag->open($attr, $ahtml, $ihtml);

		// Verify block level still increased
		$this->assertEquals($initialBlkLvl + 1, $this->mpdf->blklvl);
		
		// Verify hide flag is set
		$this->assertTrue($this->mpdf->blk[$this->mpdf->blklvl]['hide']);
	}

	public function testBlockTag_WithCssProperties()
	{
		$tag = $this->createTag(Div::class);

		$attr = ['STYLE' => 'margin: 10px; padding: 5px; text-align: right;'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);

		$blk = $this->mpdf->blk[$this->mpdf->blklvl];
		
		// Verify margins are set (converted from px to mm)
		$this->assertGreaterThan(0, $blk['margin_top']);
		$this->assertGreaterThan(0, $blk['margin_bottom']);
		$this->assertGreaterThan(0, $blk['margin_left']);
		$this->assertGreaterThan(0, $blk['margin_right']);
		
		// Verify padding is set
		$this->assertGreaterThan(0, $blk['padding_top']);
		$this->assertGreaterThan(0, $blk['padding_bottom']);
		$this->assertGreaterThan(0, $blk['padding_left']);
		$this->assertGreaterThan(0, $blk['padding_right']);
		
		// Verify text-align is set
		$this->assertEquals('R', $blk['align']);
	}

	public function testBlockTag_WithBorderProperties()
	{
		$tag = $this->createTag(Div::class);

		$attr = ['STYLE' => 'border: 2px solid red;'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);

		$blk = $this->mpdf->blk[$this->mpdf->blklvl];
		
		// Verify border width is set
		$this->assertGreaterThan(0, $blk['border_top']['w']);
		$this->assertGreaterThan(0, $blk['border_bottom']['w']);
		$this->assertGreaterThan(0, $blk['border_left']['w']);
		$this->assertGreaterThan(0, $blk['border_right']['w']);
		
		// Verify border style is set (1 = solid)
		$this->assertNotEmpty($blk['border_top']['s']);
	}

	public function testBlockTag_WithWidthAndHeight()
	{
		$tag = $this->createTag(Div::class);

		$attr = ['STYLE' => 'width: 100px; height: 50px;'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);

		$blk = $this->mpdf->blk[$this->mpdf->blklvl];
		
		// Verify width is set
		$this->assertArrayHasKey('css_set_width', $blk);
		$this->assertGreaterThan(0, $blk['css_set_width']);
		
		// Verify height is set
		$this->assertGreaterThan(0, $blk['css_set_height']);
	}

	public function testBlockTag_WithBackgroundColor()
	{
		$tag = $this->createTag(Div::class);

		$attr = ['STYLE' => 'background-color: blue;'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);

		$blk = $this->mpdf->blk[$this->mpdf->blklvl];
		
		// Verify background color is set
		$this->assertArrayHasKey('bgcolor', $blk);
		$this->assertNotFalse($blk['bgcolor']);
	}
}
