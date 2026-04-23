<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 policy decisions that need to be applied at multiple call sites.
 *
 * The class is intentionally thin: each method is a pure predicate / scalar
 * helper with no state, so it can be referenced from tag handlers, writers,
 * and tests without dragging in the wider service container.
 *
 * Currently scoped to URL scheme policy — javascript:/vbscript: and friends in
 * <a>, <area>, and Link annotations. Expand cautiously — anything more stateful
 * belongs on UaState, not here.
 */
class UaPolicy
{

	/**
	 * Schemes uniformly non-functional in PDF readers AND with no accessible
	 * alternative. AT (NVDA, JAWS, VoiceOver) announce them as dead links;
	 * readers (Reader, Preview, Foxit, Chromium PDFium) refuse to execute them.
	 * ISO 14289-1:2014 §7.18 / Matterhorn 17-001 + 28-002.
	 *
	 * `livescript:`, `mocha:`, `vbs:` are historical Netscape/IE aliases for
	 * scripting; `view-source:` is browser-only and has no PDF semantics but
	 * is rejected on principle.
	 *
	 * Compared against the *normalised* href — see normaliseHref().
	 */
	private static $deniedSchemes = [
		'javascript',
		'vbscript',
		'vbs',
		'livescript',
		'mocha',
		'view-source',
	];

	/**
	 * data: URL MIME types that can carry executable / scripted content.
	 * `data:image/png;base64,...` is left untouched — only active content
	 * types are denied.
	 */
	private static $deniedDataMimes = [
		'text/html',
		'application/x-javascript',
		'application/javascript',
		'application/ecmascript',
		'application/xhtml+xml',
		'image/svg+xml',
	];

	/**
	 * Detection is tolerant of:
	 *   - Leading ASCII whitespace and Unicode invisible prefixes
	 *     (NBSP U+00A0, ZWSP U+200B, ZWNJ/ZWJ U+200C-D, BOM U+FEFF),
	 *     and C0/C1 control bytes.
	 *   - Mixed case (`JavaScript:`, `JAVASCRIPT:`, `jAvAsCrIpT:`).
	 *   - HTML entity-encoded scheme bytes
	 *     (`&Tab;javascript:`, `&#x6A;avascript:`, `javascript&#58;...`).
	 *   - Percent-encoded scheme bytes (`%6A%61vascript:`).
	 *   - Whitespace between scheme name and colon (`javascript :`).
	 *
	 * Schemes deliberately permitted:
	 *   - `mailto:`, `tel:`, `sms:`, `http:`, `https:`, `ftp:`, `#anchor`,
	 *     relative paths, and `data:` URLs whose MIME is not in the
	 *     deniedDataMimes list (e.g. `data:image/png;base64,...`).
	 *   - `file:` — privacy concern, not an accessibility concern; out of
	 *     scope here.
	 *
	 * @param  string|null $href Raw href as it appeared in the HTML source.
	 * @return bool
	 */
	public static function isPolicyBlockedHref($href)
	{
		if ($href === null || $href === '') {
			return false;
		}

		$normalised = self::normaliseHref((string) $href);
		if ($normalised === '') {
			return false;
		}

		// data:<mime>[;...,]<payload> — block only the active-content MIME types.
		if (strncmp($normalised, 'data:', 5) === 0) {
			$mime = substr($normalised, 5);
			$parts = preg_split('/[;,]/', $mime, 2);
			$mime = trim($parts[0]);
			return in_array($mime, self::$deniedDataMimes, true);
		}

		// Other schemes: extract the scheme name (the part before the first ":").
		// Strip any byte outside the RFC 3986 scheme charset
		// (ALPHA / DIGIT / "+" / "-" / ".") — this neutralises tricks like
		// `java\nscript:`, `j a v a s c r i p t :`, or NUL-byte smuggling
		// inside the scheme.
		$colonPos = strpos($normalised, ':');
		if ($colonPos === false || $colonPos === 0) {
			return false;
		}
		$schemeRaw = substr($normalised, 0, $colonPos);
		$scheme = preg_replace('/[^a-z0-9+\-.]/', '', $schemeRaw);
		if (!is_string($scheme) || $scheme === '') {
			return false;
		}
		return in_array($scheme, self::$deniedSchemes, true);
	}

	/**
	 * Normalise an href for policy comparison. The normalised form is used
	 * ONLY for the deny-list decision — never written back into the PDF.
	 *
	 *   1. HTML entity decode (`&Tab;` → tab, `&#x6A;` → 'j', `&#58;` → ':').
	 *   2. Single percent-decode pass (`%6A` → 'j', `%20` → space).
	 *      Browsers and PDF readers do not double-decode; we don't either.
	 *   3. Strip leading invisible bytes — ASCII whitespace, NBSP, ZWSP,
	 *      ZWJ/ZWNJ, BOM, and C0/C1 controls.
	 *   4. Lowercase (ASCII) for case-insensitive scheme comparison.
	 *
	 * @param  string $href
	 * @return string
	 */
	private static function normaliseHref($href)
	{
		// html_entity_decode (not htmlspecialchars_decode) is required: the
		// latter only handles &amp; &lt; &gt; &quot; &#39;. The bypass classes
		// we care about — &Tab;, &NewLine;, &#x6A;, &#58; — need the full
		// HTML5 entity table.
		$decoded = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');

		if (strpos($decoded, '%') !== false) {
			$once = rawurldecode($decoded);
			if (is_string($once)) {
				$decoded = $once;
			}
		}

		// Strip ASCII C0/whitespace, DEL, C1, NBSP, ZWSP/ZWNJ/ZWJ, BOM.
		$pattern = '/^[\x{0000}-\x{0020}\x{007F}-\x{00A0}\x{200B}-\x{200D}\x{FEFF}]+/u';
		$stripped = preg_replace($pattern, '', $decoded);
		if (!is_string($stripped)) {
			// Invalid UTF-8 caused PCRE failure; fall back to byte-level trim.
			$stripped = ltrim($decoded, " \t\r\n\v\f\0");
		}

		return strtolower($stripped);
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
