<?php

namespace Snapshots;

/**
 * What the contents-and-index documents put on a page, so it can be read against the contents and the index
 */
trait IndexedPages
{
	/**
	 * A heading with a contents entry of the same name, in the main contents and in each of $names
	 */
	private function heading($title, array $names = [])
	{
		$entries = '<tocentry content="' . $title . '" />';
		foreach ($names as $name) {
			$entries .= '<tocentry content="' . $title . '" name="' . $name . '" />';
		}

		return '<h2>' . $entries . $title . '</h2>';
	}

	/**
	 * The closing paragraph of a page: its terms, each as an index entry, then named
	 */
	private function covers(array $terms)
	{
		$entries = '';
		foreach ($terms as $term) {
			$entries .= '<indexentry content="' . $term . '" />';
		}

		return '<p>This page covers: ' . $entries . implode(', ', $terms) . '.</p>';
	}
}
