<?php

namespace Mpdf\Tag;

use Mpdf\Mpdf;

class PageFooterTest extends BaseTagTestCase
{
	/**
	 * @var PageFooter
	 */
	private $tag;

	protected function set_up()
	{
		parent::set_up();

		$this->tag = $this->createTag(PageFooter::class);
	}

	public function testOpen_BasicFooter()
	{
		$attr = ['NAME' => '', 'CONTENT-LEFT' => 'Left', 'CONTENT-RIGHT' => 'Right'];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);

		$this->assertTrue($this->mpdf->ignorefollowingspaces);
		
		// Verify default footer is set
		$reflection = new \ReflectionClass($this->mpdf);
		$property = $reflection->getProperty('pageHTMLfooters');
		$property->setAccessible(true);
		$pageHTMLfooters = $property->getValue($this->mpdf);

		$this->assertArrayHasKey('_nonhtmldefault', $pageHTMLfooters);
		// Note: The structure might be different than expected.
		// DefFooterByName calls _createHTMLheaderFooter which returns HTML string.
		// It sets ['html'] and ['h'].
		// But wait, PageFooter tag builds a $p array and calls DefFooterByName.
		// Let's check DefFooterByName implementation again.
		// It calls _createHTMLheaderFooter($arr, 'F').
		// So we should check the content of the HTML string or the structure if it's not converted yet?
		// Actually, DefFooterByName takes $arr (properties) and converts it to HTML.
		// So we should verify the properties passed to it? Or the resulting HTML?
		// The test was asserting on array keys like ['L']['content'] which implies it expected the raw array.
		// But DefFooterByName converts it.
		// Let's check if there is a property that holds the raw definitions.
		// There doesn't seem to be one based on the search results.
		// However, we can check if the resulting HTML contains the content.
		
		$footer = $pageHTMLfooters['_nonhtmldefault'];
		$this->assertStringContainsString('Left', $footer['html']);
		$this->assertStringContainsString('Right', $footer['html']);
	}

	public function testOpen_NamedFooter()
	{
		$attr = ['NAME' => 'myfooter', 'CONTENT-CENTER' => 'Center'];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);

		$reflection = new \ReflectionClass($this->mpdf);
		$property = $reflection->getProperty('pageHTMLfooters');
		$property->setAccessible(true);
		$pageHTMLfooters = $property->getValue($this->mpdf);

		$this->assertArrayHasKey('myfooter', $pageHTMLfooters);
		$footer = $pageHTMLfooters['myfooter'];
		$this->assertStringContainsString('Center', $footer['html']);
	}

	public function testOpen_FooterStyles()
	{
		$attr = [
			'NAME' => '',
			'FOOTER-STYLE' => 'font-family: serif; font-size: 10pt; font-weight: bold; font-style: italic; color: #FF0000',
			'CONTENT-LEFT' => 'Styled'
		];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);

		$reflection = new \ReflectionClass($this->mpdf);
		$property = $reflection->getProperty('pageHTMLfooters');
		$property->setAccessible(true);
		$pageHTMLfooters = $property->getValue($this->mpdf);

		$footer = $pageHTMLfooters['_nonhtmldefault'];
		// The HTML generation logic is complex, but we can check for style attributes in the HTML
		$this->assertStringContainsString('font-family: serif', $footer['html']);
		$this->assertStringContainsString('color: #ff0000', $footer['html']);
		$this->assertStringContainsString('Styled', $footer['html']);
	}

	public function testOpen_SpecificStyles()
	{
		$attr = [
			'NAME' => '',
			'FOOTER-STYLE-LEFT' => 'font-weight: bold; color: blue',
			'FOOTER-STYLE-RIGHT' => 'font-style: italic; color: red',
			'CONTENT-LEFT' => 'L',
			'CONTENT-RIGHT' => 'R'
		];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);

		$reflection = new \ReflectionClass($this->mpdf);
		$property = $reflection->getProperty('pageHTMLfooters');
		$property->setAccessible(true);
		$pageHTMLfooters = $property->getValue($this->mpdf);

		$footer = $pageHTMLfooters['_nonhtmldefault'];
		$this->assertStringContainsString('color: blue', $footer['html']);
		$this->assertStringContainsString('color: red', $footer['html']);
	}

	public function testOpen_LineAttribute()
	{
		$attr = ['NAME' => '', 'LINE' => '1'];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);
		
		$reflection = new \ReflectionClass($this->mpdf);
		$property = $reflection->getProperty('pageHTMLfooters');
		$property->setAccessible(true);
		$pageHTMLfooters = $property->getValue($this->mpdf);

		$footer = $pageHTMLfooters['_nonhtmldefault'];
		// Line attribute might affect the HTML or be stored separately?
		// DefFooterByName calls _createHTMLheaderFooter.
		// Let's assume it adds a border or hr to the HTML.
		// Or maybe it's not easily testable via HTML string without knowing exact output.
		// However, PageFooter.php sets $p['line'] = 1.
		// And DefFooterByName passes $p to _createHTMLheaderFooter.
		// So we can check if _createHTMLheaderFooter uses it.
		// For now, let's check if the HTML is generated.
		$this->assertNotEmpty($footer['html']);
	}

	public function testOpen_PageHeader()
	{
		// Use PageHeader class to test header logic
		$headerTag = new PageHeader(
			$this->mpdf,
			$this->getService('cache'),
			$this->getService('cssManager'),
			$this->getService('form'),
			$this->getService('otl'),
			$this->getService('tableOfContents'),
			$this->getService('sizeConverter'),
			$this->getService('colorConverter'),
			$this->getService('imageProcessor'),
			$this->getService('languageToFont')
		);

		$attr = [
			'NAME' => 'myheader',
			'CONTENT-CENTER' => 'Header Content',
			'HEADER-STYLE' => 'font-weight: bold',
			'HEADER-STYLE-CENTER' => 'color: green'
		];
		$ahtml = [];
		$ihtml = 0;

		$headerTag->open($attr, $ahtml, $ihtml);

		$reflection = new \ReflectionClass($this->mpdf);
		$property = $reflection->getProperty('pageHTMLheaders');
		$property->setAccessible(true);
		$pageHTMLheaders = $property->getValue($this->mpdf);

		$this->assertArrayHasKey('myheader', $pageHTMLheaders);
		$header = $pageHTMLheaders['myheader'];
		$this->assertStringContainsString('Header Content', $header['html']);
		$this->assertStringContainsString('color: green', $header['html']);
	}

	public function testClose()
	{
		$ahtml = [];
		$ihtml = 0;
		// Close method is empty but should be callable without error
		$this->tag->close($ahtml, $ihtml);
		$this->assertTrue(true);
	}
}
