<?php

namespace Mpdf\Tests\Css;

use Mpdf\CssManager;
use Mpdf\Mpdf;
use PHPUnit\Framework\TestCase;

class SpecificityIntegrationTest extends TestCase
{
	public function testSpecificity()
	{
		$mpdf = new Mpdf();
		$reflection = new \ReflectionClass($mpdf);
		$property = $reflection->getProperty('cssManager');
		$property->setAccessible(true);
		$cssManager = $property->getValue($mpdf);
		
		$css = '
        <style>
            .red { color: red; }
            div.red { color: blue; }
            #myid { color: green; }
            div p { color: yellow; }
            p { color: black; }
        </style>
        ';
		
		$cssManager->ReadCSS($css);
		
		// Test 1: .red vs div.red
		// <div class="red">
		$attr = ['CLASS' => 'red'];
		$p = $cssManager->MergeCSS('BLOCK', 'DIV', $attr);
		$this->assertEquals('blue', $p['COLOR'], 'div.red should override .red');
		
		// Test 2: div.red vs #myid
		// <div class="red" id="myid">
		$attr = ['CLASS' => 'red', 'ID' => 'myid'];
		$p = $cssManager->MergeCSS('BLOCK', 'DIV', $attr);
		$this->assertEquals('green', $p['COLOR'], '#myid should override div.red');
		
		// Test 3: Nested div p vs p
		// Simulate parent div
		$mpdf->blklvl = 1;
		$mpdf->blk[0] = ['tag' => 'DIV', 'attr' => []];
		
		// <p> inside <div>
		$attr = [];
		$p = $cssManager->MergeCSS('BLOCK', 'P', $attr);
		$this->assertEquals('yellow', $p['COLOR'], 'div p should override p');
		
		// Test 4: Nested mismatch
		// Simulate parent span
		$mpdf->blklvl = 1;
		$mpdf->blk[0] = ['tag' => 'SPAN', 'attr' => []];
		
		// <p> inside <span>
		$attr = [];
		$p = $cssManager->MergeCSS('BLOCK', 'P', $attr);
		$this->assertEquals('black', $p['COLOR'], 'div p should NOT match inside span');
	}

	public function testComplexSpecificity()
	{
		$mpdf = new Mpdf();
		$reflection = new \ReflectionClass($mpdf);
		$property = $reflection->getProperty('cssManager');
		$property->setAccessible(true);
		$cssManager = $property->getValue($mpdf);

		$css = '
        <style>
            body div .content { color: red; } /* 0,0,1,2 = 12 */
            div.content { color: blue; } /* 0,0,1,1 = 11 */
            #main .content { color: green; } /* 0,1,1,0 = 110 */
        </style>
        ';

		$cssManager->ReadCSS($css);

		// Simulate body > div > div.content
		$mpdf->blklvl = 2;
		$mpdf->blk[0] = ['tag' => 'BODY', 'attr' => []];
		$mpdf->blk[1] = ['tag' => 'DIV', 'attr' => []];

		$attr = ['CLASS' => 'content'];
		$p = $cssManager->MergeCSS('BLOCK', 'DIV', $attr);
		$this->assertEquals('red', $p['COLOR'], 'body div .content (12) should override div.content (11)');

		// Simulate body > div#main > div.content
		$mpdf->blklvl = 2;
		$mpdf->blk[0] = ['tag' => 'BODY', 'attr' => []];
		$mpdf->blk[1] = ['tag' => 'DIV', 'attr' => ['ID' => 'main']];

		$attr = ['CLASS' => 'content'];
		$p = $cssManager->MergeCSS('BLOCK', 'DIV', $attr);
		$this->assertEquals('green', $p['COLOR'], '#main .content (110) should override body div .content (12)');
	}
}
