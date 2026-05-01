<?php

namespace Mpdf\Image;

use Mockery;
use Mpdf\CssManager;
use Mpdf\Color\ColorConverter;
use Mpdf\Language\LanguageToFont;
use Mpdf\Language\ScriptToLanguage;
use Mpdf\Mpdf;
use Mpdf\Otl;
use Mpdf\SizeConverter;

class SvgTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	/**
	 * @var \Mpdf\Image\Svg
	 */
	private $svg;

	private $sizeConverter;

	private $colorConverter;

	protected function set_up()
	{
		parent::set_up();

		$mpdf = Mockery::mock(Mpdf::class);

		$mpdf->shouldIgnoreMissing();
		$mpdf->shouldReceive('AddExtGState')->andReturn(1);

		$mpdf->img_dpi = 72;
		$mpdf->PDFAXwarnings = [];

		$otl = Mockery::mock(Otl::class);
		$cssManager = Mockery::mock(CssManager::class);
		$imageProcessor = Mockery::mock(ImageProcessor::class);
		$this->sizeConverter = Mockery::mock(SizeConverter::class);
		$this->colorConverter = Mockery::mock(ColorConverter::class);
		$languageToFontInterface = Mockery::mock(LanguageToFont::class);
		$scriptToLanguageInterface = Mockery::mock(ScriptToLanguage::class);

		$this->svg = new Svg(
			$mpdf,
			$otl,
			$cssManager,
			$imageProcessor,
			$this->sizeConverter,
			$this->colorConverter,
			$languageToFontInterface,
			$scriptToLanguageInterface
		);
	}

	protected function tear_down()
	{
		parent::tear_down();

		Mockery::close();
	}

	public function testSvgImage()
	{
		$data = file_get_contents(__DIR__ . '/../../data/img/demo.svg');

		$this->sizeConverter->shouldReceive('convert')->twice()->andReturn(0);
		$this->colorConverter->shouldReceive('convert')->times(140)->andReturn(0);

		$this->svg->ImageSVG($data);
	}

	public function testLogoManageroneSvgImage()
	{
		$data = file_get_contents(__DIR__ . '/../../data/img/logo_managerone.svg');

		$this->sizeConverter->shouldReceive('convert')->times(2)->andReturn(0);
		$this->colorConverter->shouldReceive('convert')->times(1)->andReturn(0);

		$this->svg->ImageSVG($data);
	}

	public function testLogoLivingparisianSvgImage()
	{
		$data = file_get_contents(__DIR__ . '/../../data/img/logo_livingparisian.svg');

		$this->colorConverter->shouldReceive('convert')->times(28)->andReturn(0);

		$this->svg->ImageSVG($data);
	}

	// =====================================================================
	// PDF/UA-1 M5 — accessible metadata extraction.
	//
	// extractAccessibleMetadata() is exercised directly so the tests stay
	// focused on the SimpleXML extractor and avoid the rest of the SVG path
	// walker (which the existing ImageSVG-based tests already cover with
	// richer fixtures).
	// =====================================================================

	public function testAccessibleMetadataExtractsTopLevelTitle()
	{
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<title>Hello</title>'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</svg>';
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertSame('Hello', $meta['title']);
		$this->assertNull($meta['desc']);
	}

	public function testAccessibleMetadataExtractsTopLevelDesc()
	{
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<desc>Long description body.</desc>'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</svg>';
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertNull($meta['title']);
		$this->assertSame('Long description body.', $meta['desc']);
	}

	public function testAccessibleMetadataExtractsBoth()
	{
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<title>Logo</title>'
			 . '<desc>Blue circle.</desc>'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</svg>';
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertSame('Logo', $meta['title']);
		$this->assertSame('Blue circle.', $meta['desc']);
	}

	public function testAccessibleMetadataIgnoresNestedTitle()
	{
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<g><title>NestedLabel</title>'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</g>'
			 . '</svg>';
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertNull($meta['title']);
		$this->assertNull($meta['desc']);
	}

	public function testAccessibleMetadataMalformedSvgReturnsNulls()
	{
		// Unclosed <title> — SimpleXML must fail and the extractor must
		// return [null, null] without raising warnings or throwing.
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<title>Unclosed'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</svg>';
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertNull($meta['title']);
		$this->assertNull($meta['desc']);
	}

	public function testAccessibleMetadataDecodesEntitiesAndCdata()
	{
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<title><![CDATA[A & B]]></title>'
			 . '<desc>Caf&#233;</desc>'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</svg>';
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertSame('A & B', $meta['title']);
		$this->assertSame("Caf\xC3\xA9", $meta['desc']);
	}

	public function testAccessibleMetadataEmptyTitleTreatedAsNull()
	{
		// <title></title> with empty body — author signal is "no metadata".
		$svg = '<svg width="20" height="20" xmlns="http://www.w3.org/2000/svg">'
			 . '<title></title>'
			 . '<circle cx="10" cy="10" r="8" fill="blue"/>'
			 . '</svg>';
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertNull($meta['title']);
		$this->assertNull($meta['desc']);
	}

	public function testAccessibleMetadataTitleWhitespaceCollapsed()
	{
		// Multi-line title from pretty-printed SVG: whitespace runs collapse to
		// a single space; outer whitespace trimmed.
		$svg = "<svg width=\"20\" height=\"20\" xmlns=\"http://www.w3.org/2000/svg\">\n"
			 . "  <title>\n    Pretty\n    Printed\n  </title>\n"
			 . "  <circle cx=\"10\" cy=\"10\" r=\"8\" fill=\"blue\"/>\n"
			 . "</svg>";
		$meta = $this->svg->extractAccessibleMetadata($svg);

		$this->assertSame('Pretty Printed', $meta['title']);
	}

	public function testAccessibleMetadataNoSvgRootReturnsNulls()
	{
		// Empty / non-SVG input is a no-op — must not throw.
		$meta = $this->svg->extractAccessibleMetadata('not an svg');
		$this->assertNull($meta['title']);
		$this->assertNull($meta['desc']);
	}

}
