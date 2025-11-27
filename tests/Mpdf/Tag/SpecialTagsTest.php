<?php

namespace Mpdf\Tag;

class SpecialTagsTest extends BaseTagTestCase
{
	public function testAnnotation_Open()
	{
		$tag = $this->createTag(Annotation::class);

		$attr = ['CONTENT' => 'Test annotation'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Annotation is a marker tag, doesn't create blocks
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testAnnotation_WithIcon()
	{
		$tag = $this->createTag(Annotation::class);

		$attr = ['CONTENT' => 'Test annotation', 'ICON' => 'Note'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Annotation with ICON still doesn't create blocks
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testBookmark_Open()
	{
		$tag = $this->createTag(Bookmark::class);

		$attr = ['CONTENT' => 'Test bookmark'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Bookmark is a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testBookmark_WithLevel()
	{
		$tag = $this->createTag(Bookmark::class);

		$attr = ['CONTENT' => 'Test bookmark', 'LEVEL' => '1'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Bookmark with LEVEL still marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testPre_Open()
	{
		$tag = $this->createTag(Pre::class);

		$attr = [];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Verify block level increased
		$this->assertEquals(1, $this->mpdf->blklvl);
	}

	public function testWatermarkImage_Open()
	{
		$tag = $this->createTag(WatermarkImage::class);

		$attr = ['SRC' => 'test.jpg'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// WatermarkImage is a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testWatermarkText_Open()
	{
		$tag = $this->createTag(WatermarkText::class);

		$attr = ['CONTENT' => 'DRAFT'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// WatermarkText is a marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testWatermarkText_WithAlpha()
	{
		$tag = $this->createTag(WatermarkText::class);

		$attr = ['CONTENT' => 'DRAFT', 'ALPHA' => '0.3'];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// WatermarkText with ALPHA still marker tag
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testTta_Open()
	{
		$tag = $this->createTag(Tta::class);

		$attr = [];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Tta (text transform all-caps) is inline, doesn't create blocks
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testTts_Open()
	{
		$tag = $this->createTag(Tts::class);

		$attr = [];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Tts (text transform small-caps) is inline
		$this->assertEquals(0, $this->mpdf->blklvl);
	}

	public function testTtz_Open()
	{
		$tag = $this->createTag(Ttz::class);

		$attr = [];
		$ahtml = [];
		$ihtml = 0;

		$tag->open($attr, $ahtml, $ihtml);
		
		// Ttz (text transform) is inline
		$this->assertEquals(0, $this->mpdf->blklvl);
	}
}
