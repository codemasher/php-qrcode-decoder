<?php /** @noinspection PhpComposerExtensionStubsInspection */

namespace Zxing;

use Imagick, InvalidArgumentException;
use Zxing\Decoder\{Decoder, GDLuminanceSource, IMagickLuminanceSource, LuminanceSource, Result};

final class QrReader{

	public const SOURCE_TYPE_FILE     = 'file';
	public const SOURCE_TYPE_BLOB     = 'blob';
	public const SOURCE_TYPE_RESOURCE = 'resource';

	private LuminanceSource $source;

	public function __construct($imgSource, string $sourceType = self::SOURCE_TYPE_FILE, bool $useImagickIfAvailable = true){
		$useImagickIfAvailable = $useImagickIfAvailable && \extension_loaded('imagick');

		if($sourceType === self::SOURCE_TYPE_FILE){

			if($useImagickIfAvailable){
				$im = new Imagick;
				$im->readImage($imgSource);
			}
			else{
				$im = \imagecreatefromstring(\file_get_contents($imgSource));
			}

		}
		elseif($sourceType === self::SOURCE_TYPE_BLOB){

			if($useImagickIfAvailable){
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

		$this->source = $useImagickIfAvailable
			? new IMagickLuminanceSource($im, $im->getImageWidth(), $im->getImageHeight())
			: new GDLuminanceSource($im, \imagesx($im), \imagesy($im));
	}

	public function decode():?Result{
		return (new Decoder)->decode($this->source);
	}

}
