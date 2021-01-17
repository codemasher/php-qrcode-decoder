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

namespace Zxing\Decoder;

use chillerlan\QRCode\Common\Version;
use Zxing\Common\FormatInformation;
use RuntimeException;

use function Zxing\Common\numBitsDiffering;

/**
 * @author Sean Owen
 */
final class BitMatrixParser{

	private BitMatrix          $bitMatrix;
	private ?Version           $parsedVersion    = null;
	private ?FormatInformation $parsedFormatInfo = null;
	private bool               $mirror           = false;

	/**
	 * @param \Zxing\Decoder\BitMatrix $bitMatrix
	 *
	 * @throws \RuntimeException if dimension is not >= 21 and 1 mod 4
	 */
	public function __construct(BitMatrix $bitMatrix){
		$dimension = $bitMatrix->getHeight();

		if($dimension < 21 || ($dimension % 4) !== 1){
			throw new RuntimeException('dimension is not >= 21, dimension mod 4 not 1');
		}

		$this->bitMatrix = $bitMatrix;
	}

	/**
	 * <p>Reads the bits in the {@link BitMatrix} representing the finder pattern in the
	 * correct order in order to reconstruct the codewords bytes contained within the
	 * QR Code.</p>
	 *
	 * @return array bytes encoded within the QR Code
	 * @throws \RuntimeException if the exact number of bytes expected is not read
	 */
	public function readCodewords():array{
		$formatInfo = $this->readFormatInformation();
		$version    = $this->readVersion();

		// Get the data mask for the format used in this QR Code. This will exclude
		// some bits from reading as we wind through the bit matrix.
		$dimension = $this->bitMatrix->getHeight();
		$this->bitMatrix->unmask($dimension, $formatInfo->getDataMask());
		$functionPattern = $this->bitMatrix->buildFunctionPattern($version);

		$readingUp    = true;
		$result       = \array_fill(0, $version->getTotalCodewords(), 0);
		$resultOffset = 0;
		$currentByte  = 0;
		$bitsRead     = 0;
		// Read columns in pairs, from right to left
		for($j = $dimension - 1; $j > 0; $j -= 2){
			if($j === 6){
				// Skip whole column with vertical alignment pattern;
				// saves time and makes the other code proceed more cleanly
				$j--;
			}
			// Read alternatingly from bottom to top then top to bottom
			for($count = 0; $count < $dimension; $count++){
				$i = $readingUp ? $dimension - 1 - $count : $count;
				for($col = 0; $col < 2; $col++){
					// Ignore bits covered by the function pattern
					if(!$functionPattern->get($j - $col, $i)){
						// Read a bit
						$bitsRead++;
						$currentByte <<= 1;
						if($this->bitMatrix->get($j - $col, $i)){
							$currentByte |= 1;
						}
						// If we've made a whole byte, save it off
						if($bitsRead === 8){
							$result[$resultOffset++] = $currentByte; //(byte)
							$bitsRead                = 0;
							$currentByte             = 0;
						}
					}
				}
			}
			$readingUp ^= true; // readingUp = !readingUp; // switch directions
		}

		if($resultOffset !== $version->getTotalCodewords()){
			throw new RuntimeException('offset differs from total codewords for version');
		}

		return $result;
	}

	/**
	 * <p>Reads format information from one of its two locations within the QR Code.</p>
	 *
	 * @return \Zxing\Common\FormatInformation encapsulating the QR Code's format info
	 * @throws \RuntimeException               if both format information locations cannot be parsed as
	 *                                         the valid encoding of format information
	 */
	public function readFormatInformation():FormatInformation{

		if($this->parsedFormatInfo != null){
			return $this->parsedFormatInfo;
		}

		// Read top-left format info bits
		$formatInfoBits1 = 0;

		for($i = 0; $i < 6; $i++){
			$formatInfoBits1 = $this->copyBit($i, 8, $formatInfoBits1);
		}

		// .. and skip a bit in the timing pattern ...
		$formatInfoBits1 = $this->copyBit(7, 8, $formatInfoBits1);
		$formatInfoBits1 = $this->copyBit(8, 8, $formatInfoBits1);
		$formatInfoBits1 = $this->copyBit(8, 7, $formatInfoBits1);
		// .. and skip a bit in the timing pattern ...
		for($j = 5; $j >= 0; $j--){
			$formatInfoBits1 = $this->copyBit(8, $j, $formatInfoBits1);
		}

		// Read the top-right/bottom-left pattern too
		$dimension       = $this->bitMatrix->getHeight();
		$formatInfoBits2 = 0;
		$jMin            = $dimension - 7;

		for($j = $dimension - 1; $j >= $jMin; $j--){
			$formatInfoBits2 = $this->copyBit(8, $j, $formatInfoBits2);
		}

		for($i = $dimension - 8; $i < $dimension; $i++){
			$formatInfoBits2 = $this->copyBit($i, 8, $formatInfoBits2);
		}

		$parsedFormatInfo = FormatInformation::decodeFormatInformation($formatInfoBits1, $formatInfoBits2);

		if($parsedFormatInfo !== null){
			return $parsedFormatInfo;
		}

		throw new RuntimeException('failed to read format info');
	}

	private function copyBit(int $i, int $j, int $versionBits):int{

		$bit = $this->mirror
			? $this->bitMatrix->get($j, $i)
			: $this->bitMatrix->get($i, $j);

		return $bit ? ($versionBits << 1) | 0x1 : $versionBits << 1;
	}

	/**
	 * <p>Reads version information from one of its two locations within the QR Code.</p>
	 *
	 * @return \chillerlan\QRCode\Common\Version encapsulating the QR Code's version
	 * @throws \RuntimeException                 if both version information locations cannot be parsed as
	 *                                           the valid encoding of version information
	 */
	public function readVersion():Version{

		if($this->parsedVersion !== null){
			return $this->parsedVersion;
		}

		$dimension          = $this->bitMatrix->getHeight();
		$provisionalVersion = ($dimension - 17) / 4;

		if($provisionalVersion <= 6){
			return new Version($provisionalVersion);
		}

		// Read top-right version info: 3 wide by 6 tall
		$versionBits = 0;
		$ijMin       = $dimension - 11;

		for($j = 5; $j >= 0; $j--){
			for($i = $dimension - 9; $i >= $ijMin; $i--){
				$versionBits = $this->copyBit($i, $j, $versionBits);
			}
		}

		$theParsedVersion = $this->decodeVersionInformation($versionBits);

		if($theParsedVersion !== null && $theParsedVersion->getDimension() === $dimension){
			$this->parsedVersion = $theParsedVersion;

			return $theParsedVersion;
		}

		// Hmm, failed. Try bottom left: 6 wide by 3 tall
		$versionBits = 0;

		for($i = 5; $i >= 0; $i--){
			for($j = $dimension - 9; $j >= $ijMin; $j--){
				$versionBits = $this->copyBit($i, $j, $versionBits);
			}
		}

		$theParsedVersion = $this->decodeVersionInformation($versionBits);

		if($theParsedVersion !== null && $theParsedVersion->getDimension() === $dimension){
			$this->parsedVersion = $theParsedVersion;

			return $theParsedVersion;
		}

		throw new RuntimeException('failed to read version');
	}

	private function decodeVersionInformation(int $versionBits):?Version{
		$bestDifference = \PHP_INT_MAX;
		$bestVersion    = 0;

		for($i = 7; $i <= 40; $i++){
			$targetVersion        = new Version($i);
			$targetVersionPattern = $targetVersion->getVersionPattern();

			// Do the version info bits match exactly? done.
			if($targetVersionPattern === $versionBits){
				return $targetVersion;
			}

			// Otherwise see if this is the closest to a real version info bit string
			// we have seen so far
			$bitsDifference = numBitsDiffering($versionBits, $targetVersionPattern);

			if($bitsDifference < $bestDifference){
				$bestVersion    = $i;
				$bestDifference = $bitsDifference;
			}
		}
		// We can tolerate up to 3 bits of error since no two version info codewords will
		// differ in less than 8 bits.
		if($bestDifference <= 3){
			return new Version($bestVersion);
		}

		// If we didn't find a close enough match, fail
		return null;
	}

	/**
	 * Revert the mask removal done while reading the code words. The bit matrix should revert to its original state.
	 */
	public function remask():void{

		if($this->parsedFormatInfo === null){
			return; // We have no format information, and have no data mask
		}

		$dimension = $this->bitMatrix->getHeight();
		$this->bitMatrix->unmask($dimension, $this->parsedFormatInfo->getDataMask());
	}

	/**
	 * Prepare the parser for a mirrored operation.
	 * This flag has effect only on the {@link #readFormatInformation()} and the
	 * {@link #readVersion()}. Before proceeding with {@link #readCodewords()} the
	 * {@link #mirror()} method should be called.
	 *
	 * @param bool mirror Whether to read version and format information mirrored.
	 */
	public function setMirror(bool $mirror):void{
		$this->parsedVersion    = null;
		$this->parsedFormatInfo = null;
		$this->mirror           = $mirror;
	}

	/** Mirror the bit matrix in order to attempt a second reading. */
	public function mirror():void{
		$this->bitMatrix->mirror();
	}

}
