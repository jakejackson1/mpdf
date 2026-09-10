<?php

namespace Mpdf;

/**
 * The scalar and array state of an object, captured and put back whole, for a document look-ahead that has to
 * be unwound. Objects and resources are left out: what they hold is either a service or state of their own
 *
 * @internal
 */
trait StateSnapshot
{

	/**
	 * @return array<string, mixed>
	 */
	public function getStateSnapshot()
	{
		$snapshot = [];
		foreach (get_object_vars($this) as $key => $value) {
			if (!is_object($value) && !is_resource($value)) {
				$snapshot[$key] = $value;
			}
		}

		return $snapshot;
	}

	/**
	 * @param array<string, mixed> $snapshot
	 *
	 * @return void
	 */
	public function restoreStateSnapshot(array $snapshot)
	{
		foreach ($snapshot as $key => $value) {
			$this->{$key} = $value;
		}
	}
}
