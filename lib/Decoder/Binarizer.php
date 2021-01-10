<?php
/*
* Copyright 2009 ZXing authors
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

namespace Zxing\Decoder;

use Zxing\Common\NotFoundException;

/**
 * This class implements a local thresholding algorithm, which while slower than the
 * GlobalHistogramBinarizer, is fairly efficient for what it does. It is designed for
 * high frequency images of barcodes with black data on white backgrounds. For this application,
 * it does a much better job than a global blackpoint with severe shadows and gradients.
 * However it tends to produce artifacts on lower frequency images and is therefore not
 * a good general purpose binarizer for uses outside ZXing.
 *
 * This class extends GlobalHistogramBinarizer, using the older histogram approach for 1D readers,
 * and the newer local approach for 2D readers. 1D decoding using a per-row histogram is already
 * inherently local, and only fails for horizontal gradients. We can revisit that problem later,
 * but for now it was not a win to use local blocks for 1D.
 *
 * This Binarizer is the default for the unit tests and the recommended class for library users.
 *
 * @author dswitkin@google.com (Daniel Switkin)
 */
final class Binarizer{

	// This class uses 5x5 blocks to compute local luminance, where each block is 8x8 pixels.
	// So this is the smallest dimension in each axis we can accept.
	private const BLOCK_SIZE_POWER  = 3;
	private const BLOCK_SIZE        = 8; // ...0100...00
	private const BLOCK_SIZE_MASK   = 7;   // ...0011...11
	private const MINIMUM_DIMENSION = 40;
	private const MIN_DYNAMIC_RANGE = 24;

#	private const LUMINANCE_BITS    = 5;
	private const LUMINANCE_SHIFT   = 3;
	private const LUMINANCE_BUCKETS = 32;

	private LuminanceSource $source;
	private array           $luminances;
	private array           $buckets;

	public function __construct(LuminanceSource $source){
		$this->source     = $source;
		$this->luminances = [];
		$this->buckets    = \array_fill(0, self::LUMINANCE_BUCKETS, 0);
		$this->source     = $source;
	}

	/**
	 * @return LuminanceSource
	 */
	final public function getLuminanceSource():LuminanceSource{
		return $this->source;
	}

	/**
	 * Does not sharpen the data, as this call is intended to only be used by 2D Readers.
	 */
	private function initArrays(int $luminanceSize):void{

		if(\count($this->luminances) < $luminanceSize){
			$this->luminances = [];
		}

		for($x = 0; $x < self::LUMINANCE_BUCKETS; $x++){
			$this->buckets[$x] = 0;
		}

	}

	/**
	 * @throws \Zxing\Common\NotFoundException
	 */
	private function estimateBlackPoint(array $buckets):int{
		// Find the tallest peak in the histogram.
		$numBuckets     = \count($buckets);
		$maxBucketCount = 0;
		$firstPeak      = 0;
		$firstPeakSize  = 0;

		for($x = 0; $x < $numBuckets; $x++){

			if($buckets[$x] > $firstPeakSize){
				$firstPeak     = $x;
				$firstPeakSize = $buckets[$x];
			}

			if($buckets[$x] > $maxBucketCount){
				$maxBucketCount = $buckets[$x];
			}
		}

		// Find the second-tallest peak which is somewhat far from the tallest peak.
		$secondPeak      = 0;
		$secondPeakScore = 0;

		for($x = 0; $x < $numBuckets; $x++){
			$distanceToBiggest = $x - $firstPeak;
			// Encourage more distant second peaks by multiplying by square of distance.
			$score = $buckets[$x] * $distanceToBiggest * $distanceToBiggest;

			if($score > $secondPeakScore){
				$secondPeak      = $x;
				$secondPeakScore = $score;
			}
		}

		// Make sure firstPeak corresponds to the black peak.
		if($firstPeak > $secondPeak){
			$temp       = $firstPeak;
			$firstPeak  = $secondPeak;
			$secondPeak = $temp;
		}

		// If there is too little contrast in the image to pick a meaningful black point, throw rather
		// than waste time trying to decode the image, and risk false positives.
		if($secondPeak - $firstPeak <= $numBuckets / 16){
			throw new NotFoundException('no meaningful dark point found');
		}

		// Find a valley between them that is low and closer to the white peak.
		$bestValley      = $secondPeak - 1;
		$bestValleyScore = -1;

		for($x = $secondPeak - 1; $x > $firstPeak; $x--){
			$fromFirst = $x - $firstPeak;
			$score     = $fromFirst * $fromFirst * ($secondPeak - $x) * ($maxBucketCount - $buckets[$x]);

			if($score > $bestValleyScore){
				$bestValley      = $x;
				$bestValleyScore = $score;
			}
		}

		return $bestValley << self::LUMINANCE_SHIFT;
	}

	/**
	 * Calculates the final BitMatrix once for all requests. This could be called once from the
	 * constructor instead, but there are some advantages to doing it lazily, such as making
	 * profiling easier, and not doing heavy lifting when callers don't expect it.
	 *
	 * Converts a 2D array of luminance data to 1 bit data. As above, assume this method is expensive
	 * and do not call it repeatedly. This method is intended for decoding 2D barcodes and may or
	 * may not apply sharpening. Therefore, a row from this matrix may not be identical to one
	 * fetched using getBlackRow(), so don't mix and match between them.
	 *
	 * @return BitMatrix The 2D array of bits for the image (true means black).
	 */
	public function getBlackMatrix():BitMatrix{
		$source = $this->getLuminanceSource();
		$width  = $source->getWidth();
		$height = $source->getHeight();

		if($width >= self::MINIMUM_DIMENSION && $height >= self::MINIMUM_DIMENSION){
			$luminances = $source->getMatrix();
			$subWidth   = $width >> self::BLOCK_SIZE_POWER;

			if(($width & self::BLOCK_SIZE_MASK) !== 0){
				$subWidth++;
			}

			$subHeight = $height >> self::BLOCK_SIZE_POWER;

			if(($height & self::BLOCK_SIZE_MASK) !== 0){
				$subHeight++;
			}

			$blackPoints = $this->calculateBlackPoints($luminances, $subWidth, $subHeight, $width, $height);

			return $this->calculateThresholdForBlock($luminances, $subWidth, $subHeight, $width, $height, $blackPoints);
		}

		// If the image is too small, fall back to the global histogram approach.
		return $this->getHistogramBlackMatrix();
	}

	public function getHistogramBlackMatrix():BitMatrix{
		$source = $this->getLuminanceSource();
		$width  = $source->getWidth();
		$height = $source->getHeight();
		$matrix = new BitMatrix($width, $height);

		// Quickly calculates the histogram by sampling four rows from the image. This proved to be
		// more robust on the blackbox tests than sampling a diagonal as we used to do.
		$this->initArrays($width);
		$localBuckets = $this->buckets;

		for($y = 1; $y < 5; $y++){
			$row             = (int)($height * $y / 5);
			$localLuminances = $source->getRow($row, $this->luminances);
			$right           = (int)(($width * 4) / 5);

			for($x = (int)($width / 5); $x < $right; $x++){
				$pixel = $localLuminances[(int)$x] & 0xff;
				$localBuckets[$pixel >> self::LUMINANCE_SHIFT]++;
			}
		}

		$blackPoint = $this->estimateBlackPoint($localBuckets);

		// We delay reading the entire image luminance until the black point estimation succeeds.
		// Although we end up reading four rows twice, it is consistent with our motto of
		// "fail quickly" which is necessary for continuous scanning.
		$localLuminances = $source->getMatrix();

		for($y = 0; $y < $height; $y++){
			$offset = $y * $width;

			for($x = 0; $x < $width; $x++){
				$pixel = (int)($localLuminances[$offset + $x] & 0xff);

				if($pixel < $blackPoint){
					$matrix->set($x, $y);
				}
			}
		}

		return $matrix;
	}

	/**
	 * Calculates a single black point for each block of pixels and saves it away.
	 * See the following thread for a discussion of this algorithm:
	 *  http://groups.google.com/group/zxing/browse_thread/thread/d06efa2c35a7ddc0
	 */
	private function calculateBlackPoints(array $luminances, int $subWidth, int $subHeight, int $width, int $height):array{
		$blackPoints = \array_fill(0, $subHeight, 0);

		foreach($blackPoints as $key => $point){
			$blackPoints[$key] = \array_fill(0, $subWidth, 0);
		}

		for($y = 0; $y < $subHeight; $y++){
			$yoffset    = ($y << self::BLOCK_SIZE_POWER);
			$maxYOffset = $height - self::BLOCK_SIZE;

			if($yoffset > $maxYOffset){
				$yoffset = $maxYOffset;
			}

			for($x = 0; $x < $subWidth; $x++){
				$xoffset    = ($x << self::BLOCK_SIZE_POWER);
				$maxXOffset = $width - self::BLOCK_SIZE;

				if($xoffset > $maxXOffset){
					$xoffset = $maxXOffset;
				}

				$sum = 0;
				$min = 0xFF;
				$max = 0;

				for($yy = 0, $offset = $yoffset * $width + $xoffset; $yy < self::BLOCK_SIZE; $yy++, $offset += $width){

					for($xx = 0; $xx < self::BLOCK_SIZE; $xx++){
						$pixel = (int)($luminances[(int)($offset + $xx)]) & 0xFF;
						$sum   += $pixel;
						// still looking for good contrast
						if($pixel < $min){
							$min = $pixel;
						}

						if($pixel > $max){
							$max = $pixel;
						}
					}

					// short-circuit min/max tests once dynamic range is met
					if($max - $min > self::MIN_DYNAMIC_RANGE){
						// finish the rest of the rows quickly
						for($yy++, $offset += $width; $yy < self::BLOCK_SIZE; $yy++, $offset += $width){
							for($xx = 0; $xx < self::BLOCK_SIZE; $xx++){
								$sum += $luminances[$offset + $xx] & 0xFF;
							}
						}
					}
				}

				// The default estimate is the average of the values in the block.
				$average = $sum >> (self::BLOCK_SIZE_POWER * 2);

				if($max - $min <= self::MIN_DYNAMIC_RANGE){
					// If variation within the block is low, assume this is a block with only light or only
					// dark pixels. In that case we do not want to use the average, as it would divide this
					// low contrast area into black and white pixels, essentially creating data out of noise.
					//
					// The default assumption is that the block is light/background. Since no estimate for
					// the level of dark pixels exists locally, use half the min for the block.
					$average = (int)($min / 2);

					if($y > 0 && $x > 0){
						// Correct the "white background" assumption for blocks that have neighbors by comparing
						// the pixels in this block to the previously calculated black points. This is based on
						// the fact that dark barcode symbology is always surrounded by some amount of light
						// background for which reasonable black point estimates were made. The bp estimated at
						// the boundaries is used for the interior.

						// The (min < bp) is arbitrary but works better than other heuristics that were tried.
						$averageNeighborBlackPoint = (int)(($blackPoints[$y - 1][$x] + (2 * $blackPoints[$y][$x - 1]) + $blackPoints[$y - 1][$x - 1]) / 4);

						if($min < $averageNeighborBlackPoint){
							$average = $averageNeighborBlackPoint;
						}
					}
				}

				$blackPoints[$y][$x] = (int)($average);
			}
		}

		return $blackPoints;
	}

	/**
	 * For each block in the image, calculate the average black point using a 5x5 grid
	 * of the blocks around it. Also handles the corner cases (fractional blocks are computed based
	 * on the last pixels in the row/column which are also used in the previous block).
	 */
	private function calculateThresholdForBlock(
		array $luminances,
		int $subWidth,
		int $subHeight,
		int $width,
		int $height,
		array $blackPoints
	):BitMatrix{
		$matrix = new BitMatrix($width, $height);

		for($y = 0; $y < $subHeight; $y++){
			$yoffset    = ($y << self::BLOCK_SIZE_POWER);
			$maxYOffset = $height - self::BLOCK_SIZE;

			if($yoffset > $maxYOffset){
				$yoffset = $maxYOffset;
			}

			for($x = 0; $x < $subWidth; $x++){
				$xoffset    = ($x << self::BLOCK_SIZE_POWER);
				$maxXOffset = $width - self::BLOCK_SIZE;

				if($xoffset > $maxXOffset){
					$xoffset = $maxXOffset;
				}

				$left = $this->cap($x, 2, $subWidth - 3);
				$top  = $this->cap($y, 2, $subHeight - 3);
				$sum  = 0;

				for($z = -2; $z <= 2; $z++){
					$blackRow = $blackPoints[$top + $z];
					$sum      += $blackRow[$left - 2] + $blackRow[$left - 1] + $blackRow[$left] + $blackRow[$left + 1] + $blackRow[$left + 2];
				}

				$average = (int)($sum / 25);

				// Applies a single threshold to a block of pixels.
				for($j = 0, $o = $yoffset * $width + $xoffset; $j < self::BLOCK_SIZE; $j++, $o += $width){
					for($i = 0; $i < self::BLOCK_SIZE; $i++){
						// Comparison needs to be <= so that black == 0 pixels are black even if the threshold is 0.
						if(($luminances[$o + $i] & 0xFF) <= $average){
							$matrix->set($xoffset + $i, $yoffset + $j);
						}
					}
				}
			}
		}

		return $matrix;
	}

	private function cap(int $value, int $min, int $max):int{

		if($value < $min){
			return $min;
		}

		if($value > $max){
			return $max;
		}

		return $value;
	}

}