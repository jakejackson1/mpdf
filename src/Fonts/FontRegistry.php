<?php

namespace Mpdf\Fonts;

use Mpdf\MpdfException;

class FontRegistry
{
	/**
	 * @var FontRegistrationInterface[]
	 */
	private $register = [];

	/**
	 * FontRegistry constructor.
	 *
	 * @param FontRegistrationInterface[]|FontRegistrationInterface $classes
	 */
	public function __construct($classes = [])
	{
		$classes = is_array($classes) ? $classes : [$classes];
		foreach ($classes as $class) {
			$this->add($class);
		}
	}

	/**
	 * Add a Font Package
	 *
	 * @param FontRegistrationInterface $class
	 */
	public function add(FontRegistrationInterface $class)
	{
		$this->register = [get_class($class) => $class] + $this->register;
	}

	/**
	 * Remove a Font Package by Name
	 *
	 * @param string $name
	 *
	 * @throws MpdfException
	 */
	public function remove($name)
	{
		if (!isset($this->register[$name])) {
			throw new MpdfException('Could not find font package in registry');
		}

		unset($this->register[$name]);
	}

	/**
	 * Get all registered Font Packages
	 *
	 * @return FontRegistrationInterface[]
	 */
	public function getAll()
	{
		return $this->register;
	}
}
