<?php
/*
* Copyright 2007 ZXing authors
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

namespace Zxing\Detector;

use Exception;
use Zxing\Common\NotFoundException;
use Zxing\Decoder\BitMatrix;

/**
 * Implementations of this class can, given locations of finder patterns for a QR code in an
 * image, sample the right points in the image to reconstruct the QR code, accounting for
 * perspective distortion. It is abstracted since it is relatively expensive and should be allowed
 * to take advantage of platform-specific optimized implementations, like Sun's Java Advanced
 * Imaging library, but which may not be available in other environments such as J2ME, and vice
 * versa.
 *
 * The implementation used can be controlled by calling {@link #setGridSampler(GridSampler)}
 * with an instance of a class which implements this interface.
 *
 * @author Sean Owen
 */
class GridSampler{

	/**
	 * <p>Checks a set of points that have been transformed to sample points on an image against
	 * the image's dimensions to see if the point are even within the image.</p>
	 *
	 * <p>This method will actually "nudge" the endpoints back onto the image if they are found to be
	 * barely (less than 1 pixel) off the image. This accounts for imperfect detection of finder
	 * patterns in an image where the QR Code runs all the way to the image border.</p>
	 *
	 * <p>For efficiency, the method will check points from either end of the line until one is found
	 * to be within the image. Because the set of points are assumed to be linear, this is valid.</p>
	 *
	 * @param \Zxing\Decoder\BitMatrix $image  image into which the points should map
	 * @param array                    $points actual points in x1,y1,...,xn,yn form
	 *
	 * @throws \Zxing\Common\NotFoundException if an endpoint is lies outside the image boundaries
	 */
	protected function checkAndNudgePoints(BitMatrix $image, array $points):void{
		$width  = $image->getWidth();
		$height = $image->getHeight();
		// Check and nudge points from start until we see some that are OK:
		$nudged = true;

#		$max = \count($points);

		for($offset = 0; $offset < \count($points) && $nudged; $offset += 2){
			$x = (int)$points[$offset];
			$y = (int)$points[$offset + 1];

			if($x < -1 || $x > $width || $y < -1 || $y > $height){
				throw new NotFoundException('checkAndNudgePoints 1 '.\print_r([$x, $y, $width, $height], true));
			}

			$nudged = false;

			if($x === -1){
				$points[$offset] = 0.0;
				$nudged          = true;
			}
			elseif($x === $width){
				$points[$offset] = $width - 1;
				$nudged          = true;
			}
			if($y === -1){
				$points[$offset + 1] = 0.0;
				$nudged              = true;
			}
			elseif($y === $height){
				$points[$offset + 1] = $height - 1;
				$nudged              = true;
			}
		}
		// Check and nudge points from end:
		$nudged = true;

		for($offset = \count($points) - 2; $offset >= 0 && $nudged; $offset -= 2){
			$x = (int)$points[$offset];
			$y = (int)$points[$offset + 1];

			if($x < -1 || $x > $width || $y < -1 || $y > $height){
				throw new NotFoundException('checkAndNudgePoints 2 '.\print_r([$x, $y, $width, $height], true));
			}

			$nudged = false;

			if($x === -1){
				$points[$offset] = 0.0;
				$nudged          = true;
			}
			elseif($x === $width){
				$points[$offset] = $width - 1;
				$nudged          = true;
			}
			if($y === -1){
				$points[$offset + 1] = 0.0;
				$nudged              = true;
			}
			elseif($y === $height){
				$points[$offset + 1] = $height - 1;
				$nudged              = true;
			}
		}
	}

	/**
	 * Samples an image for a rectangular matrix of bits of the given dimension. The sampling
	 * transformation is determined by the coordinates of 4 points, in the original and transformed
	 * image space.
	 *
	 * @return \Zxing\Decoder\BitMatrix representing a grid of points sampled from the image within a region
	 *   defined by the "from" parameters
	 * @throws NotFoundException if image can't be sampled, for example, if the transformation defined
	 *   by the given points is invalid or results in sampling outside the image boundaries
	 */
	public function sampleGrid(BitMatrix $image, int $dimensionX, int $dimensionY, PerspectiveTransform $transform):BitMatrix{

		if($dimensionX <= 0 || $dimensionY <= 0){
			throw new NotFoundException();
		}

		$bits   = new BitMatrix($dimensionX, $dimensionY);
		$points = \array_fill(0, 2 * $dimensionX, 0.0);

		for($y = 0; $y < $dimensionY; $y++){
			$max    = \count($points);
			$iValue = (float)$y + 0.5;

			for($x = 0; $x < $max; $x += 2){
				$points[$x]     = (float)($x / 2) + 0.5;
				$points[$x + 1] = $iValue;
			}

			$transform->transformPoints($points);
			// Quick check to see if points transformed to something inside the image;
			// sufficient to check the endpoints
			$this->checkAndNudgePoints($image, $points);

			try{
				for($x = 0; $x < $max; $x += 2){
					if($image->get((int)$points[$x], (int)$points[$x + 1])){
						// Black(-ish) pixel
						$bits->set($x / 2, $y);
					}
				}
			}
			catch(Exception $aioobe){//ArrayIndexOutOfBoundsException
				// This feels wrong, but, sometimes if the finder patterns are misidentified, the resulting
				// transform gets "twisted" such that it maps a straight line of points to a set of points
				// whose endpoints are in bounds, but others are not. There is probably some mathematical
				// way to detect this about the transformation that I don't know yet.
				// This results in an ugly runtime exception despite our clever checks above -- can't have
				// that. We could check each point's coordinates but that feels duplicative. We settle for
				// catching and wrapping ArrayIndexOutOfBoundsException.
				throw new NotFoundException();
			}

		}

		return $bits;
	}

}
