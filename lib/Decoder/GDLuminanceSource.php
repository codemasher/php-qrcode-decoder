<?php

namespace Zxing\Decoder;

use InvalidArgumentException;

/**
 * This class is used to help decode images from files which arrive as GD Resource
 * It does not support rotation.
 */
final class GDLuminanceSource extends LuminanceSource{

	/** @var resource|\GdImage */
	private $gdImage;

	/**
	 * GDLuminanceSource constructor.
	 *
	 * @param     $gdImage
	 * @param int $width
	 * @param int $height
	 *
	 * @throws \InvalidArgumentException
	 */
	public function __construct($gdImage, int $width, int $height){

		/** @noinspection PhpElementIsNotAvailableInCurrentPhpVersionInspection */
		if(
			(\PHP_MAJOR_VERSION >= 8 && !$gdImage instanceof \GdImage)
			|| (\PHP_MAJOR_VERSION < 8 && !\is_resource($gdImage))
		){
			throw new InvalidArgumentException('Invalid image source.');
		}

		parent::__construct($width, $height);

		$this->gdImage = $gdImage;

		for($j = 0; $j < $height; $j++){
			for($i = 0; $i < $width; $i++){
				$argb  = \imagecolorat($this->gdImage, $i, $j);
				$pixel = \imagecolorsforindex($this->gdImage, $argb);

				$this->setLuminancePixel($pixel['red'], $pixel['green'], $pixel['blue']);
			}
		}

	}

}
