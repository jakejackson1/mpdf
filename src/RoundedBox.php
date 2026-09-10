<?php

namespace Mpdf;

/**
 * The geometry of a box with rounded corners, as PDF path operators. Positions and sizes come in the way mPDF keeps
 * them, in millimetres from the top left of the page, so a method that writes operators takes the page height to
 * turn them into points from the bottom left. A set of radii is keyed TL/TR/BR/BL, each corner a [horizontal,
 * vertical] pair with [0, 0] for a square one. Paths come back without a painting operator, for the caller to fill,
 * stroke or clip to.
 */
class RoundedBox
{

	/**
	 * The two sides each corner of a box lies between
	 */
	const CORNER_SIDES = ['TL' => ['left', 'top'], 'TR' => ['right', 'top'], 'BR' => ['right', 'bottom'], 'BL' => ['left', 'bottom']];

	/**
	 * The quarter of an ellipse each corner is, counting anticlockwise from the top right
	 */
	const SEGMENT = ['TR' => 1, 'TL' => 2, 'BL' => 3, 'BR' => 4];

	/**
	 * A quarter of an ellipse centred on ($x0, $y0), run anticlockwise, as four curves. $part 1 or 2 gives the first
	 * or second eighth alone, and $start opens the path at the arc's first point.
	 *
	 * @param int $seg Which quarter, as SEGMENT numbers them
	 */
	public function arc($pageHeight, $x0, $y0, $rx, $ry, $seg = 1, $part = false, $start = false)
	{
		$s = '';

		if ($rx < 0) {
			$rx = 0;
		}

		if ($ry < 0) {
			$ry = 0;
		}

		$rx *= Mpdf::SCALE;
		$ry *= Mpdf::SCALE;

		$from = deg2rad(90 * ($seg - 1));
		$dt = deg2rad(22.5); // segment angle
		$dtm = $dt / 3;
		$x0 *= Mpdf::SCALE;
		$y0 = ($pageHeight - $y0) * Mpdf::SCALE;
		$a0 = $x0 + ($rx * cos($from));
		$b0 = $y0 + ($ry * sin($from));
		$c0 = -$rx * sin($from);
		$d0 = $ry * cos($from);
		$op = false;

		for ($i = 1; $i <= 4; $i++) {
			// Draw this bit of the total curve
			$t1 = $from + ($i * $dt);
			$a1 = $x0 + ($rx * cos($t1));
			$b1 = $y0 + ($ry * sin($t1));
			$c1 = -$rx * sin($t1);
			$d1 = $ry * cos($t1);
			if (!$part || ($part == 1 && $i <= 2) || ($part == 2 && $i > 2)) {
				if ($start && !$op) {
					$s .= sprintf('%.3F %.3F m ', $a0, $b0);
				}
				$s .= sprintf('%.3F %.3F %.3F %.3F %.3F %.3F c ', ($a0 + ($c0 * $dtm)), ($b0 + ($d0 * $dtm)), ($a1 - ($c1 * $dtm)), ($b1 - ($d1 * $dtm)), $a1, $b1);
				$op = true;
			}
			$a0 = $a1;
			$b0 = $b1;
			$c0 = $c1;
			$d0 = $d1;
		}

		return $s;
	}

	/**
	 * A path around the box, run anticlockwise from the top left.
	 */
	public function path($pageHeight, $x0, $y0, $x1, $y1, $radii)
	{
		$s = sprintf('%.3F %.3F m ', ($x0 + $radii['TL'][0]) * Mpdf::SCALE, ($pageHeight - $y0) * Mpdf::SCALE); // start point TL before the arc
		if ($radii['TL'][0] || $radii['TL'][1]) {
			$s .= $this->arc($pageHeight, $x0 + $radii['TL'][0], $y0 + $radii['TL'][1], $radii['TL'][0], $radii['TL'][1], self::SEGMENT['TL']);
		}
		$s .= sprintf('%.3F %.3F l ', $x0 * Mpdf::SCALE, ($pageHeight - ($y1 - $radii['BL'][1])) * Mpdf::SCALE); // line to BL
		if ($radii['BL'][0] || $radii['BL'][1]) {
			$s .= $this->arc($pageHeight, $x0 + $radii['BL'][0], $y1 - $radii['BL'][1], $radii['BL'][0], $radii['BL'][1], self::SEGMENT['BL']);
		}
		$s .= sprintf('%.3F %.3F l ', ($x1 - $radii['BR'][0]) * Mpdf::SCALE, ($pageHeight - $y1) * Mpdf::SCALE); // line to BR
		if ($radii['BR'][0] || $radii['BR'][1]) {
			$s .= $this->arc($pageHeight, $x1 - $radii['BR'][0], $y1 - $radii['BR'][1], $radii['BR'][0], $radii['BR'][1], self::SEGMENT['BR']);
		}
		$s .= sprintf('%.3F %.3F l ', $x1 * Mpdf::SCALE, ($pageHeight - ($y0 + $radii['TR'][1])) * Mpdf::SCALE); // line to TR
		if ($radii['TR'][0] || $radii['TR'][1]) {
			$s .= $this->arc($pageHeight, $x1 - $radii['TR'][0], $y0 + $radii['TR'][1], $radii['TR'][0], $radii['TR'][1], self::SEGMENT['TR']);
		}
		$s .= sprintf('%.3F %.3F l ', ($x0 + $radii['TL'][0]) * Mpdf::SCALE, ($pageHeight - $y0) * Mpdf::SCALE); // line to TL

		return $s;
	}

	/**
	 * Radii fitted to a box: a corner smaller than the border it rounds is dropped, and radii that would overlap
	 * along an edge shrink together until they meet. $borders is keyed top/right/bottom/left.
	 */
	public function fit($width, $height, $radii, $borders)
	{
		foreach (self::CORNER_SIDES as $corner => $sides) {
			if (min($radii[$corner]) < min($borders[$sides[0]], $borders[$sides[1]])) {
				$radii[$corner] = [0, 0];
			}
		}

		$f = min(
			$height / ($radii['TL'][1] + $radii['BL'][1] + 0.001),
			$height / ($radii['TR'][1] + $radii['BR'][1] + 0.001),
			$width / ($radii['TL'][0] + $radii['TR'][0] + 0.001),
			$width / ($radii['BL'][0] + $radii['BR'][0] + 0.001)
		);
		if ($f < 1) {
			foreach ($radii as $corner => $r) {
				$radii[$corner] = [$r[0] * $f, $r[1] * $f];
			}
		}

		return $radii;
	}

	/**
	 * Radii moved in from their edge by what lies along each side, stopping at square: the padding edge's corners
	 * are the border edge's less the borders, and the content edge's are those less the paddings. $insets is keyed
	 * top/right/bottom/left.
	 */
	public function inset($radii, $insets)
	{
		foreach (self::CORNER_SIDES as $corner => $sides) {
			$radii[$corner] = [max(0, $radii[$corner][0] - $insets[$sides[0]]), max(0, $radii[$corner][1] - $insets[$sides[1]])];
		}

		return $radii;
	}

	/**
	 * The border box of an image object, with the radii of its corners fitted to it and those of the content edge
	 * inside it.
	 *
	 * @param array $objattr The image object, with its OUTER box, margins, paddings, borders and border_radius
	 * @param float $k The factor a table has shrunk the object by
	 * @return array Keyed x0, y0, x1, y1, radii and content
	 */
	public function imageBox($objattr, $k)
	{
		$x0 = $objattr['OUTER-X'] + $objattr['margin_left'] / $k;
		$y0 = $objattr['OUTER-Y'] + $objattr['margin_top'] / $k;
		$x1 = $objattr['OUTER-X'] + $objattr['OUTER-WIDTH'] - $objattr['margin_right'] / $k;
		$y1 = $objattr['OUTER-Y'] + $objattr['OUTER-HEIGHT'] - $objattr['margin_bottom'] / $k;

		$borders = [];
		$paddings = [];
		foreach (['top', 'right', 'bottom', 'left'] as $side) {
			$borders[$side] = $objattr['border_' . $side]['w'] / $k;
			$paddings[$side] = $objattr['padding_' . $side] / $k;
		}

		$radii = ['TL' => [0, 0], 'TR' => [0, 0], 'BR' => [0, 0], 'BL' => [0, 0]];
		foreach ($objattr['border_radius'] as $corner => $r) {
			$radii[$corner] = [$r[0] / $k, $r[1] / $k];
		}
		$radii = $this->fit($x1 - $x0, $y1 - $y0, $radii, $borders);

		return [
			'x0' => $x0,
			'y0' => $y0,
			'x1' => $x1,
			'y1' => $y1,
			'radii' => $radii,
			'content' => $this->inset($this->inset($radii, $borders), $paddings),
		];
	}

	/**
	 * One side of a border, along the middle of the border between its two corners. A rounded corner ends the side
	 * with an arc to the diagonal, where the next side takes over; a square one runs the line out to the outer edge.
	 *
	 * @param string $side top, right, bottom or left
	 * @param array $box The border box as imageBox() returns it
	 * @param float $bw The width of this side's border
	 */
	public function side($pageHeight, $side, $box, $bw)
	{
		$x0 = $box['x0'];
		$y0 = $box['y0'];
		$x1 = $box['x1'];
		$y1 = $box['y1'];
		$radii = $box['radii'];
		$half = $bw / 2;
		$centre = [
			'TL' => [$x0 + $radii['TL'][0], $y0 + $radii['TL'][1]],
			'TR' => [$x1 - $radii['TR'][0], $y0 + $radii['TR'][1]],
			'BR' => [$x1 - $radii['BR'][0], $y1 - $radii['BR'][1]],
			'BL' => [$x0 + $radii['BL'][0], $y1 - $radii['BL'][1]],
		];
		// Anticlockwise, from one corner to the next
		$sides = [
			'top' => ['from' => 'TR', 'to' => 'TL', 'start' => [$x1, $y0 + $half], 'tangent' => [$centre['TL'][0], $y0 + $half], 'end' => [$x0, $y0 + $half]],
			'left' => ['from' => 'TL', 'to' => 'BL', 'start' => [$x0 + $half, $y0], 'tangent' => [$x0 + $half, $centre['BL'][1]], 'end' => [$x0 + $half, $y1]],
			'bottom' => ['from' => 'BL', 'to' => 'BR', 'start' => [$x0, $y1 - $half], 'tangent' => [$centre['BR'][0], $y1 - $half], 'end' => [$x1, $y1 - $half]],
			'right' => ['from' => 'BR', 'to' => 'TR', 'start' => [$x1 - $half, $y1], 'tangent' => [$x1 - $half, $centre['TR'][1]], 'end' => [$x1 - $half, $y0]],
		];
		$run = $sides[$side];
		$from = $run['from'];
		$to = $run['to'];

		if ($radii[$from][0]) {
			$s = $this->arc($pageHeight, $centre[$from][0], $centre[$from][1], $radii[$from][0] - $half, $radii[$from][1] - $half, self::SEGMENT[$from], 2, true);
		} else {
			$s = sprintf('%.3F %.3F m ', $run['start'][0] * Mpdf::SCALE, ($pageHeight - $run['start'][1]) * Mpdf::SCALE);
		}
		if ($radii[$to][0]) {
			$s .= sprintf('%.3F %.3F l ', $run['tangent'][0] * Mpdf::SCALE, ($pageHeight - $run['tangent'][1]) * Mpdf::SCALE);
			$s .= $this->arc($pageHeight, $centre[$to][0], $centre[$to][1], $radii[$to][0] - $half, $radii[$to][1] - $half, self::SEGMENT[$to], 1);
		} else {
			$s .= sprintf('%.3F %.3F l ', $run['end'][0] * Mpdf::SCALE, ($pageHeight - $run['end'][1]) * Mpdf::SCALE);
		}

		return $s;
	}
}
