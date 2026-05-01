<?php

namespace Mpdf\Tag;

/**
 * HTML <rp> tag handler — v1 Span fallback (audit 2026-05-01 L4).
 *
 * <rp> wraps fallback parentheses ("(", ")") shown by AT or non-ruby UAs
 * around the rt. In a layout-aware ruby renderer the rp is suppressed when
 * the rt is rendered above the rb. mPDF does not currently render ruby
 * specially — the rp text flows inline like any other inline tag — so we
 * treat it as a bare InlineTag subclass: no unconditional Span push, just
 * the standard /Lang / /Alt machinery from the parent.
 *
 * v2 (deferred) — when proper ruby layout lands, decide whether to suppress
 * the rp visually (per W3C Ruby Annotation §3) or render it; v2 also picks
 * between the /RP standard struct type and an alternative role. See plan
 * 2026-05-01 §4b.
 */
class Rp extends InlineTag
{


}
