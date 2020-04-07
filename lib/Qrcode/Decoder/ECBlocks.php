<?php

namespace Zxing\Qrcode\Decoder;

/**
 * <p>Encapsulates a set of error-correction blocks in one symbol version. Most versions will
 * use blocks of differing sizes within one version, so, this encapsulates the parameters for
 * each set of blocks. It also holds the number of error-correction codewords per block since it
 * will be the same across all blocks within one version.</p>
 */
final class ECBlocks{

	private $ecCodewordsPerBlock;
	/** @var \Zxing\Qrcode\Decoder\ECB[] */
	private array $ecBlocks;

	function __construct($ecCodewordsPerBlock, $ecBlocks){
		$this->ecCodewordsPerBlock = $ecCodewordsPerBlock;
		$this->ecBlocks            = $ecBlocks;
	}

	public function getECCodewordsPerBlock(){
		return $this->ecCodewordsPerBlock;
	}

	public function getNumBlocks(){
		$total = 0;
		foreach($this->ecBlocks as $ecBlock){
			$total += $ecBlock->getCount();
		}

		return $total;
	}

	public function getTotalECCodewords(){
		return $this->ecCodewordsPerBlock * $this->getNumBlocks();
	}

	public function getECBlocks(){
		return $this->ecBlocks;
	}
}




