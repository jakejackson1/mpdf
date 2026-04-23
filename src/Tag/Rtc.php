<?php

namespace Mpdf\Tag;

/**
 * HTML <rtc> tag handler — v1 Span fallback (audit 2026-05-01 L4).
 *
 * <rtc> (HTML5 ruby text container) groups multiple <rt> elements for
 * compound bases (e.g. semantic + phonetic readings). ISO 32000-1 has no
 * direct equivalent — there is no /RTC struct type — so v1 maps it to
 * Span. v2 (deferred) must decide between keeping Span and adding a
 * custom RoleMap entry that maps RTC to one of the standard types.
 *
 * Bare InlineTag subclass: no unconditional Span push. The contained
 * <rt> children handle their own Span tagging via Rt.php.
 */
class Rtc extends InlineTag
{


}
