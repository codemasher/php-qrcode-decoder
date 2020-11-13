<?php

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
