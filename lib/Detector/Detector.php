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

use chillerlan\QRCode\Common\Version;
use Zxing\Common\{MathUtils, NotFoundException};
use Zxing\Decoder\BitMatrix;

/**
 * <p>Encapsulates logic that can detect a QR Code in an image, even if the QR Code
 * is rotated or skewed, or partially obscured.</p>
 *
 * @author Sean Owen
 */
class Detector{

	private BitMatrix $image;

	/**
	 * Detector constructor.
	 */
	public function __construct(BitMatrix $image){
		$this->image = $image;
	}

	/**
	 * <p>Detects a QR Code in an image.</p>
	 *
	 * @throws \Zxing\Common\NotFoundException if QR Code cannot be found
	 */
	public final function detect():DetectorResult{/*Map<DecodeHintType,?>*/
		$finder     = new FinderPatternFinder($this->image);
		$info       = $finder->find();
		$topLeft    = $info->getTopLeft();
		$topRight   = $info->getTopRight();
		$bottomLeft = $info->getBottomLeft();
		$moduleSize = (float)$this->calculateModuleSize($topLeft, $topRight, $bottomLeft);

		if($moduleSize < 1.0){
			throw new NotFoundException();
		}

		$dimension = $this->computeDimension($topLeft, $topRight, $bottomLeft, $moduleSize);

		if($dimension % 4 !== 1){
			throw new FormatException('dimension mod 4 is not 1');
		}

		$provisionalVersion      = new Version((int)(($dimension - 17) / 4));
		$modulesBetweenFPCenters = $provisionalVersion->getDimension() - 7;

		$alignmentPattern = null;
		// Anything above version 1 has an alignment pattern
		if(count($provisionalVersion->getAlignmentPattern()) > 0){

			// Guess where a "bottom right" finder pattern would have been
			$bottomRightX = $topRight->getX() - $topLeft->getX() + $bottomLeft->getX();
			$bottomRightY = $topRight->getY() - $topLeft->getY() + $bottomLeft->getY();

			// Estimate that alignment pattern is closer by 3 modules
			// from "bottom right" to known top left location
			$correctionToTopLeft = 1.0 - 3.0 / (float)$modulesBetweenFPCenters;
			$estAlignmentX       = (int)($topLeft->getX() + $correctionToTopLeft * ($bottomRightX - $topLeft->getX()));
			$estAlignmentY       = (int)($topLeft->getY() + $correctionToTopLeft * ($bottomRightY - $topLeft->getY()));

			// Kind of arbitrary -- expand search radius before giving up
			for($i = 4; $i <= 16; $i <<= 1){//??????????
				try{
					$alignmentPattern = $this->findAlignmentInRegion(
						$moduleSize,
						$estAlignmentX,
						$estAlignmentY,
						(float)$i
					);
					break;
				}
				catch(NotFoundException $re){
					// try next round
				}
			}
			// If we didn't find alignment pattern... well try anyway without it
		}

		$transform = $this->createTransform($topLeft, $topRight, $bottomLeft, $dimension, $alignmentPattern);
		$bits      = (new GridSampler)->sampleGrid($this->image, $dimension, $dimension, $transform);

		$points = $alignmentPattern === null
			? [$bottomLeft, $topLeft, $topRight]
			: [$bottomLeft, $topLeft, $topRight, $alignmentPattern];

		return new DetectorResult($bits, $points);
	}

	/**
	 * <p>Computes an average estimated module size based on estimated derived from the positions
	 * of the three finder patterns.</p>
	 */
	protected final function calculateModuleSize(
		FinderPattern $topLeft,
		FinderPattern $topRight,
		FinderPattern $bottomLeft
	):float{
		// Take the average
		return (
			       $this->calculateModuleSizeOneWay($topLeft, $topRight) +
			       $this->calculateModuleSizeOneWay($topLeft, $bottomLeft)
		       ) / 2.0;
	}

	/**
	 * <p>Estimates module size based on two finder patterns -- it uses
	 * {@link #sizeOfBlackWhiteBlackRunBothWays(int, int, int, int)} to figure the
	 * width of each, measuring along the axis between their centers.</p>
	 */
	private function calculateModuleSizeOneWay(FinderPattern $pattern, FinderPattern $otherPattern):float{

		$moduleSizeEst1 = $this->sizeOfBlackWhiteBlackRunBothWays(
			$pattern->getX(),
			(int)$pattern->getY(),
			(int)$otherPattern->getX(),
			(int)$otherPattern->getY()
		);

		$moduleSizeEst2 = $this->sizeOfBlackWhiteBlackRunBothWays(
			(int)$otherPattern->getX(),
			(int)$otherPattern->getY(),
			(int)$pattern->getX(),
			(int)$pattern->getY()
		);

		if(\is_nan($moduleSizeEst1)){
			return $moduleSizeEst2 / 7.0;
		}

		if(\is_nan($moduleSizeEst2)){
			return $moduleSizeEst1 / 7.0;
		}
		// Average them, and divide by 7 since we've counted the width of 3 black modules,
		// and 1 white and 1 black module on either side. Ergo, divide sum by 14.
		return ($moduleSizeEst1 + $moduleSizeEst2) / 14.0;
	}

	/**
	 * See {@link #sizeOfBlackWhiteBlackRun(int, int, int, int)}; computes the total width of
	 * a finder pattern by looking for a black-white-black run from the center in the direction
	 * of another po$(another finder pattern center), and in the opposite direction too.</p>
	 */
	private function sizeOfBlackWhiteBlackRunBothWays(int $fromX, int $fromY, int $toX, int $toY):float{

		$result = $this->sizeOfBlackWhiteBlackRun($fromX, $fromY, $toX, $toY);

		// Now count other way -- don't run off image though of course
		$scale    = 1.0;
		$otherToX = $fromX - ($toX - $fromX);

		if($otherToX < 0){
			$scale    = (float)$fromX / (float)($fromX - $otherToX);
			$otherToX = 0;
		}
		elseif($otherToX >= $this->image->getWidth()){
			$scale    = (float)($this->image->getWidth() - 1 - $fromX) / (float)($otherToX - $fromX);
			$otherToX = $this->image->getWidth() - 1;
		}

		$otherToY = (int)($fromY - ($toY - $fromY) * $scale);
		$scale    = 1.0;

		if($otherToY < 0){
			$scale    = (float)$fromY / (float)($fromY - $otherToY);
			$otherToY = 0;
		}
		elseif($otherToY >= $this->image->getHeight()){
			$scale    = (float)($this->image->getHeight() - 1 - $fromY) / (float)($otherToY - $fromY);
			$otherToY = $this->image->getHeight() - 1;
		}

		$otherToX = (int)($fromX + ($otherToX - $fromX) * $scale);
		$result   += $this->sizeOfBlackWhiteBlackRun($fromX, $fromY, $otherToX, $otherToY);

		// Middle pixel is double-counted this way; subtract 1
		return $result - 1.0;
	}

	/**
	 * <p>This method traces a line from a po$in the image, in the direction towards another point.
	 * It begins in a black region, and keeps going until it finds white, then black, then white again.
	 * It reports the distance from the start to this point.</p>
	 *
	 * <p>This is used when figuring out how wide a finder pattern is, when the finder pattern
	 * may be skewed or rotated.</p>
	 */
	private function sizeOfBlackWhiteBlackRun(int $fromX, int $fromY, int $toX, int $toY):float{
		// Mild variant of Bresenham's algorithm;
		// see http://en.wikipedia.org/wiki/Bresenham's_line_algorithm
		$steep = \abs($toY - $fromY) > \abs($toX - $fromX);
		if($steep){
			$temp  = $fromX;
			$fromX = $fromY;
			$fromY = $temp;
			$temp  = $toX;
			$toX   = $toY;
			$toY   = $temp;
		}

		$dx    = \abs($toX - $fromX);
		$dy    = \abs($toY - $fromY);
		$error = -$dx / 2;
		$xstep = $fromX < $toX ? 1 : -1;
		$ystep = $fromY < $toY ? 1 : -1;

		// In black pixels, looking for white, first or second time.
		$state = 0;
		// Loop up until x == toX, but not beyond
		$xLimit = $toX + $xstep;
		for($x = $fromX, $y = $fromY; $x !== $xLimit; $x += $xstep){
			$realX = $steep ? $y : $x;
			$realY = $steep ? $x : $y;

			// Does current pixel mean we have moved white to black or vice versa?
			// Scanning black in state 0,2 and white in state 1, so if we find the wrong
			// color, advance to next state or end if we are in state 2 already
			if(($state === 1) === $this->image->get($realX, $realY)){
				if($state === 2){
					return MathUtils::distance($x, $y, $fromX, $fromY);
				}
				$state++;
			}

			$error += $dy;
			if($error > 0){
				if($y === $toY){
					break;
				}
				$y     += $ystep;
				$error -= $dx;
			}
		}
		// Found black-white-black; give the benefit of the doubt that the next pixel outside the image
		// is "white" so this last po$at (toX+xStep,toY) is the right ending. This is really a
		// small approximation; (toX+xStep,toY+yStep) might be really correct. Ignore this.
		if($state === 2){
			return MathUtils::distance($toX + $xstep, $toY, $fromX, $fromY);
		}

		// else we didn't find even black-white-black; no estimate is really possible
		return \NAN;
	}

	/**
	 * <p>Computes the dimension (number of modules on a size) of the QR Code based on the position
	 * of the finder patterns and estimated module size.</p>
	 */
	private function computeDimension(
		FinderPattern $topLeft,
		FinderPattern $topRight,
		FinderPattern $bottomLeft,
		float $moduleSize
	):int{
		$tltrCentersDimension = MathUtils::round(FinderPatternFinder::distance($topLeft, $topRight) / $moduleSize);
		$tlblCentersDimension = MathUtils::round(FinderPatternFinder::distance($topLeft, $bottomLeft) / $moduleSize);
		$dimension            = (($tltrCentersDimension + $tlblCentersDimension) / 2) + 7;

		switch($dimension & 0x03){ // mod 4
			case 0:
				$dimension++;
				break;
			// 1? do nothing
			case 2:
				$dimension--;
				break;
			case 3:
				throw new NotFoundException('estimated dimension: '.$dimension);
		}

		return $dimension;
	}

	/**
	 * <p>Attempts to locate an alignment pattern in a limited region of the image, which is
	 * guessed to contain it.</p>
	 *
	 * @param float $overallEstModuleSize estimated module size so far
	 * @param int   $estAlignmentX        x coordinate of center of area probably containing alignment pattern
	 * @param int   $estAlignmentY        y coordinate of above
	 * @param float $allowanceFactor      number of pixels in all directions to search from the center
	 *
	 * @return \Zxing\Detector\AlignmentPattern if found, or null otherwise
	 * @throws \Zxing\Common\NotFoundException if an unexpected error occurs during detection
	 */
	protected final function findAlignmentInRegion(
		float $overallEstModuleSize,
		int $estAlignmentX,
		int $estAlignmentY,
		float $allowanceFactor
	):AlignmentPattern{
		// Look for an alignment pattern (3 modules in size) around where it should be
		$allowance           = (int)($allowanceFactor * $overallEstModuleSize);
		$alignmentAreaLeftX  = \max(0, $estAlignmentX - $allowance);
		$alignmentAreaRightX = \min($this->image->getWidth() - 1, $estAlignmentX + $allowance);

		if($alignmentAreaRightX - $alignmentAreaLeftX < $overallEstModuleSize * 3){
			throw new NotFoundException();
		}

		$alignmentAreaTopY    = \max(0, $estAlignmentY - $allowance);
		$alignmentAreaBottomY = \min($this->image->getHeight() - 1, $estAlignmentY + $allowance);

		if($alignmentAreaBottomY - $alignmentAreaTopY < $overallEstModuleSize * 3){
			throw new NotFoundException();
		}

		$alignmentFinder = new AlignmentPatternFinder(
			$this->image,
			$alignmentAreaLeftX,
			$alignmentAreaTopY,
			$alignmentAreaRightX - $alignmentAreaLeftX,
			$alignmentAreaBottomY - $alignmentAreaTopY,
			$overallEstModuleSize,
		);

		return $alignmentFinder->find();
	}

	/**
	 *
	 */
	private function createTransform(
		FinderPattern $topLeft,
		FinderPattern $topRight,
		FinderPattern $bottomLeft,
		int $dimension,
		AlignmentPattern $alignmentPattern = null
	):PerspectiveTransform{
		$dimMinusThree = (float)$dimension - 3.5;

		if($alignmentPattern instanceof AlignmentPattern){
			$bottomRightX       = $alignmentPattern->getX();
			$bottomRightY       = $alignmentPattern->getY();
			$sourceBottomRightX = $dimMinusThree - 3.0;
			$sourceBottomRightY = $sourceBottomRightX;
		}
		else{
			// Don't have an alignment pattern, just make up the bottom-right point
			$bottomRightX       = ($topRight->getX() - $topLeft->getX()) + $bottomLeft->getX();
			$bottomRightY       = ($topRight->getY() - $topLeft->getY()) + $bottomLeft->getY();
			$sourceBottomRightX = $dimMinusThree;
			$sourceBottomRightY = $dimMinusThree;
		}

		return PerspectiveTransform::quadrilateralToQuadrilateral(
			3.5,
			3.5,
			$dimMinusThree,
			3.5,
			$sourceBottomRightX,
			$sourceBottomRightY,
			3.5,
			$dimMinusThree,
			$topLeft->getX(),
			$topLeft->getY(),
			$topRight->getX(),
			$topRight->getY(),
			$bottomRightX,
			$bottomRightY,
			$bottomLeft->getX(),
			$bottomLeft->getY()
		);
	}

}
