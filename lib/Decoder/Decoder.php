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

use Exception, InvalidArgumentException;
use chillerlan\QRCode\Common\{EccLevel, Version};
use Zxing\Common\ReedSolomonDecoder;
use Zxing\Detector\Detector;
use function count, array_fill;

/**
 * <p>The main class which implements QR Code decoding -- as opposed to locating and extracting
 * the QR Code from an image.</p>
 *
 * @author Sean Owen
 */
final class Decoder{

	/**
	 * @param \Zxing\Decoder\LuminanceSource $source
	 *
	 * @return \Zxing\Decoder\Result|null
	 */
	public function decode(LuminanceSource $source):?Result{
		$matrix          = (new Binarizer($source))->getBlackMatrix();
		$bits            = (new Detector($matrix))->detect();
		$decoderResult   = $this->decodeBits($bits);
		$result          = new Result($decoderResult->getText(), $decoderResult->getRawBytes());
		$byteSegments    = $decoderResult->getByteSegments();

		if($byteSegments !== null){
			$result->putMetadata('BYTE_SEGMENTS', $byteSegments);
		}

		$ecLevel = $decoderResult->getECLevel();

		if($ecLevel !== null){
			$result->putMetadata('ERROR_CORRECTION_LEVEL', $ecLevel);
		}

		if($decoderResult->hasStructuredAppend()){
			$result->putMetadata('STRUCTURED_APPEND_SEQUENCE', $decoderResult->getStructuredAppendSequenceNumber());
			$result->putMetadata('STRUCTURED_APPEND_PARITY', $decoderResult->getStructuredAppendParity());
		}

		return $result;
	}

	/**
	 * <p>Decodes a QR Code represented as a {@link \Zxing\Decoder\BitMatrix}. A 1 or "true" is taken to mean a black module.</p>
	 *
	 * @param \Zxing\Decoder\BitMatrix $bits booleans representing white/black QR Code modules
	 *
	 * @return \Zxing\Decoder\DecoderResult text and bytes encoded within the QR Code
	 * @throws \Exception if the QR Code cannot be decoded
	 */
	public function decodeBits(BitMatrix $bits):DecoderResult{
		$fe = null;

		try{
			// Construct a parser and read version, error-correction level
			// clone the BitMatrix to avoid errors in case we run into mirroring
			return $this->decodeParser(new BitMatrixParser(clone $bits));
		}
		catch(Exception $e){
			$fe = $e;
		}

		try{
			$parser = new BitMatrixParser(clone $bits);

			// Will be attempting a mirrored reading of the version and format info.
			$parser->setMirror(true);

			// Preemptively read the version.
#			$parser->readVersion();

			// Preemptively read the format information.
#			$parser->readFormatInformation();

			/*
			 * Since we're here, this means we have successfully detected some kind
			 * of version and format information when mirrored. This is a good sign,
			 * that the QR code may be mirrored, and we should try once more with a
			 * mirrored content.
			 */
			// Prepare for a mirrored reading.
			$parser->mirror();

			return $this->decodeParser($parser);
		}
		catch(Exception $e){
			// Throw the exception from the original reading
			if($fe instanceof Exception){
				throw $fe;
			}

			throw $e;
		}

	}

	/**
	 * @param \Zxing\Decoder\BitMatrixParser $parser
	 *
	 * @return \Zxing\Decoder\DecoderResult
	 */
	private function decodeParser(BitMatrixParser $parser):DecoderResult{
		$version  = $parser->readVersion();
		$eccLevel = new EccLevel($parser->readFormatInformation()->getErrorCorrectionLevel());

		// Read codewords
		$codewords  = $parser->readCodewords();
		// Separate into data blocks
		$dataBlocks = $this->getDataBlocks($codewords, $version, $eccLevel);

		// Count total number of data bytes
		$totalBytes = 0;

		foreach($dataBlocks as $dataBlock){
			$totalBytes += $dataBlock[0];
		}

		$resultBytes  = array_fill(0, $totalBytes, 0);
		$resultOffset = 0;

		// Error-correct and copy data blocks together into a stream of bytes
		foreach($dataBlocks as $dataBlock){
			[$numDataCodewords, $codewordBytes] = $dataBlock;

			$corrected = $this->correctErrors($codewordBytes, $numDataCodewords);

			for($i = 0; $i < $numDataCodewords; $i++){
				$resultBytes[$resultOffset++] = $corrected[$i];
			}
		}

		// Decode the contents of that stream of bytes
		return (new DecodedBitStreamParser)->decode($resultBytes, $version, $eccLevel);
	}

	/**
	 * <p>When QR Codes use multiple data blocks, they are actually interleaved.
	 * That is, the first byte of data block 1 to n is written, then the second bytes, and so on. This
	 * method will separate the data into original blocks.</p>
	 *
	 * @param array                              $rawCodewords bytes as read directly from the QR Code
	 * @param \chillerlan\QRCode\Common\Version  $version      version of the QR Code
	 * @param \chillerlan\QRCode\Common\EccLevel $eccLevel     error-correction level of the QR Code
	 *
	 * @return array DataBlocks containing original bytes, "de-interleaved" from representation in the QR Code
	 * @throws \InvalidArgumentException
	 */
	private function getDataBlocks(array $rawCodewords, Version $version, EccLevel $eccLevel):array{

		if(count($rawCodewords) !== $version->getTotalCodewords()){
			throw new InvalidArgumentException('$rawCodewords differ from total codewords for version');
		}

		// Figure out the number and size of data blocks used by this version and
		// error correction level
		[$numEccCodewords, $eccBlocks] = $version->getRSBlocks($eccLevel);

		// Now establish DataBlocks of the appropriate size and number of data codewords
		$result          = [];//new DataBlock[$totalBlocks];
		$numResultBlocks = 0;

		foreach($eccBlocks as $blockData){
			[$numEccBlocks, $eccPerBlock] = $blockData;

			for($i = 0; $i < $numEccBlocks; $i++, $numResultBlocks++){
				$result[$numResultBlocks] = [$eccPerBlock, array_fill(0, $numEccCodewords + $eccPerBlock, 0)];
			}
		}

		// All blocks have the same amount of data, except that the last n
		// (where n may be 0) have 1 more byte. Figure out where these start.
		$shorterBlocksTotalCodewords = count($result[0][1]);
		$longerBlocksStartAt         = count($result) - 1;

		while($longerBlocksStartAt >= 0){
			$numCodewords = count($result[$longerBlocksStartAt][1]);

			if($numCodewords == $shorterBlocksTotalCodewords){
				break;
			}

			$longerBlocksStartAt--;
		}

		$longerBlocksStartAt++;

		$shorterBlocksNumDataCodewords = $shorterBlocksTotalCodewords - $numEccCodewords;
		// The last elements of result may be 1 element longer;
		// first fill out as many elements as all of them have
		$rawCodewordsOffset = 0;

		for($i = 0; $i < $shorterBlocksNumDataCodewords; $i++){
			for($j = 0; $j < $numResultBlocks; $j++){
				$result[$j][1][$i] = $rawCodewords[$rawCodewordsOffset++];
			}
		}

		// Fill out the last data block in the longer ones
		for($j = $longerBlocksStartAt; $j < $numResultBlocks; $j++){
			$result[$j][1][$shorterBlocksNumDataCodewords] = $rawCodewords[$rawCodewordsOffset++];
		}

		// Now add in error correction blocks
		$max = count($result[0][1]);

		for($i = $shorterBlocksNumDataCodewords; $i < $max; $i++){
			for($j = 0; $j < $numResultBlocks; $j++){
				$iOffset                 = $j < $longerBlocksStartAt ? $i : $i + 1;
				$result[$j][1][$iOffset] = $rawCodewords[$rawCodewordsOffset++];
			}
		}

		return $result;
	}

	/**
	 * <p>Given data and error-correction codewords received, possibly corrupted by errors, attempts to
	 * correct the errors in-place using Reed-Solomon error correction.</p>
	 *
	 * @param array $codewordBytes    data and error correction codewords
	 * @param int   $numDataCodewords number of codewords that are data bytes
	 */
	private function correctErrors(array $codewordBytes, int $numDataCodewords):array{
		// First read into an array of ints
		$codewordsInts = [];

		foreach($codewordBytes as $i => $codewordByte){
			$codewordsInts[$i] = $codewordByte & 0xFF;
		}

		$decoded = (new ReedSolomonDecoder)->decode($codewordsInts, (count($codewordBytes) - $numDataCodewords));

		// Copy back into array of bytes -- only need to worry about the bytes that were data
		// We don't care about errors in the error-correction codewords
		for($i = 0; $i < $numDataCodewords; $i++){
			$codewordBytes[$i] = $decoded[$i];
		}

		return $codewordBytes;
	}

}
