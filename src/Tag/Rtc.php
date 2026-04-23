<?php

namespace Mpdf\Tag;

/**
 * HTML <rtc> tag handler — Span fallback.
 *
 * <rtc> (HTML5 ruby text container) groups multiple <rt> elements for
 * compound bases (e.g. semantic + phonetic readings). ISO 32000-1 has no
 * direct equivalent — there is no /RTC standard struct type — so this
 * handler maps it to Span.
 *
 * Bare InlineTag subclass: no unconditional Span push. The contained
 * <rt> children handle their own Span tagging via Rt.php.
 *
 * Spec references:
 *   - HTML5 §4.5.10 — <rtc> ruby text container semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Tables 339, 340 — Ruby standard struct types
 */
class Rtc extends InlineTag
{


}
