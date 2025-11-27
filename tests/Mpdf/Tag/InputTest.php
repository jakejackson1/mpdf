<?php

namespace Mpdf\Tag;

class InputTest extends BaseTagTestCase
{
	private $tag;

	protected function set_up()
	{
		parent::set_up();

		$this->tag = $this->createTag(Input::class);
	}

	public function testOpen_TextInput()
	{
		$attr = ['TYPE' => 'TEXT', 'VALUE' => 'test'];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);

		// Verify text input was added to textbuffer
		$this->assertNotEmpty($this->mpdf->textbuffer);
		$bufferItem = end($this->mpdf->textbuffer);
		$content = $bufferItem[0];
		
		$this->assertStringContainsString('type=input', $content);
		$this->assertStringContainsString('value";s:4:"test"', $content);
	}

	public function testOpen_HiddenInput()
	{
		$attr = ['TYPE' => 'HIDDEN', 'NAME' => 'hidden_field', 'VALUE' => 'hidden_value'];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);

		// Hidden input should not write to textbuffer but should set ignorefollowingspaces
		$this->assertTrue($this->mpdf->ignorefollowingspaces);
		
		// If active forms are enabled (default in Mpdf setup?), it might add to form object
		// But BaseTagTestCase sets up Mpdf with default config.
		// Let's check if we can verify form data.
		// $this->mpdf->form is a Form service.
		// We can check if SetFormText was called? No, we can't easily spy on the service without mocking.
		// But we can check side effects.
		// For now, asserting ignorefollowingspaces is a good start for the tag logic.
	}

	public function testOpen_Input_WithInlineStyle()
	{
		$attr = [
			'TYPE' => 'TEXT',
			'VALUE' => 'test',
			'STYLE' => 'margin: 10px; border: 1px solid red; color: blue; font-family: Arial;'
		];
		$ahtml = [];
		$ihtml = 0;

		$this->tag->open($attr, $ahtml, $ihtml);

		// Verify CSS was applied
		// Input tag restores properties, so we check the textbuffer
		$this->assertNotEmpty($this->mpdf->textbuffer);
		$bufferItem = end($this->mpdf->textbuffer);
		$content = $bufferItem[0];
		
		// Extract objattr
		// Content format: identifier...objattr=serialized...identifier
		// We look for objattr= and take everything until the end identifier
		$parts = explode('objattr=', $content);
		$this->assertCount(2, $parts);
		$serialized = substr($parts[1], 0, strpos($parts[1], \Mpdf\Mpdf::OBJECT_IDENTIFIER));
		$objattr = unserialize($serialized);
		
		// Verify color is blue (RGB: 0, 0, 255)
		$this->assertEquals(3, $objattr['color'][0]);
		$this->assertEquals(0, ord($objattr['color'][1]));
		$this->assertEquals(0, ord($objattr['color'][2]));
		$this->assertEquals(255, ord($objattr['color'][3]));
	}
}
