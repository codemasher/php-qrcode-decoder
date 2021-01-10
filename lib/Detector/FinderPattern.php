<?php
/*
 * Copyright 2007 ZXing authors
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *      http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Zxing\Detector;

use function Zxing\Common\{distance, squaredDistance};

/**
 * <p>Encapsulates a finder pattern, which are the three square patterns found in
 * the corners of QR Codes. It also encapsulates a count of similar finder patterns,
 * as a convenience to the finder's bookkeeping.</p>
 *
 * @author Sean Owen
 */
final class FinderPattern extends ResultPoint{

	private float $estimatedModuleSize;
	private int   $count;

	public function __construct(float $posX, float $posY, float $estimatedModuleSize, int $count = 1){
		parent::__construct($posX, $posY);

		$this->estimatedModuleSize = $estimatedModuleSize;
		$this->count               = $count;
	}

	public function getEstimatedModuleSize():float{
		return $this->estimatedModuleSize;
	}

	public function getCount():int{
		return $this->count;
	}

	/**
	 * @param \Zxing\Detector\FinderPattern $b second pattern
	 *
	 * @return float distance between two points
	 */
	public function distance(FinderPattern $b):float{
		return distance($this->getX(), $this->getY(), $b->getX(), $b->getY());
	}

	/**
	 * Get square of distance between a and b.
	 */
	public function squaredDistance(FinderPattern $b):float{
		return squaredDistance($this->getX(), $this->getY(), $b->getX(), $b->getY());
	}

	/**
	 * <p>Determines if this finder pattern "about equals" a finder pattern at the stated
	 * position and size -- meaning, it is at nearly the same center with nearly the same size.</p>
	 */
	public function aboutEquals(float $moduleSize, int $i, int $j):bool{

		if(\abs($i - $this->y) <= $moduleSize && \abs($j - $this->x) <= $moduleSize){
			$moduleSizeDiff = \abs($moduleSize - $this->estimatedModuleSize);

			return $moduleSizeDiff <= 1.0 || $moduleSizeDiff <= $this->estimatedModuleSize;
		}

		return false;
	}

	/**
	 * Combines this object's current estimate of a finder pattern position and module size
	 * with a new estimate. It returns a new {@code FinderPattern} containing a weighted average
	 * based on count.
	 */
	public function combineEstimate(int $i, int $j, float $newModuleSize):FinderPattern{
		$combinedCount      = $this->count + 1;
		$combinedX          = ($this->count * $this->x + $j) / $combinedCount;
		$combinedY          = ($this->count * $this->y + $i) / $combinedCount;
		$combinedModuleSize = ($this->count * $this->estimatedModuleSize + $newModuleSize) / $combinedCount;

		return new self($combinedX, $combinedY, $combinedModuleSize, $combinedCount);
	}
}
