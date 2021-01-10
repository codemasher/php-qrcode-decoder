<?php

namespace Zxing\Decoder;

use chillerlan\QRCode\Common\Version;
use Closure;
use InvalidArgumentException;

final class BitMatrix{

	private int   $width;
	private int   $height;
	private int   $rowSize;
	private array $bits;

	public function __construct(int $width, int $height = null, int $rowSize = null, array $bits = null){
		$this->width   = $width;
		$this->height  = $height ?? $width;
		$this->rowSize = $rowSize ?? ((int)(($this->width + 31) / 32));
		$this->bits    = $bits ?? \array_fill(0, $this->rowSize * $this->height, 0);
	}

	/**
	 * <p>Sets the given bit to true.</p>
	 *
	 * @param int $x ;  The horizontal component (i.e. which column)
	 * @param int $y ;  The vertical component (i.e. which row)
	 */
	public function set(int $x, int $y):void{
		$offset = (int)($y * $this->rowSize + ($x / 32));

		$this->bits[$offset] ??= 0;

		$bob                 = $this->bits[$offset];
		$bob                 |= 1 << ($x & 0x1f);
		$this->bits[$offset] |= ($bob);
	}

	/**1 << (249 & 0x1f)
	 * <p>Flips the given bit.</p>
	 *
	 * @param int $x ;  The horizontal component (i.e. which column)
	 * @param int $y ;  The vertical component (i.e. which row)
	 */
	public function flip(int $x, int $y):void{
		$offset = $y * $this->rowSize + (int)($x / 32);

		$this->bits[$offset] = ($this->bits[$offset] ^ (1 << ($x & 0x1f)));
	}

	/**
	 * <p>Sets a square region of the bit matrix to true.</p>
	 *
	 * @param int $left   ;  The horizontal position to begin at (inclusive)
	 * @param int $top    ;  The vertical position to begin at (inclusive)
	 * @param int $width  ;  The width of the region
	 * @param int $height ;  The height of the region
	 *
	 * @throws \InvalidArgumentException
	 */
	public function setRegion(int $left, int $top, int $width, int $height):void{

		if($top < 0 || $left < 0){
			throw new InvalidArgumentException('Left and top must be nonnegative');
		}

		if($height < 1 || $width < 1){
			throw new InvalidArgumentException('Height and width must be at least 1');
		}

		$right  = $left + $width;
		$bottom = $top + $height;

		if($bottom > $this->height || $right > $this->width){ //> this.height || right > this.width
			throw new InvalidArgumentException('The region must fit inside the matrix');
		}

		for($y = $top; $y < $bottom; $y++){
			$offset = $y * $this->rowSize;

			for($x = $left; $x < $right; $x++){
				$this->bits[$offset + (int)($x / 32)] = ($this->bits[$offset + (int)($x / 32)] |= 1 << ($x & 0x1f));
			}
		}
	}

	/**
	 * @return int The width of the matrix
	 */
	public function getWidth():int{
		return $this->width;
	}

	/**
	 * @return int The height of the matrix
	 */
	public function getHeight():int{
		return $this->height;
	}

	/**
	 * <p>Gets the requested bit, where true means black.</p>
	 *
	 * @param int $x The horizontal component (i.e. which column)
	 * @param int $y The vertical component (i.e. which row)
	 *
	 * @return bool value of given bit in matrix
	 */
	public function get(int $x, int $y):bool{
		$offset = (int)($y * $this->rowSize + ($x / 32));

		if(!isset($this->bits[$offset])){
			$this->bits[$offset] = 0;
		}

		return (uRShift($this->bits[$offset], ($x & 0x1f)) & 1) !== 0;
	}

	/**
	 * See ISO 18004:2006 Annex E
	 */
	public function buildFunctionPattern(Version $version):BitMatrix{
		$dimension = $version->getDimension();
		// @todo
		$bitMatrix = new self($dimension);

		// Top left finder pattern + separator + format
		$bitMatrix->setRegion(0, 0, 9, 9);
		// Top right finder pattern + separator + format
		$bitMatrix->setRegion($dimension - 8, 0, 8, 9);
		// Bottom left finder pattern + separator + format
		$bitMatrix->setRegion(0, $dimension - 8, 9, 8);

		// Alignment patterns
		$apc = $version->getAlignmentPattern();
		$max = \count($apc);

		for($x = 0; $x < $max; $x++){
			$i = $apc[$x] - 2;

			for($y = 0; $y < $max; $y++){
				if(($x === 0 && ($y === 0 || $y === $max - 1)) || ($x === $max - 1 && $y === 0)){
					// No alignment patterns near the three finder paterns
					continue;
				}

				$bitMatrix->setRegion($apc[$y] - 2, $i, 5, 5);
			}
		}

		// Vertical timing pattern
		$bitMatrix->setRegion(6, 9, 1, $dimension - 17);
		// Horizontal timing pattern
		$bitMatrix->setRegion(9, 6, $dimension - 17, 1);

		if($version->getVersionNumber() > 6){
			// Version info, top right
			$bitMatrix->setRegion($dimension - 11, 0, 3, 6);
			// Version info, bottom left
			$bitMatrix->setRegion(0, $dimension - 11, 6, 3);
		}

		return $bitMatrix;
	}

	/**
	 * Mirror the bit matrix in order to attempt a second reading.
	 */
	public function mirror():void{

		for($x = 0; $x < $this->getWidth(); $x++){
			for($y = $x + 1; $y < $this->getHeight(); $y++){
				if($this->get($x, $y) != $this->get($y, $x)){
					$this->flip($y, $x);
					$this->flip($x, $y);
				}
			}
		}

	}

	/**
	 * <p>Encapsulates data masks for the data bits in a QR code, per ISO 18004:2006 6.8. Implementations
	 * of this class can un-mask a raw BitMatrix. For simplicity, they will unmask the entire BitMatrix,
	 * including areas used for finder patterns, timing patterns, etc. These areas should be unused
	 * after the point they are unmasked anyway.</p>
	 *
	 * <p>Note that the diagram in section 6.8.1 is misleading since it indicates that i is column position
	 * and j is row position. In fact, as the text says, i is row position and j is column position.</p>
	 *
	 * <p>Implementations of this method reverse the data masking process applied to a QR Code and
	 * make its bits ready to read.</p>
	 *
	 * @param int $dimension
	 * @param int $maskPattern
	 *
	 */
	public function unmask(int $dimension, int $maskPattern):void{
		$mask = $this->getMask($maskPattern);

		for($i = 0; $i < $dimension; $i++){
			for($j = 0; $j < $dimension; $j++){
				if($mask($i, $j) === 0){
					$this->flip($j, $i);
				}
			}
		}

	}

	/**
	 * @param int $maskPattern a value between 0 and 7 indicating one of the eight possible
	 *                         data mask patterns a QR Code may use
	 *
	 * @return \Closure
	 */
	private function getMask(int $maskPattern):Closure{

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
