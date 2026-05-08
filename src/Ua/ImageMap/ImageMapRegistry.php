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
	 * Entry shape: ['mapName'=>string, 'page'=>int, 'imgX'=>float,
	 * 'imgY'=>float, 'imgW'=>float, 'imgH'=>float, 'origW'=>float,
	 * 'origH'=>float, 'figure'=>?StructureElement].
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
		foreach ($this->deferred as $deferred) {
			$mapName = $deferred['mapName'];
			if (!isset($this->maps[$mapName])) {
				$this->warn(
					'PDF/UA-1: <img usemap="#' . $mapName . '"> references unknown map; '
					. 'no link annotations emitted.'
				);
				continue;
			}
			$figureElem = $deferred['figure'];
			$this->mpdf->page = $deferred['page'];
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
				$deferred['origH']
			);
			if ($figureElem !== null) {
				$this->structureTree->close();
			}
		}
		$this->deferred = [];
		$this->mpdf->page = $savedPage;
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
	 * @return void
	 */
	private function emitForImage(array $areas, $imgX, $imgY, $imgW, $imgH, $origW, $origH)
	{
		if ($origW <= 0 || $origH <= 0 || $imgW <= 0 || $imgH <= 0) {
			return;
		}
		$sx = $imgW / $origW;
		$sy = $imgH / $origH;
		foreach ($areas as $area) {
			$rect = $this->shapeToRect($area['shape'], $area['coords'], $origW, $origH);
			if ($rect === null) {
				$this->warn(
					'PDF/UA-1: <area shape="' . $area['shape'] . '"> coords malformed; skipped.'
				);
				continue;
			}
			list($x1, $y1, $x2, $y2) = $rect;
			$rx = $imgX + $x1 * $sx;
			$ry = $imgY + $y1 * $sy;
			$rw = ($x2 - $x1) * $sx;
			$rh = ($y2 - $y1) * $sy;

			if ($rw <= 0 || $rh <= 0) {
				continue;
			}

			// Open a Link struct element under the active Figure (top of stack).
			// Mirrors Tag\A::open() — Alt is the area's alt text (Matterhorn 28-002),
			// _href is stashed so any strict-mode pruning diagnostic can quote it.
			$structAttrs = ['Alt' => $area['alt']];
			$this->structureTree->open('Link', $structAttrs);
			$linkElem = $this->structureTree->getCurrent();
			$linkElem->setAttribute('_href', $area['href']);
			$this->anchorState->setLinkStructElem($linkElem);

			// Resolve href: "#frag" → internal GoTo, anything else → URI action.
			$href = $area['href'];
			if (isset($href[0]) && $href[0] === '#') {
				$target = substr($href, 1);
				// UA1 audit L-6 — defence-in-depth iteration cap. Each
				// iteration prepends a '#' so the loop terminates as soon as
				// the prefixed key is unused; an adversarial $internallink
				// shape could in theory keep extending it. 1024 prefix chars
				// is far past any realistic anchor-name collision and well
				// below memory pressure.
				$collisionGuard = 0;
				while (array_key_exists($target, $this->mpdf->internallink)) {
					$target = '#' . $target;
					if (++$collisionGuard >= 1024) {
						$this->warn(
							'PDF/UA-1: <area href="#' . substr($area['href'], 1)
							. '"> internal-link disambiguation exceeded 1024 '
							. 'iterations; emitting external link instead.'
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

			$this->mpdf->Link($rx, $ry, $rw, $rh, $linkRef);

			$this->anchorState->clearLinkStructElem();
			$this->structureTree->close();
		}
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
