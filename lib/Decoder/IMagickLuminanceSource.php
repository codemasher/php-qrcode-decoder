<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace Zxing\Decoder;

use Imagick;
use InvalidArgumentException;

/**
 * This class is used to help decode images from files which arrive as Imagick Resource
 * It does not support rotation.
 */
final class IMagickLuminanceSource extends LuminanceSource{

	private Imagick $image;

	/**
	 * IMagickLuminanceSource constructor.
	 *
	 * @param \Imagick $image
	 * @param int      $width
	 * @param int      $height
	 *
	 * @throws \InvalidArgumentException
	 */
	public function __construct(Imagick $image, int $width, int $height){

		if(!$image instanceof Imagick){
			throw new InvalidArgumentException('Invalid image source.');
		}

		parent::__construct($width, $height);

		$this->image = $image;

		$image->setImageColorspace(Imagick::COLORSPACE_GRAY);
		// $image->newPseudoImage(0, 0, "magick:rose");
		$pixels = $image->exportImagePixels(1, 1, $width, $height, 'RGB', Imagick::PIXEL_CHAR);

		$countPixels = \count($pixels);

		for($i = 0; $i < $countPixels; $i += 3){
			$this->setLuminancePixel($pixels[$i] & 0xff, $pixels[$i + 1] & 0xff, $pixels[$i + 2] & 0xff);
		}
	}

}
