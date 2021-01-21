<?php
/**
 * Class ResultPoint
 *
 * @filesource   ResultPoint.php
 * @created      17.01.2021
 * @package      chillerlan\QRCode\Detector
 * @author       ZXing Authors
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2021 Smiley
 * @license      Apache-2.0
 */

namespace Zxing\Detector;

use function abs;

/**
 * <p>Encapsulates a point of interest in an image containing a barcode. Typically, this
 * would be the location of a finder pattern or the corner of the barcode, for example.</p>
 *
 * @author Sean Owen
 */
abstract class ResultPoint{

	protected float $x;
	protected float $y;
	protected float $estimatedModuleSize;

	public function __construct(float $x, float $y, float $estimatedModuleSize){
		$this->x                   = $x;
		$this->y                   = $y;
		$this->estimatedModuleSize = $estimatedModuleSize;
	}

	public function getX():float{
		return (float)$this->x;
	}

	public function getY():float{
		return (float)$this->y;
	}

	public function getEstimatedModuleSize():float{
		return $this->estimatedModuleSize;
	}

	/**
	 * <p>Determines if this finder pattern "about equals" a finder pattern at the stated
	 * position and size -- meaning, it is at nearly the same center with nearly the same size.</p>
	 */
	public function aboutEquals(float $moduleSize, float $i, float $j):bool{

		if(abs($i - $this->y) <= $moduleSize && abs($j - $this->x) <= $moduleSize){
			$moduleSizeDiff = abs($moduleSize - $this->estimatedModuleSize);

			return $moduleSizeDiff <= 1.0 || $moduleSizeDiff <= $this->estimatedModuleSize;
		}

		return false;
	}

}
