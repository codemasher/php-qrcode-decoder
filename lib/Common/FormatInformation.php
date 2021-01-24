<?php
/**
 * Class FormatInformation
 *
 * @filesource   FormatInformation.php
 * @created      24.01.2021
 * @package      chillerlan\QRCode\Common
 * @author       ZXing Authors
 * @author       Smiley <smiley@chillerlan.net>
 * @copyright    2021 Smiley
 * @license      Apache-2.0
 */

namespace Zxing\Common;

use chillerlan\QRCode\Common\{EccLevel, MaskPattern};

/**
 * <p>Encapsulates a QR Code's format information, including the data mask used and
 * error correction level.</p>
 *
 * @author Sean Owen
 * @see    \Zxing\Common\ErrorCorrectionLevel
 */
final class FormatInformation{

	public const MASK_QR = 0x5412;

	/**
	 * See ISO 18004:2006, Annex C, Table C.1
	 *
	 * [data bits, sequence after masking]
	 */
	public const DECODE_LOOKUP = [
		[0x00, 0x5412],
		[0x01, 0x5125],
		[0x02, 0x5E7C],
		[0x03, 0x5B4B],
		[0x04, 0x45F9],
		[0x05, 0x40CE],
		[0x06, 0x4F97],
		[0x07, 0x4AA0],
		[0x08, 0x77C4],
		[0x09, 0x72F3],
		[0x0A, 0x7DAA],
		[0x0B, 0x789D],
		[0x0C, 0x662F],
		[0x0D, 0x6318],
		[0x0E, 0x6C41],
		[0x0F, 0x6976],
		[0x10, 0x1689],
		[0x11, 0x13BE],
		[0x12, 0x1CE7],
		[0x13, 0x19D0],
		[0x14, 0x0762],
		[0x15, 0x0255],
		[0x16, 0x0D0C],
		[0x17, 0x083B],
		[0x18, 0x355F],
		[0x19, 0x3068],
		[0x1A, 0x3F31],
		[0x1B, 0x3A06],
		[0x1C, 0x24B4],
		[0x1D, 0x2183],
		[0x1E, 0x2EDA],
		[0x1F, 0x2BED],
	];

	private int $errorCorrectionLevel;
	private int $dataMask;

	public function __construct(int $formatInfo){
		$this->errorCorrectionLevel = ($formatInfo >> 3) & 0x03; // Bits 3,4
		$this->dataMask             = ($formatInfo & 0x07); // Bottom 3 bits
	}

	public function getErrorCorrectionLevel():EccLevel{
		return new EccLevel($this->errorCorrectionLevel);
	}

	public function getDataMask():MaskPattern{
		return new MaskPattern($this->dataMask);
	}

}

