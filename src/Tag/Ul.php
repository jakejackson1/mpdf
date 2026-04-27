<?php

namespace Mpdf\Tag;

/**
 * PDF/UA-1 Phase 4 — UL tag handler.
 *
 * Inherits all block-level layout and struct-tree handling from BlockTag.
 * StructType::fromHtmlTag('UL') maps to 'L', so BlockTag's PDFUA hook
 * already calls structureTree->open('L') on tag open and close() on tag close
 * for non-table content. No additional code is required here.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.8 Table 333 — L (List) grouping element
 *   - Tagged PDF Best Practice Guide §4.2.3 — list structure L > LI > Lbl + LBody
 */
class Ul extends BlockTag
{


}
