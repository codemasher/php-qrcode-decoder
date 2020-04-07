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

namespace Zxing\Qrcode\Decoder;

use Closure;
use InvalidArgumentException;
use Zxing\Common\BitMatrix;

/**
 * <p>Encapsulates data masks for the data bits in a QR code, per ISO 18004:2006 6.8. Implementations
 * of this class can un-mask a raw BitMatrix. For simplicity, they will unmask the entire BitMatrix,
 * including areas used for finder patterns, timing patterns, etc. These areas should be unused
 * after the point they are unmasked anyway.</p>
 *
 * <p>Note that the diagram in section 6.8.1 is misleading since it indicates that i is column position
 * and j is row position. In fact, as the text says, i is row position and j is column position.</p>
 *
 * @author Sean Owen
 */
class DataMask{

	/**
	 * <p>Implementations of this method reverse the data masking process applied to a QR Code and
	 * make its bits ready to read.</p>
	 *
	 * @param \Zxing\Common\BitMatrix $bits
	 * @param int                     $dimension
	 * @param int                     $maskPattern
	 *
	 * @return \Zxing\Common\BitMatrix
	 */
	public static function unmaskBitMatrix(BitMatrix $bits, int $dimension, int $maskPattern):BitMatrix{
		$mask = self::getMask($maskPattern);

		for($i = 0; $i < $dimension; $i++){
			for($j = 0; $j < $dimension; $j++){
				if($mask($i, $j) === 0){
					$bits->flip($j, $i);
				}
			}
		}

		return $bits;
	}

	/**
	 * @param int $maskPattern a value between 0 and 7 indicating one of the eight possible
	 *                 data mask patterns a QR Code may use
	 *
	 * @return \Closure
	 */
	protected static function getMask(int $maskPattern):Closure{

		return [
			0b000 => fn($i, $j):int => ($i + $j) % 2,
			0b001 => fn($i, $j):int => $i % 2,
			0b010 => fn($i, $j):int => $j % 3,
			0b011 => fn($i, $j):int => ($i + $j) % 3,
			0b100 => fn($i, $j):int => ((int)($i / 2) + (int)($j / 3)) % 2,
			0b101 => fn($i, $j):int => (($i * $j) % 2) + (($i * $j) % 3),
			0b110 => fn($i, $j):int => ((($i * $j) % 2) + (($i * $j) % 3)) % 2,
			0b111 => fn($i, $j):int => ((($i * $j) % 3) + (($i + $j) % 2)) % 2,
		][$maskPattern];

	}

}
