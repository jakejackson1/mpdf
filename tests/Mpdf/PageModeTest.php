<?php

namespace Mpdf;

class PageModeTest extends \Yoast\PHPUnitPolyfills\TestCases\TestCase
{

	public function testNoPageModeByDefault()
	{
		$output = $this->render(function (Mpdf $mpdf) {
			$mpdf->WriteHTML('<p>Hello</p>');
		});

		$this->assertStringNotContainsString('/PageMode', $output);
	}

	public function testBookmarksOpenTheOutlinePane()
	{
		$output = $this->render(function (Mpdf $mpdf) {
			$mpdf->WriteHTML('<p>Hello</p>');
			$mpdf->Bookmark('Chapter one');
		});

		$this->assertSame(1, substr_count($output, '/PageMode'));
		$this->assertStringContainsString('/PageMode /UseOutlines', $output);
	}

	public function testUseAttachmentsDisplayPreference()
	{
		$output = $this->render(function (Mpdf $mpdf) {
			$mpdf->SetDisplayPreferences('UseAttachments');
			$mpdf->WriteHTML('<p>Hello</p>');
		});

		$this->assertSame(1, substr_count($output, '/PageMode'));
		$this->assertStringContainsString('/PageMode /UseAttachments', $output);
	}

	/**
	 * A dictionary cannot hold the same key twice, so an explicit request has to beat the
	 * outline pane that bookmarks would otherwise ask for.
	 */
	public function testExplicitPreferenceWinsOverBookmarks()
	{
		$output = $this->render(function (Mpdf $mpdf) {
			$mpdf->SetDisplayPreferences('UseAttachments');
			$mpdf->WriteHTML('<p>Hello</p>');
			$mpdf->Bookmark('Chapter one');
		});

		$this->assertSame(1, substr_count($output, '/PageMode'));
		$this->assertStringContainsString('/PageMode /UseAttachments', $output);
	}

	public function testFullScreenWinsOverUseAttachments()
	{
		$output = $this->render(function (Mpdf $mpdf) {
			$mpdf->SetDisplayPreferences('FullScreenUseAttachments');
			$mpdf->WriteHTML('<p>Hello</p>');
		});

		$this->assertSame(1, substr_count($output, '/PageMode'));
		$this->assertStringContainsString('/PageMode /FullScreen', $output);
	}

	public function testLayerPaneWinsOverEverythingElse()
	{
		$output = $this->render(function (Mpdf $mpdf) {
			$mpdf->open_layer_pane = true;
			$mpdf->SetDisplayPreferences('FullScreen');
			$mpdf->SetVisibility('screenonly');
			$mpdf->WriteHTML('<p>Hello</p>');
			$mpdf->SetVisibility('visible');
			$mpdf->Bookmark('Chapter one');
		});

		$this->assertSame(1, substr_count($output, '/PageMode'));
		$this->assertStringContainsString('/PageMode /UseOC', $output);
	}

	private function render(callable $write)
	{
		$mpdf = new Mpdf();
		$mpdf->compress = false;

		$write($mpdf);

		$output = $mpdf->OutputBinaryData();
		$mpdf->cleanup();

		return $output;
	}

}
