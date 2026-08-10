<?php

namespace Mpdf\Tag;

/**
 * HTML <rtc> tag handler — transparent (no PDF standard type).
 *
 * <rtc> (HTML5 ruby text container) groups multiple <rt> elements for compound
 * bases (e.g. semantic + phonetic readings). ISO 32000-1 has no /RTC standard
 * struct type, so this handler opens no struct element of its own: its <rt>
 * children open their RT elements directly beneath the enclosing Ruby (Rt.php),
 * which is valid ruby structure. A lang=/aria-label= on the <rtc> still produces
 * a Span with /Lang or /Alt via the InlineTag base, so language metadata is not
 * lost.
 *
 * Spec references:
 *   - HTML5 §4.5.10 — <rtc> ruby text container semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 337 — Ruby standard struct types
 */
class Rtc extends InlineTag
{


}
