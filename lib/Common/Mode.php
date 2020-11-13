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

use InvalidArgumentException;

/**
 * <p>See ISO 18004:2006, 6.4.1, Tables 2 and 3. This enum encapsulates the various modes in which
 * data can be encoded to bits in the QR code standard.</p>
 *
 * @author Sean Owen
 */
class Mode{

	// ISO/IEC 18004:2000 Section 8.4, Table 2 - Mode indicators

	/** @var int */
	public const DATA_TERMINATOR = 0b0000;
	/** @var int */
	public const DATA_NUMBER = 0b0001;
	/** @var int */
	public const DATA_ALPHANUM = 0b0010;
	/** @var int */
	public const DATA_BYTE = 0b0100;
	/** @var int */
	public const DATA_KANJI = 0b1000;
	/** @var int */
	public const DATA_STRCTURED_APPEND = 0b0011;
	/** @var int */
	public const DATA_FNC1_FIRST = 0b0101;
	/** @var int */
	public const DATA_FNC1_SECOND = 0b1001;
	/** @var int */
	public const DATA_ECI = 0b0111;
	/** @var int */
	public const DATA_HANZI = 0b1101;

	/**
	 * ISO/IEC 18004:2000 Section 8.4, Table 3 - Number of bits in Character Count Indicator
	 *
	 * @var int[][]
	 */
	protected const LENGTH_BITS = [
		self::DATA_NUMBER   => [10, 12, 14],
		self::DATA_ALPHANUM => [9, 11, 13],
		self::DATA_BYTE     => [8, 16, 16],
		self::DATA_KANJI    => [8, 10, 12],
		self::DATA_HANZI    => [8, 10, 12],
	];

	public static function getCharacterCountBits(int $version, int $mode):int{

		foreach([9, 26, 40] as $key => $breakpoint){
			if($version <= $breakpoint){
				return self::LENGTH_BITS[$mode][$key];
			}
		}

		throw new InvalidArgumentException();
	}

}
