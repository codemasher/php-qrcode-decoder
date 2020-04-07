<?php

namespace Zxing\Qrcode\Decoder;

/**
 * <p>Encapsualtes the parameters for one error-correction block in one symbol version.
 * This includes the number of data codewords, and the number of times a block with these
 * parameters is used consecutively in the QR code version's format.</p>
 */
final class ECB{

	private int $count;
	private int $dataCodewords;

	function __construct(int $count, int $dataCodewords){
		$this->count         = $count;
		$this->dataCodewords = $dataCodewords;
	}

	public function getCount(){
		return $this->count;
	}

	public function getDataCodewords(){
		return $this->dataCodewords;
	}


	public function toString(){
		throw new \Exception('Version ECB toString()');
		//  return parent::$versionNumber;
	}

}
