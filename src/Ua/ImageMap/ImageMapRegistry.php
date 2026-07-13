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

			// PDF/UA-1 (audit E20) — a poly/polygon hotspot must not activate the
			// whole bounding box. Tile the polygon interior with /QuadPoints (one
			// degenerate quad per triangle) so the clickable region approximates
			// the shape; /Rect stays the bounding box. Works for both axis-aligned
			// hosts (synthesise the plain placement matrix) and rotated/transformed
			// ones (reuse the captured render matrix — C2 machinery).
			$poly = $this->polygonPoints($area['shape'], $area['coords']);
			if ($poly !== null) {
				$devMatrix = $matrix !== null
					? $matrix
					: $this->buildAxisAlignedMatrix($imgX, $imgY, $imgW, $imgH, $origW, $origH);
				$quads = $this->polygonQuads($poly, $devMatrix);
				if ($quads !== null) {
					$this->emitQuads($area, $quads);
					continue;
				}
				// Non-simple/degenerate polygon that could not be triangulated:
				// fall through to the bounding-box path so the link still emits.
			}

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
			$this->emitQuads($area, [$c1[0], $c1[1], $c2[0], $c2[1], $c3[0], $c3[1], $c4[0], $c4[1]]);
		}
	}

	/**
	 * Emit one Link annotation for a hotspot already reduced to device-space
	 * /QuadPoints (8·n floats, n≥1). /Rect is the quads' axis-aligned bounding
	 * box back-converted to user space so Mpdf::Link() reproduces it. Shared by
	 * the rotated single-quad path and the polygon-tiling path (audit E20).
	 *
	 * @param  array<string,mixed> $area
	 * @param  float[]             $quads  device-space /QuadPoints (8·n floats)
	 * @return void
	 */
	private function emitQuads(array $area, array $quads)
	{
		$xs = [];
		$ys = [];
		$count = count($quads);
		for ($i = 0; $i < $count; $i += 2) {
			$xs[] = $quads[$i];
			$ys[] = $quads[$i + 1];
		}
		$minx = min($xs);
		$maxx = max($xs);
		$miny = min($ys);
		$maxy = max($ys);
		if ($maxx - $minx <= 0 || $maxy - $miny <= 0) {
			return;
		}
		$scale = Mpdf::SCALE;
		$this->emitAreaLink(
			$area,
			$minx / $scale,
			($this->mpdf->hPt - $maxy) / $scale,
			($maxx - $minx) / $scale,
			($maxy - $miny) / $scale,
			$quads
		);
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
	 * Build the pixel-space -> device-space affine for an axis-aligned host
	 * image (no rotate/transform), so the polygon-tiling path (audit E20) can
	 * map hotspot vertices the same way the rotated path maps them via
	 * buildHotspotMatrix(). Pixel origin is top-left; device origin is
	 * bottom-left (y up), matching Mpdf::Link()'s y-flip.
	 *
	 * @param  float $imgX
	 * @param  float $imgY
	 * @param  float $imgW
	 * @param  float $imgH
	 * @param  float $origW
	 * @param  float $origH
	 * @return float[]  [a, b, c, d, e, f]
	 */
	private function buildAxisAlignedMatrix($imgX, $imgY, $imgW, $imgH, $origW, $origH)
	{
		$scale = Mpdf::SCALE;
		$sx = ($imgW * $scale) / $origW;
		$sy = ($imgH * $scale) / $origH;
		return [$sx, 0.0, 0.0, -$sy, $imgX * $scale, $this->mpdf->hPt - $imgY * $scale];
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
	 * Return a poly/polygon area's vertices in image-pixel space, or null when
	 * the shape is not a polygon or its coords are malformed. Used by the E20
	 * tiling path; every other shape keeps the plain shapeToRect() bounding box.
	 *
	 * @param  string  $shape
	 * @param  float[] $coords
	 * @return array<int,float[]>|null  list of [x, y] vertices, or null
	 */
	private function polygonPoints($shape, array $coords)
	{
		if ($shape !== 'poly' && $shape !== 'polygon') {
			return null;
		}
		$n = count($coords);
		if ($n < 6 || $n % 2 !== 0) {
			return null;
		}
		$pts = [];
		for ($i = 0; $i < $n; $i += 2) {
			$pts[] = [(float) $coords[$i], (float) $coords[$i + 1]];
		}
		return $pts;
	}

	/**
	 * Tile a polygon (pixel-space vertices) with device-space /QuadPoints: ear-clip
	 * it into triangles, then map each triangle through $matrix and emit it as a
	 * degenerate quad (its third vertex repeated as the fourth). The union of the
	 * quads approximates the polygon interior. Returns null when the polygon is
	 * non-simple/degenerate and cannot be triangulated, so the caller falls back to
	 * the bounding box. ISO 32000-1 §12.5.6.5.
	 *
	 * @param  array<int,float[]> $poly    pixel-space vertices [[x, y], …]
	 * @param  float[]            $matrix  pixel-space -> device-space affine
	 * @return float[]|null                flat list of 8·n device-space floats, or null
	 */
	private function polygonQuads(array $poly, array $matrix)
	{
		$tris = $this->triangulatePolygon($poly);
		if (empty($tris)) {
			return null;
		}
		$dev = [];
		foreach ($poly as $i => $pt) {
			$dev[$i] = $this->applyMatrix($matrix, $pt[0], $pt[1]);
		}
		$quads = [];
		foreach ($tris as $t) {
			$a = $dev[$t[0]];
			$b = $dev[$t[1]];
			$c = $dev[$t[2]];
			array_push($quads, $a[0], $a[1], $b[0], $b[1], $c[0], $c[1], $c[0], $c[1]);
		}
		return $quads;
	}

	/**
	 * Ear-clipping triangulation of a simple polygon (convex or concave), after
	 * John W. Ratcliff's classic algorithm. Returns a list of index triples into
	 * $pts, or an empty list when the polygon is non-simple/degenerate (self-
	 * intersecting or zero-area) and no valid triangulation exists.
	 *
	 * @param  array<int,float[]> $pts  vertices [[x, y], …]
	 * @return array<int,int[]>         list of [i, j, k] index triples
	 */
	private function triangulatePolygon(array $pts)
	{
		$n = count($pts);
		if ($n < 3) {
			return [];
		}
		// Orient the working index ring counter-clockwise so the ear-convexity
		// sign test is consistent regardless of the source winding.
		$V = [];
		if ($this->polygonArea($pts) > 0.0) {
			for ($i = 0; $i < $n; $i++) {
				$V[$i] = $i;
			}
		} else {
			for ($i = 0; $i < $n; $i++) {
				$V[$i] = ($n - 1) - $i;
			}
		}
		$tris = [];
		$nv    = $n;
		$count = 2 * $nv; // failsafe against a non-simple polygon looping forever
		$v = $nv - 1;
		while ($nv > 2) {
			if (($count--) <= 0) {
				return []; // non-simple polygon: caller falls back to the bbox
			}
			$u = $v >= $nv ? 0 : $v;
			$v = $u + 1 >= $nv ? 0 : $u + 1;
			$w = $v + 1 >= $nv ? 0 : $v + 1;
			if ($this->polygonSnip($pts, $V[$u], $V[$v], $V[$w], $nv, $V)) {
				$tris[] = [$V[$u], $V[$v], $V[$w]];
				// Remove the clipped ear tip (vertex v) from the ring.
				for ($s = $v, $t = $v + 1; $t < $nv; $s++, $t++) {
					$V[$s] = $V[$t];
				}
				$nv--;
				$count = 2 * $nv;
			}
		}
		return $tris;
	}

	/**
	 * Signed area of a polygon (shoelace); positive for counter-clockwise winding.
	 *
	 * @param  array<int,float[]> $pts
	 * @return float
	 */
	private function polygonArea(array $pts)
	{
		$n    = count($pts);
		$area = 0.0;
		for ($p = $n - 1, $q = 0; $q < $n; $p = $q++) {
			$area += $pts[$p][0] * $pts[$q][1] - $pts[$q][0] * $pts[$p][1];
		}
		return $area * 0.5;
	}

	/**
	 * Ear test for the ear-clipping triangulator: true when triangle (a, b, c) is
	 * convex (CCW) and no other remaining vertex lies inside it.
	 *
	 * @param  array<int,float[]> $pts
	 * @param  int                $a
	 * @param  int                $b
	 * @param  int                $c
	 * @param  int                $nv  number of live vertices in $V
	 * @param  int[]              $V   live index ring
	 * @return bool
	 */
	private function polygonSnip(array $pts, $a, $b, $c, $nv, array $V)
	{
		$eps = 1e-9;
		$ax = $pts[$a][0];
		$ay = $pts[$a][1];
		$bx = $pts[$b][0];
		$by = $pts[$b][1];
		$cx = $pts[$c][0];
		$cy = $pts[$c][1];
		if ($eps > (($bx - $ax) * ($cy - $ay) - ($by - $ay) * ($cx - $ax))) {
			return false; // reflex (or collinear) vertex — not an ear
		}
		for ($p = 0; $p < $nv; $p++) {
			$idx = $V[$p];
			if ($idx === $a || $idx === $b || $idx === $c) {
				continue;
			}
			if ($this->pointInTriangle($ax, $ay, $bx, $by, $cx, $cy, $pts[$idx][0], $pts[$idx][1])) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Barycentric-sign point-in-triangle test (inclusive of the edges), assuming a
	 * counter-clockwise triangle (a, b, c).
	 *
	 * @return bool
	 */
	private function pointInTriangle($ax, $ay, $bx, $by, $cx, $cy, $px, $py)
	{
		$ax0 = $cx - $bx;
		$ay0 = $cy - $by;
		$bx0 = $ax - $cx;
		$by0 = $ay - $cy;
		$cx0 = $bx - $ax;
		$cy0 = $by - $ay;
		$apx = $px - $ax;
		$apy = $py - $ay;
		$bpx = $px - $bx;
		$bpy = $py - $by;
		$cpx = $px - $cx;
		$cpy = $py - $cy;
		$aCrossBp = $ax0 * $bpy - $ay0 * $bpx;
		$cCrossAp = $cx0 * $apy - $cy0 * $apx;
		$bCrossCp = $bx0 * $cpy - $by0 * $cpx;
		return $aCrossBp >= 0.0 && $bCrossCp >= 0.0 && $cCrossAp >= 0.0;
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
