<?php

namespace Mpdf\Tag;

/**
 * HTML <rp> tag handler — Span fallback.
 *
 * <rp> wraps fallback parentheses ("(", ")") shown by AT or non-ruby UAs
 * around the rt. In a layout-aware ruby renderer the rp is suppressed when
 * the rt is rendered above the rb. mPDF does not currently render ruby
 * specially — the rp text flows inline like any other inline tag — so we
 * treat it as a bare InlineTag subclass: no unconditional Span push, just
 * the standard /Lang / /Alt machinery from the parent.
 *
 * Spec references:
 *   - W3C Ruby Annotation §3 — fallback parenthesis semantics
 *   - ISO 32000-1:2008 §14.8.5.6 Table 339 — /RP standard struct type
 */
class Rp extends InlineTag
{


}
