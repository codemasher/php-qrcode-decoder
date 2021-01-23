<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace Zxing;

use Imagick, InvalidArgumentException;
use Zxing\Decoder\{Decoder, DecoderResult, GDLuminanceSource, IMagickLuminanceSource};
use function extension_loaded, file_exists, file_get_contents, imagecreatefromstring, is_file, is_readable;

final class QrReader{

	private bool $useImagickIfAvailable;

	public function __construct(bool $useImagickIfAvailable = true){
		$this->useImagickIfAvailable = $useImagickIfAvailable && extension_loaded('imagick');
	}

	/**
	 * @param \Imagick|\GdImage|resource $im
	 *
	 * @return \Zxing\Decoder\DecoderResult
	 * @phan-suppress PhanUndeclaredTypeParameter (GdImage)
	 */
	protected function decode($im):DecoderResult{

		$source = $this->useImagickIfAvailable
			? new IMagickLuminanceSource($im)
			: new GDLuminanceSource($im);

		return (new Decoder)->decode($source);
	}

	/**
	 * @param string $imgFilePath
	 *
	 * @return \Zxing\Decoder\DecoderResult
	 */
	public function readFile(string $imgFilePath):DecoderResult{

		if(!file_exists($imgFilePath) || !is_file($imgFilePath) || !is_readable($imgFilePath)){
			throw new InvalidArgumentException('invalid file: '.$imgFilePath);
		}

		$im = $this->useImagickIfAvailable
			? new Imagick($imgFilePath)
			: imagecreatefromstring(file_get_contents($imgFilePath));

		return $this->decode($im);
	}

	/**
	 * @param string $imgBlob
	 *
	 * @return \Zxing\Decoder\DecoderResult
	 */
	public function readBlob(string $imgBlob):DecoderResult{

		if($this->useImagickIfAvailable){
			$im = new Imagick;
			$im->readImageBlob($imgBlob);
		}
		else{
			$im = imagecreatefromstring($imgBlob);
		}

		return $this->decode($im);
	}

	/**
	 * @param \Imagick|\GdImage|resource $imgSource
	 *
	 * @return \Zxing\Decoder\DecoderResult
	 */
	public function readResource($imgSource):DecoderResult{
		return $this->decode($imgSource);
	}

}
