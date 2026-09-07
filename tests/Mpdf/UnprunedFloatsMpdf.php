<?php

namespace Mpdf;

/**
 * mPDF as it behaved before dead floats were pruned: every float ever closed is kept and rescanned.
 *
 * The baseline FloatDivTest measures the pruning against.
 */
class UnprunedFloatsMpdf extends Mpdf
{

	public function addFloatDiv(array $floatDiv)
	{
		$this->floatDivs[] = $floatDiv;
	}

}
