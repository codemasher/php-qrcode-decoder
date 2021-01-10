<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace Zxing;

use Imagick, InvalidArgumentException;
use Zxing\Decoder\{Decoder, GDLuminanceSource, IMagickLuminanceSource, LuminanceSource, Result};

final class QrReader{

	public const SOURCE_TYPE_FILE     = 'file';
	public const SOURCE_TYPE_BLOB     = 'blob';
	public const SOURCE_TYPE_RESOURCE = 'resource';
	private bool $useImagickIfAvailable;

	public function __construct(bool $useImagickIfAvailable = true){
		$this->useImagickIfAvailable = $useImagickIfAvailable && \extension_loaded('imagick');
	}

	/**
	 * @param \Imagick|\GdImage|resource|string $imgSource
	 * @param string                            $sourceType
	 *
	 * @return \Zxing\Decoder\Result|null
	 */
	public function decode($imgSource, string $sourceType = self::SOURCE_TYPE_FILE):?Result{

		if($sourceType === self::SOURCE_TYPE_FILE){

			if($this->useImagickIfAvailable){
				$im = new Imagick;
				$im->readImage($imgSource);
			}
			else{
				$im = \imagecreatefromstring(\file_get_contents($imgSource));
			}

		}
		elseif($sourceType === self::SOURCE_TYPE_BLOB){

			if($this->useImagickIfAvailable){
				$im = new Imagick;
				$im->readImageBlob($imgSource);
			}
			else{
				$im = \imagecreatefromstring($imgSource);
			}

		}
		elseif($sourceType === self::SOURCE_TYPE_RESOURCE){
			$im = $imgSource;
		}
		else{
			throw new InvalidArgumentException('Invalid image source.');
		}

		$source = $this->useImagickIfAvailable
			? new IMagickLuminanceSource($im, $im->getImageWidth(), $im->getImageHeight())
			: new GDLuminanceSource($im, \imagesx($im), \imagesy($im));

		return (new Decoder)->decode($source);
	}

}
