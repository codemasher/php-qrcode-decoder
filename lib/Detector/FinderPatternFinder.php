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

use Zxing\Common\NotFoundException;
use Zxing\Decoder\BitMatrix;

/**
 * <p>This class attempts to find finder patterns in a QR Code. Finder patterns are the square
 * markers at three corners of a QR Code.</p>
 *
 * <p>This class is thread-safe but not reentrant. Each thread must allocate its own object.
 *
 * @author Sean Owen
 */
final class FinderPatternFinder{

	private const MIN_SKIP      = 2;
	private const MAX_MODULES   = 177; // 1 pixel/module times 3 modules/center
	private const CENTER_QUORUM = 2; // support up to version 10 for mobile clients
	private BitMatrix $bitMatrix;
	/** @var \Zxing\Detector\FinderPattern[] */
	private array $possibleCenters; //private final List<FinderPattern> possibleCenters;
	private bool  $hasSkipped = false;
	/** @var int[] */
	private array $crossCheckStateCount;

	/**
	 * <p>Creates a finder that will search the image for three finder patterns.</p>
	 *
	 * @param BitMatrix $bitMatrix image to search
	 */
	public function __construct(BitMatrix $bitMatrix){
		$this->bitMatrix = $bitMatrix;

		$this->possibleCenters      = [];//new ArrayList<>();
		$this->crossCheckStateCount = $this->getCrossCheckStateCount();
	}

	public function find():FinderPatternInfo{
		$maxI = $this->bitMatrix->getHeight();
		$maxJ = $this->bitMatrix->getWidth();

		// We are looking for black/white/black/white/black modules in
		// 1:1:3:1:1 ratio; this tracks the number of such modules seen so far

		// Let's assume that the maximum version QR Code we support takes up 1/4 the height of the
		// image, and then account for the center being 3 modules in size. This gives the smallest
		// number of pixels the center could be, so skip this often.
		$iSkip = (int)((3 * $maxI) / (4 * self::MAX_MODULES));

		if($iSkip < self::MIN_SKIP){
			$iSkip = self::MIN_SKIP;
		}

		$done  = false;

		for($i = $iSkip - 1; $i < $maxI && !$done; $i += $iSkip){
			// Get a row of black/white values
			$stateCount   = $this->getCrossCheckStateCount();
			$currentState = 0;

			for($j = 0; $j < $maxJ; $j++){

				if($this->bitMatrix->get($j, $i)){
					// Black pixel
					if(($currentState & 1) === 1){ // Counting white pixels
						$currentState++;
					}

					$stateCount[$currentState]++;
				}
				else{ // White pixel

					if(($currentState & 1) === 0){ // Counting black pixels

						if($currentState === 4){ // A winner?

							if($this->foundPatternCross($stateCount)){ // Yes
								$confirmed = $this->handlePossibleCenter($stateCount, $i, $j);

								if($confirmed){
									// Start examining every other line. Checking each line turned out to be too
									// expensive and didn't improve performance.
									$iSkip = 3;
									if($this->hasSkipped){
										$done = $this->haveMultiplyConfirmedCenters();
									}
									else{
										$rowSkip = $this->findRowSkip();

										if($rowSkip > $stateCount[2]){
											// Skip rows between row of lower confirmed center
											// and top of presumed third confirmed center
											// but back up a bit to get a full chance of detecting
											// it, entire width of center of finder pattern

											// Skip by rowSkip, but back off by $stateCount[2] (size of last center
											// of pattern we saw) to be conservative, and also back off by iSkip which
											// is about to be re-added
											$i += $rowSkip - $stateCount[2] - $iSkip;
											$j = $maxJ - 1;
										}
									}
								}
								else{
									$stateCount   = $this->doShiftCounts2($stateCount);
									$currentState = 3;
									continue;
								}
								// Clear state to start looking again
								$currentState = 0;
								$stateCount   = $this->getCrossCheckStateCount();
							}
							else{ // No, shift counts back by two
								$stateCount   = $this->doShiftCounts2($stateCount);
								$currentState = 3;
							}
						}
						else{
							$stateCount[++$currentState]++;
						}
					}
					else{ // Counting white pixels
						$stateCount[$currentState]++;
					}
				}
			}

			if($this->foundPatternCross($stateCount)){
				$confirmed = $this->handlePossibleCenter($stateCount, $i, $maxJ);

				if($confirmed){
					$iSkip = $stateCount[0];

					if($this->hasSkipped){
						// Found a third one
						$done = $this->haveMultiplyConfirmedCenters();
					}
				}
			}
		}

		$patternInfo = $this->orderBestPatterns($this->selectBestPatterns());

		return new FinderPatternInfo($patternInfo);
	}

	private function getCrossCheckStateCount():array{
		return [0, 0, 0, 0, 0];
	}

	private function doShiftCounts2(array $stateCount):array{
		$stateCount[0] = $stateCount[2];
		$stateCount[1] = $stateCount[3];
		$stateCount[2] = $stateCount[4];
		$stateCount[3] = 1;
		$stateCount[4] = 0;

		return $stateCount;
	}

	/**
	 * Given a count of black/white/black/white/black pixels just seen and an end position,
	 * figures the location of the center of this run.
	 */
	private function centerFromEnd(array $stateCount, int $end):float{
		return (float)(($end - $stateCount[4] - $stateCount[3]) - $stateCount[2] / 2.0);
	}

	private function foundPatternCross(array $stateCount):bool{
		// Allow less than 50% variance from 1-1-3-1-1 proportions
		return $this->foundPatternVariance($stateCount, 2.0);
	}

	private function foundPatternDiagonal(array $stateCount):bool{
		// Allow less than 75% variance from 1-1-3-1-1 proportions
		return $this->foundPatternVariance($stateCount, 1.333);
	}

	/**
	 * @param int[] $stateCount count of black/white/black/white/black pixels just read
	 *
	 * @return bool true if the proportions of the counts is close enough to the 1/1/3/1/1 ratios
	 *              used by finder patterns to be considered a match
	 */
	private function foundPatternVariance(array $stateCount, float $variance):bool{
		$totalModuleSize = 0;

		for($i = 0; $i < 5; $i++){
			$count = $stateCount[$i];

			if($count === 0){
				return false;
			}

			$totalModuleSize += $count;
		}

		if($totalModuleSize < 7){
			return false;
		}

		$moduleSize  = $totalModuleSize / 7.0;
		$maxVariance = $moduleSize / $variance;

		return
			\abs($moduleSize - $stateCount[0]) < $maxVariance &&
			\abs($moduleSize - $stateCount[1]) < $maxVariance &&
			\abs(3.0 * $moduleSize - $stateCount[2]) < 3 * $maxVariance &&
			\abs($moduleSize - $stateCount[3]) < $maxVariance &&
			\abs($moduleSize - $stateCount[4]) < $maxVariance;
	}

	/**
	 * After a vertical and horizontal scan finds a potential finder pattern, this method
	 * "cross-cross-cross-checks" by scanning down diagonally through the center of the possible
	 * finder pattern to see if the same proportion is detected.
	 *
	 * @param $centerI ;  row where a finder pattern was detected
	 * @param $centerJ ; center of the section that appears to cross a finder pattern
	 *
	 * @return bool true if proportions are withing expected limits
	 */
	private function crossCheckDiagonal(int $centerI, int $centerJ):bool{
		$stateCount = $this->getCrossCheckStateCount();

		// Start counting up, left from center finding black center mass
		$i = 0;

		while($centerI >= $i && $centerJ >= $i && $this->bitMatrix->get($centerJ - $i, $centerI - $i)){
			$stateCount[2]++;
			$i++;
		}

		if($stateCount[2] === 0){
			return false;
		}

		// Continue up, left finding white space
		while($centerI >= $i && $centerJ >= $i && !$this->bitMatrix->get($centerJ - $i, $centerI - $i)){
			$stateCount[1]++;
			$i++;
		}

		if($stateCount[1] === 0){
			return false;
		}

		// Continue up, left finding black border
		while($centerI >= $i && $centerJ >= $i && $this->bitMatrix->get($centerJ - $i, $centerI - $i)){
			$stateCount[0]++;
			$i++;
		}

		if($stateCount[0] === 0){
			return false;
		}

		$maxI = $this->bitMatrix->getHeight();
		$maxJ = $this->bitMatrix->getWidth();

		// Now also count down, right from center
		$i = 1;
		while($centerI + $i < $maxI && $centerJ + $i < $maxJ && $this->bitMatrix->get($centerJ + $i, $centerI + $i)){
			$stateCount[2]++;
			$i++;
		}

		while($centerI + $i < $maxI && $centerJ + $i < $maxJ && !$this->bitMatrix->get($centerJ + $i, $centerI + $i)){
			$stateCount[3]++;
			$i++;
		}

		if($stateCount[3] === 0){
			return false;
		}

		while($centerI + $i < $maxI && $centerJ + $i < $maxJ && $this->bitMatrix->get($centerJ + $i, $centerI + $i)){
			$stateCount[4]++;
			$i++;
		}

		if($stateCount[4] === 0){
			return false;
		}

		return $this->foundPatternDiagonal($stateCount);
	}

	/**
	 * <p>After a horizontal scan finds a potential finder pattern, this method
	 * "cross-checks" by scanning down vertically through the center of the possible
	 * finder pattern to see if the same proportion is detected.</p>
	 *
	 * @param int $startI   ;  row where a finder pattern was detected
	 * @param int $centerJ  ; center of the section that appears to cross a finder pattern
	 * @param int $maxCount ; maximum reasonable number of modules that should be
	 *                      observed in any reading state, based on the results of the horizontal scan
	 * @param int $originalStateCountTotal
	 *
	 * @return float vertical center of finder pattern, or {@link Float#NaN} if not found
	 */
	private function crossCheckVertical(int $startI, int $centerJ, int $maxCount, int $originalStateCountTotal):float{
		$maxI       = $this->bitMatrix->getHeight();
		$stateCount = $this->getCrossCheckStateCount();

		// Start counting up from center
		$i = $startI;
		while($i >= 0 && $this->bitMatrix->get($centerJ, $i)){
			$stateCount[2]++;
			$i--;
		}

		if($i < 0){
			return \NAN;
		}

		while($i >= 0 && !$this->bitMatrix->get($centerJ, $i) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$i--;
		}

		// If already too many modules in this state or ran off the edge:
		if($i < 0 || $stateCount[1] > $maxCount){
			return \NAN;
		}

		while($i >= 0 && $this->bitMatrix->get($centerJ, $i) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$i--;
		}

		if($stateCount[0] > $maxCount){
			return \NAN;
		}

		// Now also count down from center
		$i = $startI + 1;
		while($i < $maxI && $this->bitMatrix->get($centerJ, $i)){
			$stateCount[2]++;
			$i++;
		}

		if($i === $maxI){
			return \NAN;
		}

		while($i < $maxI && !$this->bitMatrix->get($centerJ, $i) && $stateCount[3] < $maxCount){
			$stateCount[3]++;
			$i++;
		}

		if($i === $maxI || $stateCount[3] >= $maxCount){
			return \NAN;
		}

		while($i < $maxI && $this->bitMatrix->get($centerJ, $i) && $stateCount[4] < $maxCount){
			$stateCount[4]++;
			$i++;
		}

		if($stateCount[4] >= $maxCount){
			return \NAN;
		}

		// If we found a finder-pattern-like section, but its size is more than 40% different than
		// the original, assume it's a false positive
		$stateCountTotal = $stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4];
		if(5 * \abs($stateCountTotal - $originalStateCountTotal) >= 2 * $originalStateCountTotal){
			return \NAN;
		}

		return $this->foundPatternCross($stateCount) ? $this->centerFromEnd($stateCount, $i) : \NAN;
	}

	/**
	 * <p>Like {@link #crossCheckVertical(int, int, int, int)}, and in fact is basically identical,
	 * except it reads horizontally instead of vertically. This is used to cross-cross
	 * check a vertical cross check and locate the real center of the alignment pattern.</p>
	 */
	private function crossCheckHorizontal(int $startJ, int $centerI, int $maxCount, int $originalStateCountTotal):float{
		$maxJ       = $this->bitMatrix->getWidth();
		$stateCount = $this->getCrossCheckStateCount();

		$j = $startJ;
		while($j >= 0 && $this->bitMatrix->get($j, $centerI)){
			$stateCount[2]++;
			$j--;
		}

		if($j < 0){
			return \NAN;
		}

		while($j >= 0 && !$this->bitMatrix->get($j, $centerI) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$j--;
		}

		if($j < 0 || $stateCount[1] > $maxCount){
			return \NAN;
		}

		while($j >= 0 && $this->bitMatrix->get($j, $centerI) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$j--;
		}

		if($stateCount[0] > $maxCount){
			return \NAN;
		}

		$j = $startJ + 1;
		while($j < $maxJ && $this->bitMatrix->get($j, $centerI)){
			$stateCount[2]++;
			$j++;
		}

		if($j === $maxJ){
			return \NAN;
		}

		while($j < $maxJ && !$this->bitMatrix->get($j, $centerI) && $stateCount[3] < $maxCount){
			$stateCount[3]++;
			$j++;
		}

		if($j === $maxJ || $stateCount[3] >= $maxCount){
			return \NAN;
		}

		while($j < $maxJ && $this->bitMatrix->get($j, $centerI) && $stateCount[4] < $maxCount){
			$stateCount[4]++;
			$j++;
		}

		if($stateCount[4] >= $maxCount){
			return \NAN;
		}

		// If we found a finder-pattern-like section, but its size is significantly different than
		// the original, assume it's a false positive
		$stateCountTotal = $stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4];
		if(5 * \abs($stateCountTotal - $originalStateCountTotal) >= $originalStateCountTotal){
			return \NAN;
		}

		return $this->foundPatternCross($stateCount) ? $this->centerFromEnd($stateCount, $j) : \NAN;
	}

	/**
	 * <p>This is called when a horizontal scan finds a possible alignment pattern. It will
	 * cross check with a vertical scan, and if successful, will, ah, cross-cross-check
	 * with another horizontal scan. This is needed primarily to locate the real horizontal
	 * center of the pattern in cases of extreme skew.
	 * And then we cross-cross-cross check with another diagonal scan.</p>
	 *
	 * <p>If that succeeds the finder pattern location is added to a list that tracks
	 * the number of times each location has been nearly-matched as a finder pattern.
	 * Each additional find is more evidence that the location is in fact a finder
	 * pattern center
	 *
	 * @param int[] $stateCount  reading state module counts from horizontal scan
	 * @param int   $i           row where finder pattern may be found
	 * @param int   $j           end of possible finder pattern in row
	 *
	 * @return bool if a finder pattern candidate was found this time
	 */
	protected final function handlePossibleCenter(array $stateCount, int $i, int $j):bool{
		$stateCountTotal = $stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4];
		$centerJ         = $this->centerFromEnd($stateCount, $j);
		$centerI         = $this->crossCheckVertical($i, (int)$centerJ, $stateCount[2], $stateCountTotal);

		if(!\is_nan($centerI)){
			// Re-cross check
			$centerJ = $this->crossCheckHorizontal((int)$centerJ, (int)$centerI, $stateCount[2], $stateCountTotal);
			if(!\is_nan($centerJ) && ($this->crossCheckDiagonal((int)$centerI, (int)$centerJ))){
				$estimatedModuleSize = $stateCountTotal / 7.0;
				$found               = false;

				for($index = 0; $index < \count($this->possibleCenters); $index++){
					$center = $this->possibleCenters[$index];
					// Look for about the same center and module size:
					if($center->aboutEquals($estimatedModuleSize, $centerI, $centerJ)){
						$this->possibleCenters[$index] = $center->combineEstimate($centerI, $centerJ, $estimatedModuleSize);
						$found                         = true;
						break;
					}
				}

				if(!$found){
					$point                   = new FinderPattern($centerJ, $centerI, $estimatedModuleSize);
					$this->possibleCenters[] = $point;
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * @return int number of rows we could safely skip during scanning, based on the first
	 *         two finder patterns that have been located. In some cases their position will
	 *         allow us to infer that the third pattern must lie below a certain point farther
	 *         down in the image.
	 */
	private function findRowSkip():int{
		$max = \count($this->possibleCenters);

		if($max <= 1){
			return 0;
		}

		$firstConfirmedCenter = null;
		foreach($this->possibleCenters as $center){

			if($center->getCount() >= self::CENTER_QUORUM){

				if($firstConfirmedCenter === null){
					$firstConfirmedCenter = $center;
				}
				else{
					// We have two confirmed centers
					// How far down can we skip before resuming looking for the next
					// pattern? In the worst case, only the difference between the
					// difference in the x / y coordinates of the two centers.
					// This is the case where you find top left last.
					$this->hasSkipped = true;

					return (int)((\abs($firstConfirmedCenter->getX() - $center->getX()) -
					              \abs($firstConfirmedCenter->getY() - $center->getY())) / 2);
				}
			}
		}

		return 0;
	}

	/**
	 * @return bool true if we have found at least 3 finder patterns that have been detected
	 *              at least {@link #CENTER_QUORUM} times each, and, the estimated module size of the
	 *              candidates is "pretty similar"
	 */
	private function haveMultiplyConfirmedCenters():bool{
		$confirmedCount  = 0;
		$totalModuleSize = 0.0;
		$max             = \count($this->possibleCenters);

		foreach($this->possibleCenters as $pattern){
			if($pattern->getCount() >= self::CENTER_QUORUM){
				$confirmedCount++;
				$totalModuleSize += $pattern->getEstimatedModuleSize();
			}
		}

		if($confirmedCount < 3){
			return false;
		}
		// OK, we have at least 3 confirmed centers, but, it's possible that one is a "false positive"
		// and that we need to keep looking. We detect this by asking if the estimated module sizes
		// vary too much. We arbitrarily say that when the total deviation from average exceeds
		// 5% of the total module size estimates, it's too much.
		$average        = $totalModuleSize / (float)$max;
		$totalDeviation = 0.0;

		foreach($this->possibleCenters as $pattern){
			$totalDeviation += \abs($pattern->getEstimatedModuleSize() - $average);
		}

		return $totalDeviation <= 0.05 * $totalModuleSize;
	}

	/**
	 * @return \Zxing\Detector\FinderPattern[] the 3 best {@link FinderPattern}s from our list of candidates. The "best" are
	 *         those that have been detected at least {@link #CENTER_QUORUM} times, and whose module
	 *         size differs from the average among those patterns the least
	 * @throws \Zxing\Common\NotFoundException if 3 such finder patterns do not exist
	 */
	private function selectBestPatterns():array{
		$startSize = \count($this->possibleCenters);

		if($startSize < 3){
			// Couldn't find enough finder patterns
			throw new NotFoundException;
		}

		\usort(
			$this->possibleCenters,
			fn(FinderPattern $a, FinderPattern $b) => $a->getEstimatedModuleSize() <=> $b->getEstimatedModuleSize()
		);

		$distortion   = \PHP_FLOAT_MAX;
		$bestPatterns = [];

		for($i = 0; $i < $startSize - 2; $i++){
			$fpi           = $this->possibleCenters[$i];
			$minModuleSize = $fpi->getEstimatedModuleSize();

			for($j = $i + 1; $j < $startSize - 1; $j++){
				$fpj      = $this->possibleCenters[$j];
				$squares0 = $fpi->squaredDistance($fpj);

				for($k = $j + 1; $k < $startSize; $k++){
					$fpk           = $this->possibleCenters[$k];
					$maxModuleSize = $fpk->getEstimatedModuleSize();

					if($maxModuleSize > $minModuleSize * 1.4){
						// module size is not similar
						continue;
					}

					$a = $squares0;
					$b = $fpj->squaredDistance($fpk);
					$c = $fpi->squaredDistance($fpk);

					// sorts ascending - inlined
					if($a < $b){
						if($b > $c){
							if($a < $c){
								$temp = $b;
								$b    = $c;
								$c    = $temp;
							}
							else{
								$temp = $a;
								$a    = $c;
								$c    = $b;
								$b    = $temp;
							}
						}
					}
					else{
						if($b < $c){
							if($a < $c){
								$temp = $a;
								$a    = $b;
								$b    = $temp;
							}
							else{
								$temp = $a;
								$a    = $b;
								$b    = $c;
								$c    = $temp;
							}
						}
						else{
							$temp = $a;
							$a    = $c;
							$c    = $temp;
						}
					}

					// a^2 + b^2 = c^2 (Pythagorean theorem), and a = b (isosceles triangle).
					// Since any right triangle satisfies the formula c^2 - b^2 - a^2 = 0,
					// we need to check both two equal sides separately.
					// The value of |c^2 - 2 * b^2| + |c^2 - 2 * a^2| increases as dissimilarity
					// from isosceles right triangle.
					$d = \abs($c - 2 * $b) + \abs($c - 2 * $a);

					if($d < $distortion){
						$distortion   = $d;
						$bestPatterns = [$fpi, $fpj, $fpk];
					}
				}
			}
		}

		if($distortion === \PHP_FLOAT_MAX){
			throw new NotFoundException;
		}

		return $bestPatterns;
	}

	/**
	 * Orders an array of three ResultPoints in an order [A,B,C] such that AB is less than AC
	 * and BC is less than AC, and the angle between BC and BA is less than 180 degrees.
	 *
	 * @param ResultPoint[] patterns array of three {@code ResultPoint} to order
	 *
	 * @return array
	 */
	private function orderBestPatterns(array $patterns):array{

		// Find distances between pattern centers
		$zeroOneDistance = $patterns[0]->distance($patterns[1]);
		$oneTwoDistance  = $patterns[1]->distance($patterns[2]);
		$zeroTwoDistance = $patterns[0]->distance($patterns[2]);

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
		if($this->crossProductZ($pointA, $pointB, $pointC) < 0.0){
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
	 * Returns the z component of the cross product between vectors BC and BA.
	 */
	private function crossProductZ(FinderPattern $pointA, FinderPattern $pointB, FinderPattern $pointC):float{
		$bX = $pointB->getX();
		$bY = $pointB->getY();

		return (($pointC->getX() - $bX) * ($pointA->getY() - $bY)) - (($pointC->getY() - $bY) * ($pointA->getX() - $bX));
	}

}
