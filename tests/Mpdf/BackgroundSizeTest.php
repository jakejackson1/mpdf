<?php

namespace Mpdf;

class BackgroundSizeTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	const WIDE_IMAGE = __DIR__ . '/../data/img/bayeux2.jpg'; // 292x83

	/**
	 * @var Mpdf
	 */
	private $mpdf;

	protected function set_up()
	{
		parent::set_up();

		$this->mpdf = new Mpdf();
	}

	/**
	 * The area is passed in mm and scaled on the way in, so expectations are stated the same way
	 */
	private function resize($imw, $imh, $areaW, $areaH, $keyword)
	{
		return $this->mpdf->_resizeBackgroundImage($imw, $imh, $areaW, $areaH, 0, false, false, [], ['w' => $keyword, 'h' => $keyword]);
	}

	public function testCoverOnAnImageWiderThanTheAreaBindsToTheHeight()
	{
		list($w, $h) = $this->resize(200, 50, 100, 100, 'cover');

		$this->assertEqualsWithDelta(100 * Mpdf::SCALE * 4, $w, 0.001);
		$this->assertEqualsWithDelta(100 * Mpdf::SCALE, $h, 0.001);
	}

	public function testCoverOnAnImageTallerThanTheAreaBindsToTheWidth()
	{
		list($w, $h) = $this->resize(50, 200, 100, 100, 'cover');

		$this->assertEqualsWithDelta(100 * Mpdf::SCALE, $w, 0.001);
		$this->assertEqualsWithDelta(100 * Mpdf::SCALE * 4, $h, 0.001);
	}

	/**
	 * 4:1 image, 2.5:1 area - the height still binds, but neither dimension matches the area
	 */
	public function testCoverOnAWideImageInAWideArea()
	{
		list($w, $h) = $this->resize(200, 50, 100, 40, 'cover');

		$this->assertEqualsWithDelta(40 * Mpdf::SCALE * 4, $w, 0.001);
		$this->assertEqualsWithDelta(40 * Mpdf::SCALE, $h, 0.001);
	}

	public function testContainOnAnImageWiderThanTheAreaBindsToTheWidth()
	{
		list($w, $h) = $this->resize(200, 50, 100, 100, 'contain');

		$this->assertEqualsWithDelta(100 * Mpdf::SCALE, $w, 0.001);
		$this->assertEqualsWithDelta(100 * Mpdf::SCALE / 4, $h, 0.001);
	}

	/**
	 * Footers draw each tile themselves instead of going through a pattern, so they run a second
	 * copy of the same arithmetic in PrintPageBackgrounds()
	 */
	public function testFooterBackgroundIsDrawnAtCoverScale()
	{
		$mpdf = new Mpdf(['margin_bottom' => 40]);
		$mpdf->compress = false;

		$mpdf->WriteHTML('<htmlpagefooter name="f"><div style="width: 60mm; height: 30mm; background-image: url(\''
			. self::WIDE_IMAGE . '\'); background-size: cover; background-repeat: no-repeat"></div></htmlpagefooter>');
		$mpdf->WriteHTML('<sethtmlpagefooter name="f" value="on" show-this-page="1" />');
		$mpdf->WriteHTML('<p>body</p>');

		$matches = [];
		$found = preg_match_all('#([\d.]+) 0 0 ([\d.]+) [\d.\-]+ [\d.\-]+ cm\s+/I\d+ Do#', $mpdf->Output('', 'S'), $matches, PREG_SET_ORDER);

		$this->assertGreaterThan(0, $found, 'the footer background was not drawn');

		foreach ($matches as $cm) {
			$this->assertEqualsWithDelta(292 / 83, $cm[1] / $cm[2], 0.001, 'aspect ratio is not preserved');
			$this->assertEqualsWithDelta(30 * Mpdf::SCALE, (float) $cm[2], 0.001, 'the bound dimension does not match the area');
			$this->assertGreaterThan(60 * Mpdf::SCALE, (float) $cm[1], 'the area is not covered horizontally');
		}
	}

}
