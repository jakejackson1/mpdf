<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 policy decisions that need to be applied at multiple call sites.
 *
 * The class is intentionally thin: each method is a pure predicate / scalar
 * helper with no state, so it can be referenced from tag handlers, writers,
 * and tests without dragging in the wider service container.
 *
 * Currently scoped to URL scheme policy (javascript:/vbscript: hrefs in <a>
 * elements). Expand cautiously — anything more stateful belongs on UaState,
 * not here.
 */
class UaPolicy
{

	/**
	 * Schemes that are uniformly non-functional in PDF readers AND have no
	 * accessible alternative. AT (NVDA, JAWS, VoiceOver) announce them as
	 * dead links; readers (Reader, Preview, Foxit, Chromium PDFium) refuse
	 * to execute them. ISO 14289-1:2014 §7.18 / Matterhorn 17-001 + 28-002.
	 *
	 * Detection is intentionally tolerant of:
	 *   - Leading whitespace (` javascript:foo()`).
	 *   - Mixed case (`JavaScript:`, `JAVASCRIPT:`, `jAvAsCrIpT:`).
	 *   - Tab / vertical-whitespace prefixes (`\tjavascript:...`).
	 *   - Whitespace between scheme name and colon (`javascript :...`),
	 *     because some browsers tolerate it and a permissive author may
	 *     still expect the scheme semantics.
	 *
	 * Schemes deliberately NOT covered here:
	 *   - `data:`  — has a navigable target; readers handle it; AT can
	 *               announce it. Out of scope for the L2 audit.
	 *   - `file:`  — privacy concern, not an accessibility concern.
	 *   - `mailto:`, `tel:`, `sms:`, `http:`, `https:`, `ftp:`, `#anchor`,
	 *               relative paths — all permitted.
	 *
	 * @param  string|null $href Raw href as it appeared in the HTML source.
	 * @return bool
	 */
	public static function isPolicyBlockedHref($href)
	{
		if ($href === null || $href === '') {
			return false;
		}
		return (bool) preg_match('@^\s*(?:javascript|vbscript)\s*:@i', (string) $href);
	}

	/**
	 * Format an offending href for inclusion in an exception or warning
	 * message. Long encoded payloads are truncated with an ellipsis so
	 * stack traces remain readable; short hrefs pass through verbatim so
	 * the developer sees exactly what they wrote.
	 *
	 * @param  string $href
	 * @param  int    $maxLen Characters of href to show before ellipsis.
	 * @return string
	 */
	public static function formatHrefForMessage($href, $maxLen = 200)
	{
		$href = (string) $href;
		if (strlen($href) <= $maxLen) {
			return $href;
		}
		return substr($href, 0, $maxLen) . '...';
	}
}
