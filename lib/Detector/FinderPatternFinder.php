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

use Zxing\Common\{MathUtils, NotFoundException};
use Zxing\Decoder\BitMatrix;

/**
 * <p>This class attempts to find finder patterns in a QR Code. Finder patterns are the square
 * markers at three corners of a QR Code.</p>
 *
 * <p>This class is thread-safe but not reentrant. Each thread must allocate its own object.
 *
 * @author Sean Owen
 *
 * @todo: port updates: https://github.com/zxing/zxing/blob/3c96923276dd5785d58eb970b6ba3f80d36a9505/core/src/main/java/com/google/zxing/qrcode/detector/FinderPatternFinder.java#
 */
final class FinderPatternFinder{

	private const MIN_SKIP      = 3;
	private const MAX_MODULES   = 57; // 1 pixel/module times 3 modules/center
	private const CENTER_QUORUM = 2; // support up to version 10 for mobile clients
	private BitMatrix $bitMatrix;
	private float $average;
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
		$this->crossCheckStateCount = \array_fill(0, 5, 0);
	}

	final public function find():FinderPatternInfo{
		$tryHarder   = true;
		$maxI        = $this->bitMatrix->getHeight();
		$maxJ        = $this->bitMatrix->getWidth();
		// We are looking for black/white/black/white/black modules in
		// 1:1:3:1:1 ratio; this tracks the number of such modules seen so far

		// Let's assume that the maximum version QR Code we support takes up 1/4 the height of the
		// image, and then account for the center being 3 modules in size. This gives the smallest
		// number of pixels the center could be, so skip this often. When trying harder, look for all
		// QR versions regardless of how dense they are.
		$iSkip = (int)((3 * $maxI) / (4 * self::MAX_MODULES));

		if($iSkip < self::MIN_SKIP || $tryHarder){
			$iSkip = self::MIN_SKIP;
		}

		$done       = false;

		for($i = $iSkip - 1; $i < $maxI && !$done; $i += $iSkip){
			// Get a row of black/white values
			$stateCount = \array_fill(0, 5, 0);
			$currentState  = 0;

			for($j = 0; $j < $maxJ; $j++){

				if($this->bitMatrix->get($j, $i)){
					// Black pixel
					if(($currentState & 1) == 1){ // Counting white pixels
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
									$stateCount[0] = $stateCount[2];
									$stateCount[1] = $stateCount[3];
									$stateCount[2] = $stateCount[4];
									$stateCount[3] = 1;
									$stateCount[4] = 0;
									$currentState  = 3;
									continue;
								}
								// Clear state to start looking again
								$currentState = 0;
								$stateCount   = \array_fill(0, 5, 0);
							}
							else{ // No, shift counts back by two
								$stateCount[0] = $stateCount[2];
								$stateCount[1] = $stateCount[3];
								$stateCount[2] = $stateCount[4];
								$stateCount[3] = 1;
								$stateCount[4] = 0;
								$currentState  = 3;
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

	/**
	 * Orders an array of three ResultPoints in an order [A,B,C] such that AB is less than AC
	 * and BC is less than AC, and the angle between BC and BA is less than 180 degrees.
	 *
	 * @param ResultPoint[] patterns array of three {@code ResultPoint} to order
	 */
	public function orderBestPatterns(array $patterns):array{

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

	/**
	 * @param \Zxing\Detector\FinderPattern $pattern1 first pattern
	 * @param \Zxing\Detector\FinderPattern $pattern2 second pattern
	 *
	 * @return float distance between two points
	 */
	public static function distance(FinderPattern $pattern1, FinderPattern $pattern2):float{
		return MathUtils::distance($pattern1->getX(), $pattern1->getY(), $pattern2->getX(), $pattern2->getY());
	}

	/**
	 * @param int[] $stateCount count of black/white/black/white/black pixels just read
	 *
	 * @return bool true if the proportions of the counts is close enough to the 1/1/3/1/1 ratios
	 *              used by finder patterns to be considered a match
	 */
	private function foundPatternCross(array $stateCount):bool{
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
		$maxVariance = $moduleSize / 2.0;

		// Allow less than 50% variance from 1-1-3-1-1 proportions
		return
			\abs($moduleSize - $stateCount[0]) < $maxVariance &&
			\abs($moduleSize - $stateCount[1]) < $maxVariance &&
			\abs(3.0 * $moduleSize - $stateCount[2]) < 3 * $maxVariance &&
			\abs($moduleSize - $stateCount[3]) < $maxVariance &&
			\abs($moduleSize - $stateCount[4]) < $maxVariance;
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
		$centerI         = $this->crossCheckVertical($i, (int)($centerJ), $stateCount[2], $stateCountTotal);

		if(!\is_nan($centerI)){
			// Re-cross check
			$centerJ = $this->crossCheckHorizontal((int)($centerJ), (int)($centerI), $stateCount[2], $stateCountTotal);
			if(!\is_nan($centerJ)
			   && ($this->crossCheckDiagonal((int)($centerI), (int)($centerJ), $stateCount[2], $stateCountTotal))
			){
				$estimatedModuleSize = (float)$stateCountTotal / 7.0;
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
	 * Given a count of black/white/black/white/black pixels just seen and an end position,
	 * figures the location of the center of this run.
	 */
	private function centerFromEnd(array $stateCount, int $end):float{
		return (float)(($end - $stateCount[4] - $stateCount[3]) - $stateCount[2] / 2.0);
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
		$image = $this->bitMatrix;

		$maxI       = $image->getHeight();
		$stateCount = $this->getCrossCheckStateCount();

		// Start counting up from center
		$i = $startI;
		while($i >= 0 && $image->get($centerJ, $i)){
			$stateCount[2]++;
			$i--;
		}

		if($i < 0){
			return \NAN;
		}

		while($i >= 0 && !$image->get($centerJ, $i) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$i--;
		}

		// If already too many modules in this state or ran off the edge:
		if($i < 0 || $stateCount[1] > $maxCount){
			return \NAN;
		}

		while($i >= 0 && $image->get($centerJ, $i) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$i--;
		}

		if($stateCount[0] > $maxCount){
			return \NAN;
		}

		// Now also count down from center
		$i = $startI + 1;
		while($i < $maxI && $image->get($centerJ, $i)){
			$stateCount[2]++;
			$i++;
		}

		if($i === $maxI){
			return \NAN;
		}

		while($i < $maxI && !$image->get($centerJ, $i) && $stateCount[3] < $maxCount){
			$stateCount[3]++;
			$i++;
		}

		if($i === $maxI || $stateCount[3] >= $maxCount){
			return \NAN;
		}

		while($i < $maxI && $image->get($centerJ, $i) && $stateCount[4] < $maxCount){
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
	 * @return int[]
	 */
	private function getCrossCheckStateCount():array{
		$this->crossCheckStateCount[0] = 0;
		$this->crossCheckStateCount[1] = 0;
		$this->crossCheckStateCount[2] = 0;
		$this->crossCheckStateCount[3] = 0;
		$this->crossCheckStateCount[4] = 0;

		return $this->crossCheckStateCount;
	}

	/**
	 * <p>Like {@link #crossCheckVertical(int, int, int, int)}, and in fact is basically identical,
	 * except it reads horizontally instead of vertically. This is used to cross-cross
	 * check a vertical cross check and locate the real center of the alignment pattern.</p>
	 */
	private function crossCheckHorizontal(int $startJ, int $centerI, int $maxCount, int $originalStateCountTotal):float{
		$image = $this->bitMatrix;

		$maxJ       = $this->bitMatrix->getWidth();
		$stateCount = $this->getCrossCheckStateCount();

		$j = $startJ;
		while($j >= 0 && $image->get($j, $centerI)){
			$stateCount[2]++;
			$j--;
		}

		if($j < 0){
			return \NAN;
		}

		while($j >= 0 && !$image->get($j, $centerI) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$j--;
		}

		if($j < 0 || $stateCount[1] > $maxCount){
			return \NAN;
		}

		while($j >= 0 && $image->get($j, $centerI) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$j--;
		}

		if($stateCount[0] > $maxCount){
			return \NAN;
		}

		$j = $startJ + 1;
		while($j < $maxJ && $image->get($j, $centerI)){
			$stateCount[2]++;
			$j++;
		}

		if($j === $maxJ){
			return \NAN;
		}

		while($j < $maxJ && !$image->get($j, $centerI) && $stateCount[3] < $maxCount){
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
		if(5 * \abs(($stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4]) - $originalStateCountTotal) >= $originalStateCountTotal){
			return \NAN;
		}

		return $this->foundPatternCross($stateCount) ? $this->centerFromEnd($stateCount, $j) : \NAN;
	}

	/**
	 * After a vertical and horizontal scan finds a potential finder pattern, this method
	 * "cross-cross-cross-checks" by scanning down diagonally through the center of the possible
	 * finder pattern to see if the same proportion is detected.
	 *
	 * @param $startI                  ;  row where a finder pattern was detected
	 * @param $centerJ                 ; center of the section that appears to cross a finder pattern
	 * @param $maxCount                ; maximum reasonable number of modules that should be
	 *                                 observed in any reading state, based on the results of the horizontal scan
	 * @param $originalStateCountTotal ; The original state count total.
	 *
	 * @return bool true if proportions are withing expected limits
	 */
	private function crossCheckDiagonal(int $startI, int $centerJ, int $maxCount, int $originalStateCountTotal):bool{
		$stateCount = $this->getCrossCheckStateCount();

		// Start counting up, left from center finding black center mass
		$i       = 0;

		while($startI >= $i && $centerJ >= $i && $this->bitMatrix->get($centerJ - $i, $startI - $i)){
			$stateCount[2]++;
			$i++;
		}

		if($startI < $i || $centerJ < $i){
			return false;
		}

		// Continue up, left finding white space
		while($startI >= $i && $centerJ >= $i && !$this->bitMatrix->get($centerJ - $i, $startI - $i) && $stateCount[1] <= $maxCount){
			$stateCount[1]++;
			$i++;
		}

		// If already too many modules in this state or ran off the edge:
		if($startI < $i || $centerJ < $i || $stateCount[1] > $maxCount){
			return false;
		}

		// Continue up, left finding black border
		while($startI >= $i && $centerJ >= $i && $this->bitMatrix->get($centerJ - $i, $startI - $i) && $stateCount[0] <= $maxCount){
			$stateCount[0]++;
			$i++;
		}

		if($stateCount[0] > $maxCount){
			return false;
		}

		$maxI = $this->bitMatrix->getHeight();
		$maxJ = $this->bitMatrix->getWidth();

		// Now also count down, right from center
		$i = 1;
		while($startI + $i < $maxI && $centerJ + $i < $maxJ && $this->bitMatrix->get($centerJ + $i, $startI + $i)){
			$stateCount[2]++;
			$i++;
		}

		// Ran off the edge?
		if($startI + $i >= $maxI || $centerJ + $i >= $maxJ){
			return false;
		}

		while($startI + $i < $maxI && $centerJ + $i < $maxJ && !$this->bitMatrix->get($centerJ + $i, $startI + $i) && $stateCount[3] < $maxCount){
			$stateCount[3]++;
			$i++;
		}

		if($startI + $i >= $maxI || $centerJ + $i >= $maxJ || $stateCount[3] >= $maxCount){
			return false;
		}

		while($startI + $i < $maxI && $centerJ + $i < $maxJ && $this->bitMatrix->get($centerJ + $i, $startI + $i)
		      && $stateCount[4] < $maxCount){
			$stateCount[4]++;
			$i++;
		}

		if($stateCount[4] >= $maxCount){
			return false;
		}

		// If we found a finder-pattern-like section, but its size is more than 100% different than
		// the original, assume it's a false positive
		$stateCountTotal = $stateCount[0] + $stateCount[1] + $stateCount[2] + $stateCount[3] + $stateCount[4];

		return \abs($stateCountTotal - $originalStateCountTotal) < 2 * $originalStateCountTotal && $this->foundPatternCross($stateCount);
	}

	/**
	 * @return bool true if we have found at least 3 finder patterns that have been detected
	 *         at least {@link #CENTER_QUORUM} times each, and, the estimated module size of the
	 *         candidates is "pretty similar"
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

				if($firstConfirmedCenter == null){
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

		// Filter outlier possibilities whose module size is too different
		if($startSize > 3){
			// But we can only afford to do so if we have at least 4 possibilities to choose from
			$totalModuleSize = 0.0;
			$square          = 0.0;

			foreach($this->possibleCenters as $center){
				$size            = $center->getEstimatedModuleSize();
				$totalModuleSize += $size;
				$square          += $size * $size;
			}

			$this->average = $totalModuleSize / (float)$startSize;
			$stdDev        = (float)sqrt($square / $startSize - $this->average * $this->average);

			\usort($this->possibleCenters, [$this, 'FurthestFromAverageComparator']);

			$limit = \max(0.2 * $this->average, $stdDev);

			// this is horrific
			for($i = 0; $i < \count($this->possibleCenters) && \count($this->possibleCenters) > 3; $i++){
				$pattern = $this->possibleCenters[$i];

				if(\abs($pattern->getEstimatedModuleSize() - $this->average) > $limit){
					unset($this->possibleCenters[$i]);//возможно что ключи меняются в java при вызове .remove(i) ???
					$this->possibleCenters = \array_values($this->possibleCenters);
					$i--;
				}
			}
		}

		if(\count($this->possibleCenters) > 3){
			// Throw away all but those first size candidate points we found.

			$totalModuleSize = 0.0;

			foreach($this->possibleCenters as $possibleCenter){
				$totalModuleSize += $possibleCenter->getEstimatedModuleSize();
			}

			$this->average = $totalModuleSize / (float)\count($this->possibleCenters);

			\usort($this->possibleCenters, [$this, 'CenterComparator']);

			$this->possibleCenters = \array_slice($this->possibleCenters, 3, \count($this->possibleCenters) - 3);
		}

		if(\count($this->possibleCenters) < 3){
			// Couldn't find enough finder patterns
			throw new NotFoundException;
		}

#		\var_dump($this->possibleCenters);
		return $this->possibleCenters;
	}

	/**
	 * <p>Orders by furthest from average</p>
	 */
	public function FurthestFromAverageComparator(FinderPattern $center1, FinderPattern $center2):int{
		$dA = abs($center2->getEstimatedModuleSize() - $this->average);
		$dB = abs($center1->getEstimatedModuleSize() - $this->average);

		if($dA < $dB){
			return -1;
		}

		if($dA === $dB){
			return 0;
		}

		return 1;
	}

	public function CenterComparator(FinderPattern $center1, FinderPattern $center2):int{

		if($center2->getCount() !== $center1->getCount()){
			return $center2->getCount() - $center1->getCount();
		}

		$dA = abs($center2->getEstimatedModuleSize() - $this->average);
		$dB = abs($center1->getEstimatedModuleSize() - $this->average);

		if($dA < $dB){
			return 1;
		}

		if($dA === $dB){
			return 0;
		}

		return -1;
	}

	protected final function getImage():BitMatrix{
		return $this->bitMatrix;
	}

	/**
	 * <p>Orders by {@link FinderPattern#getCount()}, descending.</p>
	 */

	protected final function getPossibleCenters():array{ //List<FinderPattern> getPossibleCenters()
		return $this->possibleCenters;
	}
}
