<?php

namespace Mpdf\Tag;

/**
 * HTML <acronym> tag handler.
 *
 * <acronym> is deprecated in HTML5 but still used. Functionally identical to
 * <abbr>: the title attribute provides the expansion text for the acronym, which
 * is stored as the /E attribute on the Span struct element in PDF/UA-1 mode.
 *
 * Delegates to Abbr for all logic.
 *
 * Spec references:
 *   - ISO 14289-1:2014 §7.1 — abbreviations and acronyms should carry expansion text
 *   - ISO 32000-1:2008 §14.7.2 Table 322 — /E (expansion text) entry on StructElem dict
 */
class Acronym extends Abbr
{

}
