<?php

namespace Mpdf\Language;

use Mpdf\MpdfException;

class LanguageToFontRegistry
{
	/**
	 * @var LanguageToFontInterface[]
	 */
	private $register = [];

	public function __construct(array $classes)
	{
		foreach ($classes as $key => $languageClass) {
			if (!$languageClass instanceof LanguageToFontInterface) {
				throw new MpdfException('The LanguageToFontRegistry only accepts classes that implement LanguageToFontInterface: ' . get_class($languageClass));
			}

			$this->add($key, $languageClass);
		}
	}

	public function add($key, LanguageToFontInterface $languageClass)
	{
		$this->register[$key] = $languageClass;
	}

	public function remove($key)
	{
		if (!isset($this->register[$key])) {
			throw new MpdfException('Could not find language package in registry');
		}

		unset($this->register[$key]);
	}

	public function getAll()
	{
		return $this->register;
	}

	public function getByName($key)
	{
		if (!isset($this->register[$key])) {
			throw new MpdfException('Could not find language package in registry');
		}

		return $this->register[$key];
	}

	public function getLanguageOptions($mode, $adobeCJK)
	{
		foreach ($this->getAll() as $languageClass) {
			$font = $languageClass->getLanguageOptions($mode, $adobeCJK);
			if (!empty($font)) {
				return is_array($font) ? $font : [false, $font];
			}
		}

		return [false, ''];
	}
}
