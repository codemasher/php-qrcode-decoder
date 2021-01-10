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

use Zxing\Common\{CharacterSetECI};
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Mode;
use chillerlan\QRCode\Common\Version;

/**
 * <p>QR Codes can encode text as bits in one of several modes, and can use multiple modes
 * in one QR Code. This class decodes the bits back into text.</p>
 *
 * <p>See ISO 18004:2006, 6.4.3 - 6.4.7</p>
 *
 * @author Sean Owen
 */
final class DecodedBitStreamParser{

	/**
	 * See ISO 18004:2006, 6.4.4 Table 5
	 */
	private const ALPHANUMERIC_CHARS = [
		'0', '1', '2', '3', '4', '5', '6', '7', '8', '9', 'A', 'B',
		'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K', 'L', 'M', 'N',
		'O', 'P', 'Q', 'R', 'S', 'T', 'U', 'V', 'W', 'X', 'Y', 'Z',
		' ', '$', '%', '*', '+', '-', '.', '/', ':',
	];

	private const GB2312_SUBSET = 1;

	/**
	 * @throws \Zxing\Decoder\FormatException
	 */
	public function decode(array $bytes, Version $version, EccLevel $ecLevel):DecoderResult{
		$bits           = new BitSource($bytes);
		$result         = '';
		$byteSegments   = [];
		$symbolSequence = -1;
		$parityData     = -1;
		$versionNumber  = $version->getVersionNumber();

		$currentCharacterSetECI = null;
		$fc1InEffect            = false;

		// While still another segment to read...
		while($bits->available() >= 4){
			$mode = $bits->readBits(4); // mode is encoded by 4 bits

			// OK, assume we're done. Really, a TERMINATOR mode should have been recorded here
			if($mode === Mode::DATA_TERMINATOR){
				break;
			}

			if($mode === Mode::DATA_FNC1_FIRST || $mode === Mode::DATA_FNC1_SECOND){
				// We do little with FNC1 except alter the parsed result a bit according to the spec
				$fc1InEffect = true;
			}
			elseif($mode === Mode::DATA_STRCTURED_APPEND){
				if($bits->available() < 16){
					throw new FormatException();
				}
				// sequence number and parity is added later to the result metadata
				// Read next 8 bits (symbol sequence #) and 8 bits (parity data), then continue
				$symbolSequence = $bits->readBits(8);
				$parityData     = $bits->readBits(8);
			}
			elseif($mode === Mode::DATA_ECI){
				// Count doesn't apply to ECI
				$value                  = $this->parseECIValue($bits);
				$currentCharacterSetECI = CharacterSetECI::getCharacterSetECIByValue($value);
				if($currentCharacterSetECI === null){
					throw new FormatException();
				}
			}
			else{
				// First handle Hanzi mode which does not start with character count
/*				if($mode === Mode::DATA_HANZI){
					//chinese mode contains a sub set indicator right after mode indicator
					$subset     = $bits->readBits(4);
					$countHanzi = $bits->readBits(Mode::getLengthBitsForVersion($mode, $versionNumber));
					if($subset == self::GB2312_SUBSET){
						$this->decodeHanziSegment($bits, $result, $countHanzi);
					}
				}*/
#				else{
					// "Normal" QR code modes:
					// How many characters will follow, encoded in this mode?
					$count = $bits->readBits(Mode::getLengthBitsForVersion($mode, $versionNumber));
					if($mode === Mode::DATA_NUMBER){
						$this->decodeNumericSegment($bits, $result, $count);
					}
					elseif($mode === Mode::DATA_ALPHANUM){
						$this->decodeAlphanumericSegment($bits, $result, $count, $fc1InEffect);
					}
					elseif($mode === Mode::DATA_BYTE){
						$this->decodeByteSegment($bits, $result, $count, $byteSegments, $currentCharacterSetECI);
					}
					elseif($mode === Mode::DATA_KANJI){
						$this->decodeKanjiSegment($bits, $result, $count);
					}
					else{
						throw new FormatException();
					}
#				}
			}
		}

		return new DecoderResult($bytes, $result, $byteSegments, (string)$ecLevel, $symbolSequence, $parityData);
	}

	/**
	 * @throws \Zxing\Decoder\FormatException
	 */
	private function parseECIValue(BitSource $bits):int{
		$firstByte = $bits->readBits(8);

		if(($firstByte & 0x80) === 0){
			// just one byte
			return $firstByte & 0x7F;
		}

		if(($firstByte & 0xC0) === 0x80){
			// two bytes
			$secondByte = $bits->readBits(8);

			return (($firstByte & 0x3F) << 8) | $secondByte;
		}

		if(($firstByte & 0xE0) === 0xC0){
			// three bytes
			$secondThirdBytes = $bits->readBits(16);

			return (($firstByte & 0x1F) << 16) | $secondThirdBytes;
		}

		throw new FormatException();
	}

	/**
	 * See specification GBT 18284-2000
	 *
	 * @throws \Zxing\Decoder\FormatException
	 */
	private function decodeHanziSegment(BitSource $bits, string &$result, int $count):void{
		// Don't crash trying to read more bits than we have available.
		if($count * 13 > $bits->available()){
			throw new FormatException();
		}

		// Each character will require 2 bytes. Read the characters as 2-byte pairs
		// and decode as GB2312 afterwards
		$buffer = \array_fill(0, 2 * $count, 0);
		$offset = 0;

		while($count > 0){
			// Each 13 bits encodes a 2-byte character
			$twoBytes          = $bits->readBits(13);
			$assembledTwoBytes = (($twoBytes / 0x060) << 8) | ($twoBytes % 0x060);

			$assembledTwoBytes += ($assembledTwoBytes < 0x00A00) // 0x003BF
				? 0x0A1A1  // In the 0xA1A1 to 0xAAFE range
				: 0x0A6A1; // In the 0xB0A1 to 0xFAFE range

			$buffer[$offset]     = \chr(0xff & ($assembledTwoBytes >> 8));
			$buffer[$offset + 1] = \chr(0xff & $assembledTwoBytes);
			$offset              += 2;
			$count--;
		}
		$result .= \mb_convert_encoding(\implode($buffer), 'UTF-8', 'GB2312');
	}

	/**
	 * @throws \Zxing\Decoder\FormatException
	 */
	private function decodeNumericSegment(BitSource $bits, string &$result, int $count):void{
		// Read three digits at a time
		while($count >= 3){
			// Each 10 bits encodes three digits
			if($bits->available() < 10){
				throw new FormatException();
			}
			$threeDigitsBits = $bits->readBits(10);
			if($threeDigitsBits >= 1000){
				throw new FormatException();
			}
			$result .= $this->toAlphaNumericChar($threeDigitsBits / 100);
			$result .= $this->toAlphaNumericChar(($threeDigitsBits / 10) % 10);
			$result .= $this->toAlphaNumericChar($threeDigitsBits % 10);
			$count  -= 3;
		}
		if($count === 2){
			// Two digits left over to read, encoded in 7 bits
			if($bits->available() < 7){
				throw new FormatException();
			}
			$twoDigitsBits = $bits->readBits(7);
			if($twoDigitsBits >= 100){
				throw new FormatException();
			}
			$result .= $this->toAlphaNumericChar($twoDigitsBits / 10);
			$result .= $this->toAlphaNumericChar($twoDigitsBits % 10);
		}
		elseif($count === 1){
			// One digit left over to read
			if($bits->available() < 4){
				throw new FormatException();
			}
			$digitBits = $bits->readBits(4);
			if($digitBits >= 10){
				throw new FormatException();
			}
			$result .= $this->toAlphaNumericChar($digitBits);
		}
	}

	/**
	 * @throws \Zxing\Decoder\FormatException
	 */
	private function toAlphaNumericChar(int $value):string{

		if($value >= count(self::ALPHANUMERIC_CHARS)){
			throw new FormatException();
		}

		return self::ALPHANUMERIC_CHARS[$value];
	}

	/**
	 * @throws \Zxing\Decoder\FormatException
	 */
	private function decodeAlphanumericSegment(BitSource $bits, string &$result, int $count, bool $fc1InEffect):void{
		// Read two characters at a time
		$start = \strlen($result);
		while($count > 1){
			if($bits->available() < 11){
				throw new FormatException();
			}
			$nextTwoCharsBits = $bits->readBits(11);
			$result           .= $this->toAlphaNumericChar($nextTwoCharsBits / 45);
			$result           .= $this->toAlphaNumericChar($nextTwoCharsBits % 45);
			$count            -= 2;
		}
		if($count == 1){
			// special case: one character left
			if($bits->available() < 6){
				throw new FormatException();
			}
			$result .= $this->toAlphaNumericChar($bits->readBits(6));
		}
		// See section 6.4.8.1, 6.4.8.2
		if($fc1InEffect){
			// We need to massage the result a bit if in an FNC1 mode:
			for($i = $start; $i < \strlen($result); $i++){
				if($result[$i] === '%'){
					if($i < strlen($result) - 1 && $result[$i + 1] === '%'){
						// %% is rendered as %
						$result = \substr_replace($result, '', $i + 1, 1);//deleteCharAt(i + 1);
					}
#					else{
					// In alpha mode, % should be converted to FNC1 separator 0x1D @todo
#						$result .= setCharAt($i, \chr(0x1D)); // ???
#					}
				}
			}
		}
	}

	/**
	 * @todo: why is this so slow??? and why is it slower with GD than Imagick???
	 * @throws \Zxing\Decoder\FormatException
	 */
	private function decodeByteSegment(
		BitSource $bits,
		string &$result,
		int $count,
		array &$byteSegments,
		CharacterSetECI $currentCharacterSetECI = null
	):void{
		// Don't crash trying to read more bits than we have available.
		if(8 * $count > $bits->available()){
			throw new FormatException();
		}

		$readBytes = [];
		for($i = 0; $i < $count; $i++){
			$readBytes[$i] = $bits->readBits(8);//(byte)
		}

		$text = \implode(\array_map('chr', $readBytes));
#		$encoding = '';
		if($currentCharacterSetECI === null){
			// The spec isn't clear on this mode; see
			// section 6.4.5: t does not say which encoding to assuming
			// upon decoding. I have seen ISO-8859-1 used as well as
			// Shift_JIS -- without anything like an ECI designator to
			// give a hint.

#			$encoding = mb_detect_encoding($text, ['ISO-8859-1', 'SJIS', 'UTF-8']);
		}
		else{
#			$encoding = $currentCharacterSetECI->name();
		}
#		$result.= mb_convert_encoding($text ,$encoding);//(new String(readBytes, encoding));
		$result .= $text;//(new String(readBytes, encoding));

		$byteSegments = \array_merge($byteSegments, $readBytes);
	}

	/**
	 * @throws \Zxing\Decoder\FormatException
	 */
	private function decodeKanjiSegment(BitSource $bits, string &$result, int $count):void{
		// Don't crash trying to read more bits than we have available.
		if($count * 13 > $bits->available()){
			throw new FormatException();
		}

		// Each character will require 2 bytes. Read the characters as 2-byte pairs
		// and decode as Shift_JIS afterwards
		$buffer = [0, 2 * $count, 0];
		$offset = 0;
		while($count > 0){
			// Each 13 bits encodes a 2-byte character
			$twoBytes          = $bits->readBits(13);
			$assembledTwoBytes = (($twoBytes / 0x0C0) << 8) | ($twoBytes % 0x0C0);

			$assembledTwoBytes += ($assembledTwoBytes < 0x01F00)
				? 0x08140  // In the 0x8140 to 0x9FFC range
				: 0x0C140; // In the 0xE040 to 0xEBBF range

			$buffer[$offset]     = \chr(0xff & ($assembledTwoBytes >> 8));
			$buffer[$offset + 1] = \chr(0xff & $assembledTwoBytes);
			$offset              += 2;
			$count--;
		}

		// Shift_JIS may not be supported in some environments:
		$result .= \mb_convert_encoding(\implode($buffer), 'UTF-8', 'SJIS');
	}

}
