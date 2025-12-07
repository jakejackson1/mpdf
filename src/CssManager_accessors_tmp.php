	/**
	 * Set border dominance level for a specific side.
	 * 
	 * @param string $side 'L', 'R', 'T', or 'B'
	 * @param int $val Dominance value
	 */
	public function setBorderDominance($side, $val)
	{
		if (isset($this->borderDominance[$side])) {
			$this->borderDominance[$side] = $val;
		}
	}

	/**
	 * Get border dominance level for a specific side.
	 * 
	 * @param string $side 'L', 'R', 'T', or 'B'
	 * @return int Dominance value
	 */
	public function getBorderDominance($side)
	{
		return isset($this->borderDominance[$side]) ? $this->borderDominance[$side] : 0;
	}
