<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace Zxing;

use Imagick, InvalidArgumentException;
use Zxing\Decoder\{Decoder, GDLuminanceSource, IMagickLuminanceSource, Result};
use function extension_loaded, file_exists, file_get_contents, imagecreatefromstring, is_file, is_readable;

final class QrReader{

	private bool $useImagickIfAvailable;

	public function __construct(bool $useImagickIfAvailable = true){
		$this->useImagickIfAvailable = $useImagickIfAvailable && extension_loaded('imagick');
	}

	/**
	 * @param \Imagick|\GdImage|resource $im
	 *
	 * @return \Zxing\Decoder\Result|null
	 * @phan-suppress PhanUndeclaredTypeParameter (GdImage)
	 */
	protected function decode($im):?Result{

		$source = $this->useImagickIfAvailable
			? new IMagickLuminanceSource($im)
			: new GDLuminanceSource($im);

		return (new Decoder)->decode($source);
	}

	/**
	 * @param string $imgFilePath
	 *
	 * @return \Zxing\Decoder\Result|null
	 */
	public function readFile(string $imgFilePath):?Result{

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
	 * @return \Zxing\Decoder\Result|null
	 */
	public function readBlob(string $imgBlob):?Result{

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
	 * @return \Zxing\Decoder\Result|null
	 */
	public function readResource($imgSource):?Result{
		return $this->decode($imgSource);
	}
}
