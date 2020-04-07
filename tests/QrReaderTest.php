<?php

namespace Khanamiryan\QrCodeTests;

use Zxing\QrReader;

class QrReaderTest extends \PHPUnit\Framework\TestCase{

	public function QRCodeProvider():array{
		return [
			['hello_world.png', 'Hello world!'],
			['url.png', 'https://smiley.codes/qrcode/'],
		];
	}

	/**
	 * @dataProvider QRCodeProvider
	 */
	public function testText(string $img, string $expected):void{
		$qrcode = new QrReader(__DIR__.'/qrcodes/'.$img);

		$this::assertSame($expected, $qrcode->text());
	}
}
