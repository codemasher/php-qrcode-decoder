<?php

namespace Khanamiryan\QrCodeTests;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Version;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PHPUnit\Framework\TestCase;
use Zxing\QrReader;

class QrReaderTest extends TestCase{

	// https://www.bobrosslipsum.com/
	protected const loremipsum = 'Just let this happen. We just let this flow right out of our minds. '
		.'Anyone can paint. We touch the canvas, the canvas takes what it wants. From all of us here, '
		.'I want to wish you happy painting and God bless, my friends. A tree cannot be straight if it has a crooked trunk. '
		.'You have to make almighty decisions when you\'re the creator. I guess that would be considered a UFO. '
		.'A big cotton ball in the sky. I\'m gonna add just a tiny little amount of Prussian Blue. '
		.'They say everything looks better with odd numbers of things. But sometimes I put even numbers—just '
		.'to upset the critics. We\'ll lay all these little funky little things in there. ';

	public function qrCodeProvider():array{
		return [
			'helloworld' => ['hello_world.png', 'Hello world!'],
			// covers mirroring
			'mirrored'   => ['hello_world_mirrored.png', 'Hello world!'],
			'byte'       => ['byte.png', 'https://smiley.codes/qrcode/'],
			'numeric'    => ['numeric.png', '123456789012345678901234567890'],
			'alphanum'   => ['alphanum.png', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890 $%*+-./:'],
			'kanji'      => ['kanji.png', '茗荷茗荷茗荷茗荷'],
			// covers most of ReedSolomonDecoder
			'damaged'    => ['damaged.png', 'https://smiley.codes/qrcode/'],
			// covers Binarizer::getHistogramBlackMatrix()
			'smol'       => ['smol.png', 'https://smiley.codes/qrcode/'],
		];
	}

	/**
	 * @dataProvider qrCodeProvider
	 */
	public function testReaderGD(string $img, string $expected):void{
		$reader = new QrReader(false);

		self::assertSame($expected, (string)$reader->decode(__DIR__.'/qrcodes/'.$img, QrReader::SOURCE_TYPE_FILE));
	}

	/**
	 * @dataProvider qrCodeProvider
	 */
	public function testReaderImagick(string $img, string $expected):void{

		if(!\extension_loaded('imagick')){
			self::markTestSkipped('imagick not installed');
		}

		$reader = new QrReader(true);

		self::assertSame($expected, (string)$reader->decode(__DIR__.'/qrcodes/'.$img, QrReader::SOURCE_TYPE_FILE));
	}

	public function dataTestProvider():array{
		$data = [];
		$str  = \str_repeat(self::loremipsum, 5);

		foreach(\range(1, 40) as $v){
			$version = new Version($v);

			foreach(EccLevel::MODES as $ecc => $_){
				$eccLevel = new EccLevel($ecc);

				$data['version: '.$version->getVersionNumber().$eccLevel->__toString()] = [
					$version,
					$eccLevel,
					\substr($str, 0, $version->getMaxLengthForMode(2, $eccLevel)) // byte mode
				];
			}
		}

		return $data;
	}

	/**
	 * @dataProvider dataTestProvider
	 */
	public function testReadData(Version $version, EccLevel $ecc, string $expected):void{
		$options = new QROptions;
#		$options->imageTransparent = false;
		$options->eccLevel         = $ecc->getLevel();
		$options->version          = $version->getVersionNumber();
		$options->imageBase64      = false;

		$imagedata = (new QRCode($options))->render($expected);

		try{
			$result = (new QrReader(true))->decode($imagedata, QrReader::SOURCE_TYPE_BLOB);
		}
		catch(\Exception $e){
			self::markTestSkipped($version->getVersionNumber().$ecc->__toString().': '.$e->getMessage());
		}

		self::assertSame($expected, (string)$result);
	}

}
