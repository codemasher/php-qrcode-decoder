<?php
namespace Zxing\Common;

if(!function_exists('arraycopy')){
	function arraycopy(array $srcArray, int $srcPos, array $destArray, int $destPos, int $length):array{
		$srcArrayToCopy = array_slice($srcArray, $srcPos, $length);
		array_splice($destArray, $destPos, $length, $srcArrayToCopy);

		return $destArray;
	}
}

if(!function_exists('uRShift')){
	function uRShift(int $a, int $b):int{
		static $mask = (8 * PHP_INT_SIZE - 1);

		if($b === 0){
			return $a;
		}

		return ($a >> $b) & ~(1 << $mask >> ($b - 1));
	}
}

if(!function_exists('numBitsDiffering')){
	function numBitsDiffering(int $a, int $b):int{
		// a now has a 1 bit exactly where its bit differs with b's
		$a ^= $b;
		// Offset i holds the number of 1 bits in the binary representation of i
		$BITS_SET_IN_HALF_BYTE = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
		// Count bits set quickly with a series of lookups:
		$count = 0;

		for($i = 0; $i < 32; $i += 4){
			$count += $BITS_SET_IN_HALF_BYTE[uRShift($a, $i) & 0x0F];
		}

		return $count;
	}
}

if(!function_exists('squaredDistance')){
	function squaredDistance(int $aX, int $aY, int $bX, int $bY):float{
		$xDiff = $aX - $bX;
		$yDiff = $aY - $bY;

		return $xDiff * $xDiff + $yDiff * $yDiff;
	}
}

if(!function_exists('distance')){
	function distance(int $aX, int $aY, int $bX, int $bY):float{
		return \sqrt(squaredDistance($aX, $aY, $bX, $bY));
	}
}
