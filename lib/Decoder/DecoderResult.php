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

use chillerlan\QRCode\Common\{EccLevel, Version};

/**
 * <p>Encapsulates the result of decoding a matrix of bits. This typically
 * applies to 2D barcode formats. For now it contains the raw bytes obtained,
 * as well as a String interpretation of those bytes, if applicable.</p>
 *
 * @author Sean Owen
 */
final class DecoderResult{

	private array    $rawBytes;
	private string   $text;
	private Version  $version;
	private EccLevel $eccLevel;
	private int      $structuredAppendParity;
	private int      $structuredAppendSequenceNumber;

	public function __construct(
		array $rawBytes,
		string $text,
		Version $version,
		EccLevel $eccLevel,
		int $saSequence = -1,
		int $saParity = -1
	){
		$this->rawBytes                       = $rawBytes;
		$this->text                           = $text;
		$this->version                        = $version;
		$this->eccLevel                       = $eccLevel;
		$this->structuredAppendParity         = $saParity;
		$this->structuredAppendSequenceNumber = $saSequence;
	}

	/**
	 * @return int[] raw bytes encoded by the barcode, if applicable, otherwise {@code null}
	 */
	public function getRawBytes():array{
		return $this->rawBytes;
	}

	/**
	 * @return string raw text encoded by the barcode
	 */
	public function getText():string{
		return $this->text;
	}

	public function __toString():string{
		return $this->text;
	}

	public function getVersion():Version{
		return $this->version;
	}

	public function getEccLevel():EccLevel{
		return $this->eccLevel;
	}

	public function hasStructuredAppend():bool{
		return $this->structuredAppendParity >= 0 && $this->structuredAppendSequenceNumber >= 0;
	}

	public function getStructuredAppendParity():int{
		return $this->structuredAppendParity;
	}

	public function getStructuredAppendSequenceNumber():int{
		return $this->structuredAppendSequenceNumber;
	}

}
