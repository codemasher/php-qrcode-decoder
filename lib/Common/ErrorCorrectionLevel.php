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
 * <p>See ISO 18004:2006, 6.5.1. This enum encapsulates the four error correction levels
 * defined by the QR code standard.</p>
 *
 * @author Sean Owen
 */
class ErrorCorrectionLevel{

	public const ECC_LEVELS = [
		0b00 => [1, 'M'], // 15%
		0b01 => [0, 'L'], // 7%
		0b10 => [3, 'H'], // 30%
		0b11 => [2, 'Q'], // 25%
	];

	private int $bits;

	/**
	 * @param int $bits containing the two bits encoding a QR Code's error correction level
	 */
	public function __construct(int $bits){

		if($bits < 0b00 || $bits >= 0b11){
			throw new InvalidArgumentException();
		}

		$this->bits = $bits;
	}

	public function __toString(){
		return self::ECC_LEVELS[$this->bits][1];
	}

	public function getOrdinal(){
		return self::ECC_LEVELS[$this->bits][0];
	}

}
