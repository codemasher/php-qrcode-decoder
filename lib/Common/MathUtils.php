<?php
/*
* Copyright 2012 ZXing authors
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
*      http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*/

namespace Zxing\Common;

final class MathUtils{

	public static function squaredDistance(int $aX, int $aY, int $bX, int $bY):float{
		$xDiff = $aX - $bX;
		$yDiff = $aY - $bY;

		return $xDiff * $xDiff + $yDiff * $yDiff;
	}

	public static function distance(int $aX, int $aY, int $bX, int $bY):float{
		return \sqrt(self::squaredDistance($aX, $aY, $bX, $bY));
	}
}
