<?php

namespace Mpdf\Ua\ImageMap;

use Mpdf\Mpdf;
use Mpdf\Ua\AnchorState;
use Mpdf\Ua\StructureTree;

/**
 * PDF/UA-1 — registry, deferred queue, and emission for HTML image maps
 * (`<img usemap="#name">` referencing `<map name="name">…<area>…</map>`).
 *
 * Replaces the legacy properties `$mpdf->pdfUaImageMaps`,
 * `$mpdf->pdfUaCurrentMapName`, `$mpdf->pdfUaDeferredImageMaps` and the three
 * private methods that lived on Mpdf.php (`processDeferredImageMaps`,
 * `emitImageMapLinks`, `imageMapShapeToRect`). Functionally identical, but
 * the state and behaviour live entirely under UaState now.
 *
 * Lifecycle:
 *   1. Tag\Map::open()  → openMap()  — register the named map.
 *   2. Tag\Area::open() → addArea()  — append a hotspot to the open map.
 *   3. Tag\Map::close() → closeMap() — clear the current-map cursor.
 *   4. Mpdf::printobjectbuffer() (image branch) → queueDeferred() — record
 *      the placed-image rectangle so we can emit Link annotations once the
 *      <map> registry is final (HTML5 §4.8.13 allows <map> after the host).
 *   5. Mpdf::WriteHTML() close path → drain() — process queued items.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §12.5.6.5 — Link annotation /Rect /A /Contents
 *   - ISO 32000-1:2008 §14.8 Table 335 — Link struct element with OBJR kid
 *   - ISO 14289-1:2014 §7.18 — interactive content tagging
 *   - Matterhorn 28-002 — Link annotation needs a text alternative
 *
 * @see \Mpdf\Tag\Map
 * @see \Mpdf\Tag\Area
 * @see \Mpdf\Ua\StructureTree
 * @see \Mpdf\Ua\AnchorState
 */
class ImageMapRegistry
{

	/** @var Mpdf */
	private $mpdf;

	/** @var StructureTree */
	private $structureTree;

	/** @var AnchorState */
	private $anchorState;

	/**
	 * map name (lowercased) → list of area descriptors:
	 *   ['shape' => string, 'coords' => float[], 'href' => string,
	 *    'alt' => string, 'target' => ?string]
	 *
	 * @var array<string,array<int,array<string,mixed>>>
	 */
	private $maps = [];

	/**
	 * Lowercased name of the <map> currently being parsed, or null when no
	 * <map> is open.
	 *
	 * @var string|null
	 */
	private $currentMapName = null;

	/**
	 * Deferred queue: one entry per <img usemap> seen in the layout pass that
	 * is awaiting a final <map> registry before its Link annotations can be
	 * emitted.
	 *
	 * Entry shape: ['mapName'=>string, 'page'=>int, 'pageHpt'=>float,
	 * 'imgX'=>float, 'imgY'=>float, 'imgW'=>float, 'imgH'=>float,
	 * 'origW'=>float, 'origH'=>float, 'transformCm'=>string,
	 * 'figure'=>?StructureElement].
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private $deferred = [];

	/**
	 * Lazy callback set by setLazyUaWiring(); used to resolve the UaState
	 * reference for warning emission. Avoids a constructor-time cycle
	 * (UaState owns this class).
	 *
	 * @var callable|null
	 */
	private $uaResolver = null;

	/**
	 * @param Mpdf          $mpdf           host Mpdf for object-number allocation, page mutation, internal-link map
	 * @param StructureTree $structureTree  push the host Figure / open Link struct elements
	 * @param AnchorState   $anchorState    capture the per-area Link element ref for Mpdf::Link()
	 */
	public function __construct(Mpdf $mpdf, StructureTree $structureTree, AnchorState $anchorState)
	{
		$this->mpdf          = $mpdf;
		$this->structureTree = $structureTree;
		$this->anchorState   = $anchorState;
	}

	/**
	 * Wire a callback that returns the UaState facade for warning emission.
	 *
	 * Used by ServiceFactory to break the construction cycle: UaState owns
	 * this class, so we cannot accept it as a constructor dep. The factory
	 * calls setLazyUaWiring(function () use ($uaState) { return $uaState; })
	 * after the facade has been built.
	 *
	 * @param  callable $resolver  () → UaState
	 * @return void
	 */
	public function setLazyUaWiring($resolver)
	{
		$this->uaResolver = $resolver;
	}

	/**
	 * Register a new map (Tag\Map::open). Idempotent — repeated open of the
	 * same name preserves any areas already added.
	 *
	 * @param  string $name  lowercased map name
	 * @return void
	 */
	public function openMap($name)
	{
		if (!isset($this->maps[$name])) {
			$this->maps[$name] = [];
		}
		$this->currentMapName = $name;
	}

	/**
	 * Clear the current-map cursor (Tag\Map::close).
	 *
	 * @return void
	 */
	public function closeMap()
	{
		$this->currentMapName = null;
	}

	public function getCurrentMapName()
	{
		return $this->currentMapName;
	}

	/**
	 * Return the full registry (map name → list of area descriptors).
	 *
	 * Primarily intended for tests that introspect the parsed <map>/<area>
	 * structure; production code should not snapshot the registry — it is
	 * drained automatically at the end of WriteHTML().
	 *
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	public function getMaps()
	{
		return $this->maps;
	}

	/**
	 * Append a hotspot to the open map (Tag\Area::open). No-op if no map is
	 * currently open — caller (Tag\Area) is responsible for warning when
	 * <area> appears outside any <map>.
	 *
	 * @param  string  $shape   'rect' | 'circle' | 'poly' | 'default'
	 * @param  float[] $coords  numeric coords (already parsed by Tag\Area)
	 * @param  string  $href
	 * @param  string  $alt
	 * @param  ?string $target
	 * @return void
	 */
	public function addArea($shape, array $coords, $href, $alt, $target)
	{
		if ($this->currentMapName === null) {
			return;
		}
		$this->maps[$this->currentMapName][] = [
			'shape'  => $shape,
			'coords' => $coords,
			'href'   => $href,
			'alt'    => $alt,
			'target' => $target,
		];
	}

	/**
	 * Record one host <img usemap> placement for later emission. Called from
	 * Mpdf::printobjectbuffer() when the image's placed rectangle is known.
	 *
	 * @param  array<string,mixed> $entry
	 * @return void
	 */
	public function queueDeferred(array $entry)
	{
		$this->deferred[] = $entry;
	}

	public function hasDeferred()
	{
		return !empty($this->deferred);
	}

	/**
	 * Drain the deferred queue. Called from Mpdf::WriteHTML()'s close path
	 * once the entire HTML has been parsed and the <map> registry is final.
	 *
	 * For each queued image:
	 *   1. Resolve the map by name (warn-and-skip if unknown).
	 *   2. Push the host Figure struct element back onto the StructureTree
	 *      stack so the Link kids parent under it.
	 *   3. Switch $mpdf->page to the captured page so Mpdf::Link() routes
	 *      the annotation onto the correct page's PageLinks array.
	 *   4. Call emitForImage() to do the per-area work.
	 *   5. Restore $mpdf->page and pop the Figure.
	 *
	 * @return void
	 */
	public function drain()
	{
		$savedPage = $this->mpdf->page;
		$savedHpt  = $this->mpdf->hPt;
		foreach ($this->deferred as $deferred) {
			$mapName = $deferred['mapName'];
			if (!isset($this->maps[$mapName])) {
				$this->enforce(
					'PDF/UA-1: <img usemap="#' . $mapName . '"> references unknown map "' . $mapName
					. '"; no link annotations can be emitted. Define a matching <map name="' . $mapName
					. '"> or enable PDFUAauto to skip the image map.'
				);
				continue;
			}
			$figureElem = $deferred['figure'];
			$this->mpdf->page = $deferred['page'];
			// Mpdf::Link() flips y with the live $mpdf->hPt. At drain time hPt
			// holds the *final* page's height, so restore the host image's page
			// height too — otherwise hotspots land at the wrong y on documents
			// that mix page sizes / orientations.
			$this->mpdf->hPt = $deferred['pageHpt'];
			if ($figureElem !== null) {
				$this->structureTree->pushExisting($figureElem);
			}
			$this->emitForImage(
				$this->maps[$mapName],
				$deferred['imgX'],
				$deferred['imgY'],
				$deferred['imgW'],
				$deferred['imgH'],
				$deferred['origW'],
				$deferred['origH'],
				$this->buildHotspotMatrix($deferred)
			);
			if ($figureElem !== null) {
				$this->structureTree->close();
			}
		}
		$this->deferred = [];
		$this->mpdf->page = $savedPage;
		$this->mpdf->hPt  = $savedHpt;
	}

	/**
	 * Emit one PDF Link annotation + Link struct element per <area> on a host
	 * <img usemap>.
	 *
	 * @param  array<int,array<string,mixed>> $areas
	 * @param  float $imgX   inner-X of the placed image (user units)
	 * @param  float $imgY   inner-Y of the placed image (user units, top-left)
	 * @param  float $imgW   placed image width in user units
	 * @param  float $imgH   placed image height in user units
	 * @param  float $origW  source image width in pixels
	 * @param  float $origH  source image height in pixels
	 * @param  float[]|null $matrix  pixel-space -> device-space affine for a
	 *                               rotated/transformed host (emits /QuadPoints),
	 *                               or null for an axis-aligned host (plain /Rect)
	 * @return void
	 */
	private function emitForImage(array $areas, $imgX, $imgY, $imgW, $imgH, $origW, $origH, array $matrix = null)
	{
		if ($origW <= 0 || $origH <= 0 || $imgW <= 0 || $imgH <= 0) {
			return;
		}
		foreach ($areas as $area) {
			$rect = $this->shapeToRect($area['shape'], $area['coords'], $origW, $origH);
			if ($rect === null) {
				$this->enforce(
					'PDF/UA-1: <area shape="' . $area['shape'] . '"> coords malformed; the hotspot '
					. 'cannot be placed. Fix the coords or enable PDFUAauto to skip the area.'
				);
				continue;
			}
			list($x1, $y1, $x2, $y2) = $rect;

			if ($matrix === null) {
				$sx = $imgW / $origW;
				$sy = $imgH / $origH;
				$rx = $imgX + $x1 * $sx;
				$ry = $imgY + $y1 * $sy;
				$rw = ($x2 - $x1) * $sx;
				$rh = ($y2 - $y1) * $sy;
				if ($rw <= 0 || $rh <= 0) {
					continue;
				}
				$this->emitAreaLink($area, $rx, $ry, $rw, $rh, null);
				continue;
			}

			// Rotated/transformed host image: map the four pixel-space corners of
			// the hotspot through the exact render matrix into device space and
			// emit them as a /QuadPoints quad. /Rect is the quad's bounding box,
			// back-converted to user space so Mpdf::Link() reproduces it.
			$c1 = $this->applyMatrix($matrix, $x1, $y1);
			$c2 = $this->applyMatrix($matrix, $x2, $y1);
			$c3 = $this->applyMatrix($matrix, $x2, $y2);
			$c4 = $this->applyMatrix($matrix, $x1, $y2);
			$quad = [$c1[0], $c1[1], $c2[0], $c2[1], $c3[0], $c3[1], $c4[0], $c4[1]];
			$minx = min($c1[0], $c2[0], $c3[0], $c4[0]);
			$maxx = max($c1[0], $c2[0], $c3[0], $c4[0]);
			$miny = min($c1[1], $c2[1], $c3[1], $c4[1]);
			$maxy = max($c1[1], $c2[1], $c3[1], $c4[1]);
			if ($maxx - $minx <= 0 || $maxy - $miny <= 0) {
				continue;
			}
			$scale = Mpdf::SCALE;
			$this->emitAreaLink(
				$area,
				$minx / $scale,
				($this->mpdf->hPt - $maxy) / $scale,
				($maxx - $minx) / $scale,
				($maxy - $miny) / $scale,
				$quad
			);
		}
	}

	/**
	 * Emit one Link struct element + PDF Link annotation for a single <area>.
	 *
	 * Shared by the axis-aligned (/Rect) and rotated (/QuadPoints) paths: only
	 * the geometry differs, so the struct-element open, href resolution and
	 * Mpdf::Link() call live here. $quad is null for an axis-aligned hotspot.
	 *
	 * @param  array<string,mixed> $area
	 * @param  float        $rx    annotation rect x (user units)
	 * @param  float        $ry    annotation rect y (user units, top-left)
	 * @param  float        $rw    annotation rect width (user units)
	 * @param  float        $rh    annotation rect height (user units)
	 * @param  float[]|null $quad  device-space /QuadPoints (8 floats), or null
	 * @return void
	 */
	private function emitAreaLink(array $area, $rx, $ry, $rw, $rh, $quad)
	{
		// Open a Link struct element under the active Figure (top of stack).
		// Mirrors Tag\A::open(): Alt is the area's alt text (Matterhorn 28-002),
		// _href is stashed so any strict-mode pruning diagnostic can quote it.
		$this->structureTree->open('Link', ['Alt' => $area['alt']]);
		$linkElem = $this->structureTree->getCurrent();
		$linkElem->setAttribute('_href', $area['href']);
		$this->anchorState->setLinkStructElem($linkElem);

		// Resolve href: "#frag" is an internal GoTo, anything else a URI action.
		$href = $area['href'];
		if (isset($href[0]) && $href[0] === '#') {
			$target = substr($href, 1);
			// UA1 audit L-6 defence-in-depth iteration cap. Each iteration
			// prepends a '#' so the loop terminates as soon as the prefixed key
			// is unused; an adversarial $internallink shape could in theory keep
			// extending it. 1024 prefix chars is far past any realistic anchor
			// collision and well below memory pressure.
			$collisionGuard = 0;
			while (array_key_exists($target, $this->mpdf->internallink)) {
				$target = '#' . $target;
				if (++$collisionGuard >= 1024) {
					$this->enforce(
						'PDF/UA-1: <area href="#' . substr($area['href'], 1)
						. '"> internal-link disambiguation exceeded 1024 iterations. '
						. 'Fix the anchor collision or enable PDFUAauto to emit an external link instead.'
					);
					$target = null;
					break;
				}
			}
			if ($target === null) {
				$linkRef = $href;
			} else {
				if (!isset($this->mpdf->internallink[$target])) {
					$this->mpdf->internallink[$target] = $this->mpdf->AddLink();
				}
				$linkRef = $this->mpdf->internallink[$target];
			}
		} else {
			$linkRef = $href;
		}

		$this->mpdf->Link($rx, $ry, $rw, $rh, $linkRef, $quad);

		$this->anchorState->clearLinkStructElem();
		$this->structureTree->close();
	}

	/**
	 * Build the device-space affine matrix mapping a host image's pixel-space
	 * coordinates (origin top-left) to PDF default user space, or null when the
	 * image is axis-aligned (no rotate/transform) and a plain /Rect suffices.
	 *
	 * Replays the exact content-stream matrices mPDF draws the image with (the
	 * image placement cm, then the captured $tr rotate + $tr2 CSS transform)
	 * so every hotspot quad aligns with the drawn pixels by construction.
	 * ISO 32000-1 Sec 8.3.4 (CTM concatenation).
	 *
	 * @param  array<string,mixed> $entry  a drained deferred image-map entry
	 * @return float[]|null                 [a, b, c, d, e, f], or null
	 */
	private function buildHotspotMatrix(array $entry)
	{
		$cm = isset($entry['transformCm']) ? $entry['transformCm'] : '';
		if ($cm === '') {
			return null;
		}
		$scale = Mpdf::SCALE;
		// Image placement matrix: unit square to device rect (the raster
		// "... cm /I Do" operator in Mpdf::printobjectbuffer()).
		$imageCm = [
			$entry['imgW'] * $scale, 0.0,
			0.0, $entry['imgH'] * $scale,
			$entry['imgX'] * $scale,
			$entry['pageHpt'] - ($entry['imgY'] + $entry['imgH']) * $scale,
		];
		// CTM at the Do = placement matrix pre-multiplied onto the captured
		// matrices folded in issue order (each cm left-multiplies the CTM).
		$pre = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
		foreach ($this->parseCmMatrices($cm) as $m) {
			$pre = $this->matmul($m, $pre);
		}
		$ctm = $this->matmul($imageCm, $pre);
		// Pixel space (top-left origin) to image unit square (bottom-left).
		$pixelToUnit = [1.0 / $entry['origW'], 0.0, 0.0, -1.0 / $entry['origH'], 0.0, 1.0];
		return $this->matmul($pixelToUnit, $ctm);
	}

	/**
	 * Extract every "a b c d e f cm" matrix from a content-stream fragment, in
	 * the order they appear.
	 *
	 * @param  string $cm
	 * @return array<int,float[]>  list of [a, b, c, d, e, f]
	 */
	private function parseCmMatrices($cm)
	{
		$num = '(-?\d+(?:\.\d+)?)';
		$re  = '/' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+' . $num . '\s+cm/';
		if (!preg_match_all($re, $cm, $matches, PREG_SET_ORDER)) {
			return [];
		}
		$out = [];
		foreach ($matches as $m) {
			$out[] = [(float) $m[1], (float) $m[2], (float) $m[3], (float) $m[4], (float) $m[5], (float) $m[6]];
		}
		return $out;
	}

	/**
	 * Multiply two affine matrices [a b c d e f] (row-vector convention, third
	 * column implicitly [0 0 1]).
	 *
	 * @param  float[] $a
	 * @param  float[] $b
	 * @return float[]  $a times $b
	 */
	private function matmul(array $a, array $b)
	{
		return [
			$a[0] * $b[0] + $a[1] * $b[2],
			$a[0] * $b[1] + $a[1] * $b[3],
			$a[2] * $b[0] + $a[3] * $b[2],
			$a[2] * $b[1] + $a[3] * $b[3],
			$a[4] * $b[0] + $a[5] * $b[2] + $b[4],
			$a[4] * $b[1] + $a[5] * $b[3] + $b[5],
		];
	}

	/**
	 * Apply an affine matrix to a point.
	 *
	 * @param  float[] $m
	 * @param  float   $x
	 * @param  float   $y
	 * @return float[]  [x', y']
	 */
	private function applyMatrix(array $m, $x, $y)
	{
		return [
			$x * $m[0] + $y * $m[2] + $m[4],
			$x * $m[1] + $y * $m[3] + $m[5],
		];
	}

	/**
	 * Convert an HTML image-map shape + coords into an axis-aligned rectangle
	 * in image-pixel space.
	 *
	 * @param  string  $shape   'rect' | 'circle' | 'poly' | 'polygon' | 'default'
	 * @param  float[] $coords
	 * @param  float   $origW
	 * @param  float   $origH
	 * @return float[]|null  [x1, y1, x2, y2] in image-pixel space, or null if invalid
	 */
	private function shapeToRect($shape, $coords, $origW, $origH)
	{
		switch ($shape) {
			case 'rect':
			case 'rectangle':
				if (count($coords) < 4) {
					return null;
				}
				return [
					min($coords[0], $coords[2]), min($coords[1], $coords[3]),
					max($coords[0], $coords[2]), max($coords[1], $coords[3]),
				];
			case 'default':
				return [0.0, 0.0, (float) $origW, (float) $origH];
			case 'circle':
			case 'circ':
				if (count($coords) < 3) {
					return null;
				}
				$cx = $coords[0];
				$cy = $coords[1];
				$r  = $coords[2];
				if ($r <= 0) {
					return null;
				}
				return [$cx - $r, $cy - $r, $cx + $r, $cy + $r];
			case 'poly':
			case 'polygon':
				if (count($coords) < 6 || count($coords) % 2 !== 0) {
					return null;
				}
				$xs = [];
				$ys = [];
				$n  = count($coords);
				for ($i = 0; $i < $n; $i += 2) {
					$xs[] = $coords[$i];
					$ys[] = $coords[$i + 1];
				}
				return [min($xs), min($ys), max($xs), max($ys)];
			default:
				return null;
		}
	}

	/**
	 * Apply the branch's strict/auto policy to an image-map conformance
	 * violation detected during drain/emission.
	 *
	 * PDFUAauto=false (strict): throw MpdfException naming the offending
	 * map/area so the document fails loudly rather than shipping partial or
	 * mis-placed link output. PDFUAauto=true: record the diagnostic as a
	 * warning and let the caller skip the offending item. Mirrors the
	 * strict/auto branches the tag handlers use (Tag\Area::open,
	 * Tag\A::open) — the registry runs after the tag handlers (at drain time)
	 * but must honour the same contract.
	 *
	 * @param  string $msg
	 * @return void
	 * @throws \Mpdf\MpdfException in strict mode
	 */
	private function enforce($msg)
	{
		if (empty($this->mpdf->PDFUAauto)) {
			throw new \Mpdf\MpdfException($msg);
		}
		$this->warn($msg);
	}

	/**
	 * Emit a PDFUAauto warning via the lazy UaState resolver if wired.
	 *
	 * @param  string $msg
	 * @return void
	 */
	private function warn($msg)
	{
		if ($this->uaResolver === null) {
			return;
		}
		$resolver = $this->uaResolver;
		$ua       = $resolver();
		if ($ua !== null) {
			$ua->addWarning($msg);
		}
	}
}
