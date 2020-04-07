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

namespace Zxing;

use Zxing\Common\Detector\MathUtils;
use Zxing\Qrcode\Detector\FinderPattern;

/**
 * <p>Encapsulates a point of interest in an image containing a barcode. Typically, this
 * would be the location of a finder pattern or the corner of the barcode, for example.</p>
 *
 * @author Sean Owen
 */
class ResultPoint{

	protected float $x;
	protected float $y;

	public function __construct(float $x, float $y){
		$this->x = $x;
		$this->y = $y;
	}

	/**
	 * Orders an array of three ResultPoints in an order [A,B,C] such that AB is less than AC
	 * and BC is less than AC, and the angle between BC and BA is less than 180 degrees.
	 *
	 * @param ResultPoint[] patterns array of three {@code ResultPoint} to order
	 */
	public static function orderBestPatterns(array $patterns):array{

		// Find distances between pattern centers
		$zeroOneDistance = self::distance($patterns[0], $patterns[1]);
		$oneTwoDistance  = self::distance($patterns[1], $patterns[2]);
		$zeroTwoDistance = self::distance($patterns[0], $patterns[2]);

		// Assume one closest to other two is B; A and C will just be guesses at first
		if($oneTwoDistance >= $zeroOneDistance && $oneTwoDistance >= $zeroTwoDistance){
			$pointB = $patterns[0];
			$pointA = $patterns[1];
			$pointC = $patterns[2];
		}
		elseif($zeroTwoDistance >= $oneTwoDistance && $zeroTwoDistance >= $zeroOneDistance){
			$pointB = $patterns[1];
			$pointA = $patterns[0];
			$pointC = $patterns[2];
		}
		else{
			$pointB = $patterns[2];
			$pointA = $patterns[0];
			$pointC = $patterns[1];
		}

		// Use cross product to figure out whether A and C are correct or flipped.
		// This asks whether BC x BA has a positive z component, which is the arrangement
		// we want for A, B, C. If it's negative, then we've got it flipped around and
		// should swap A and C.
		if(self::crossProductZ($pointA, $pointB, $pointC) < 0.0){
			$temp   = $pointA;
			$pointA = $pointC;
			$pointC = $temp;
		}

		$patterns[0] = $pointA;
		$patterns[1] = $pointB;
		$patterns[2] = $pointC;

		return $patterns;
	}

	/**
	 * @param \Zxing\Qrcode\Detector\FinderPattern $pattern1 first pattern
	 * @param \Zxing\Qrcode\Detector\FinderPattern $pattern2 second pattern
	 *
	 * @return float distance between two points
	 */
	public static function distance(FinderPattern $pattern1, FinderPattern $pattern2):float{
		return MathUtils::distance($pattern1->getX(), $pattern1->getY(), $pattern2->getX(), $pattern2->getY());
	}

	/**
	 * Returns the z component of the cross product between vectors BC and BA.
	 */
	private static function crossProductZ(FinderPattern $pointA, FinderPattern $pointB, FinderPattern $pointC):float{
		$bX = $pointB->getX();
		$bY = $pointB->getY();

		return (($pointC->getX() - $bX) * ($pointA->getY() - $bY)) - (($pointC->getY() - $bY) * ($pointA->getX() - $bX));
	}

	final public function getX():float{
		return (float)$this->x;
	}

	final public function getY():float{
		return (float)$this->y;
	}

	final public function equals($other):bool{

		if($other instanceof ResultPoint){
			$otherPoint = $other;

			return $this->x == $otherPoint->getX() && $this->y == $otherPoint->getY();
		}

		return false;
	}

	final public function hashCode():int{
		return 31 * floatToIntBits($this->x) + floatToIntBits($this->y);
	}

	final public function toString():string{
		return '('.$this->x.','.$this->y.')';
	}
}
