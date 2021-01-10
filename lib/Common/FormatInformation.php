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

namespace Zxing\Common;

/**
 * <p>Encapsulates a QR Code's format information, including the data mask used and
 * error correction level.</p>
 *
 * @author Sean Owen
 * @see    \Zxing\Common\ErrorCorrectionLevel
 */
final class FormatInformation{

	private const FORMAT_INFO_MASK_QR = 0x5412;

	/**
	 * See ISO 18004:2006, Annex C, Table C.1
	 */
	public const FORMAT_INFO_DECODE_LOOKUP = [
		[0x5412, 0x00],
		[0x5125, 0x01],
		[0x5E7C, 0x02],
		[0x5B4B, 0x03],
		[0x45F9, 0x04],
		[0x40CE, 0x05],
		[0x4F97, 0x06],
		[0x4AA0, 0x07],
		[0x77C4, 0x08],
		[0x72F3, 0x09],
		[0x7DAA, 0x0A],
		[0x789D, 0x0B],
		[0x662F, 0x0C],
		[0x6318, 0x0D],
		[0x6C41, 0x0E],
		[0x6976, 0x0F],
		[0x1689, 0x10],
		[0x13BE, 0x11],
		[0x1CE7, 0x12],
		[0x19D0, 0x13],
		[0x0762, 0x14],
		[0x0255, 0x15],
		[0x0D0C, 0x16],
		[0x083B, 0x17],
		[0x355F, 0x18],
		[0x3068, 0x19],
		[0x3F31, 0x1A],
		[0x3A06, 0x1B],
		[0x24B4, 0x1C],
		[0x2183, 0x1D],
		[0x2EDA, 0x1E],
		[0x2BED, 0x1F],
	];

	private int $errorCorrectionLevel;
	private int $dataMask;

	private function __construct(int $formatInfo){
		// Bits 3,4
		$this->errorCorrectionLevel = ($formatInfo >> 3) & 0x03;
		// Bottom 3 bits
		$this->dataMask = ($formatInfo & 0x07);//(byte)
	}

	/**
	 * @param int $maskedFormatInfo1 format info indicator, with mask still applied
	 * @param int $maskedFormatInfo2 second copy of same info; both are checked at the same time
	 *                               to establish best match
	 *
	 * @return \Zxing\Common\FormatInformation information about the format it specifies, or {@code null}
	 *                                         if doesn't seem to match any known pattern
	 */
	public static function decodeFormatInformation(int $maskedFormatInfo1, int $maskedFormatInfo2):?FormatInformation{
		$formatInfo = self::doDecodeFormatInformation($maskedFormatInfo1, $maskedFormatInfo2);

		if($formatInfo !== null){
			return $formatInfo;
		}

		// Should return null, but, some QR codes apparently
		// do not mask this info. Try again by actually masking the pattern
		// first
		return self::doDecodeFormatInformation(
			$maskedFormatInfo1 ^ self::FORMAT_INFO_MASK_QR,
			$maskedFormatInfo2 ^ self::FORMAT_INFO_MASK_QR
		);
	}

	private static function doDecodeFormatInformation(int $maskedFormatInfo1, int $maskedFormatInfo2):?FormatInformation{
		// Find the int in FORMAT_INFO_DECODE_LOOKUP with fewest bits differing
		$bestDifference = \PHP_INT_MAX;
		$bestFormatInfo = 0;

		foreach(self::FORMAT_INFO_DECODE_LOOKUP as $decodeInfo){
			$targetInfo = $decodeInfo[0];

			if($targetInfo === $maskedFormatInfo1 || $targetInfo === $maskedFormatInfo2){
				// Found an exact match
				return new self($decodeInfo[1]);
			}

			$bitsDifference = numBitsDiffering($maskedFormatInfo1, $targetInfo);

			if($bitsDifference < $bestDifference){
				$bestFormatInfo = $decodeInfo[1];
				$bestDifference = $bitsDifference;
			}

			if($maskedFormatInfo1 !== $maskedFormatInfo2){
				// also try the other option
				$bitsDifference = numBitsDiffering($maskedFormatInfo2, $targetInfo);
				if($bitsDifference < $bestDifference){
					$bestFormatInfo = $decodeInfo[1];
					$bestDifference = $bitsDifference;
				}
			}
		}
		// Hamming distance of the 32 masked codes is 7, by construction, so <= 3 bits
		// differing means we found a match
		if($bestDifference <= 3){
			return new self($bestFormatInfo);
		}

		return null;
	}

	public function getErrorCorrectionLevel():int{
		return $this->errorCorrectionLevel;
	}

	public function getDataMask():int{
		return $this->dataMask;
	}

}

