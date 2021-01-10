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

use function Zxing\Common\arraycopy;

/**
 * <p>Encapsulates the result of decoding a barcode within an image.</p>
 *
 * @author Sean Owen
 */
final class Result{

	private string  $text;
	/** @var int[] */
	private array   $rawBytes;
	/** @var \Zxing\Detector\FinderPattern[] */
	private ?array  $resultPoints;
	private int     $timestamp;
	private ?array  $resultMetadata = null;

	public function __construct(string $text, array $rawBytes, array $resultPoints, int $timestamp = null){
		$this->text         = $text;
		$this->rawBytes     = $rawBytes;
		$this->resultPoints = $resultPoints;
		$this->timestamp    = $timestamp ?? \time();
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

	/**
	 * @return int[] raw bytes encoded by the barcode, if applicable, otherwise {@code null}
	 */
	public function getRawBytes():array{
		return $this->rawBytes;
	}

	/**
	 * @return \Zxing\Detector\FinderPattern[]
	 *         points related to the barcode in the image. These are typically points
	 *         identifying finder patterns or the corners of the barcode. The exact meaning is
	 *         specific to the type of barcode that was decoded.
	 */
	public function getResultPoints():array{
		return $this->resultPoints;
	}

	/**
	 * @return {@link Map} mapping {@link ResultMetadataType} keys to values. May be
	 *   {@code null}. This contains optional metadata about what was detected about the barcode,
	 *   like orientation.
	 */
	public function getResultMetadata():?array{
		return $this->resultMetadata;
	}

	public function putMetadata(string $type, $value):void{

		if($this->resultMetadata === null){
			$this->resultMetadata = [];
		}

		$this->resultMetadata[$type] = $value;
	}

	public function putAllMetadata(array $metadata = null):void{

		if($metadata === null){
			return;
		}

		if($this->resultMetadata === null){
			$this->resultMetadata = $metadata;
		}
		else{
			$this->resultMetadata = \array_merge($this->resultMetadata, $metadata);
		}
	}

	public function addResultPoints(array $newPoints = null):void{
		$oldPoints = $this->resultPoints;

		if($oldPoints === null){
			$this->resultPoints = $newPoints;
		}
		elseif($newPoints !== null && \count($newPoints) > 0){
			$allPoints          = \array_fill(0, \count($oldPoints) + \count($newPoints), 0);
			$allPoints          = arraycopy($oldPoints, 0, $allPoints, 0, \count($oldPoints));
			$allPoints          = arraycopy($newPoints, 0, $allPoints, \count($oldPoints), \count($newPoints));
			$this->resultPoints = $allPoints;
		}

	}

	public function getTimestamp():int{
		return $this->timestamp;
	}

}
