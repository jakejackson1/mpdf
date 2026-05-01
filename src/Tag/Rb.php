<?php

namespace Mpdf\Tag;

/**
 * HTML <rb> tag handler — v1 Span fallback (audit 2026-05-01 L4).
 *
 * <rb> marks the ruby base (the word being annotated). This is a bare
 * InlineTag subclass — no unconditional Span push, because doing so would
 * just produce a noise Span around the base text that adds nothing the
 * surrounding Ruby (or parent block) doesn't already provide. The Ruby
 * container handler (Ruby.php) is the load-bearing tagged-tree marker;
 * the rb's content is tagged via that wrapper.
 *
 * The InlineTag base class still handles lang= / aria-label= via
 * openInlineUaStruct() — those still produce a Span with /Lang or /Alt
 * when present.
 *
 * v2 (deferred) — replace Span fallback with the /RB standard struct type
 * per ISO 32000-1 §14.8.5.6 Table 339; gated on layout work (plan §4b).
 */
class Rb extends InlineTag
{


}
