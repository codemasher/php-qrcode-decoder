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

/**
 * <p>Encapsulates information about finder patterns in an image, including the location of
 * the three finder patterns, and their estimated module size.</p>
 *
 * @author Sean Owen
 */
final class FinderPatternInfo{

	private FinderPattern $bottomLeft;
	private FinderPattern $topLeft;
	private FinderPattern $topRight;

	public function __construct(array $patternCenters){
		[$this->bottomLeft, $this->topLeft, $this->topRight] = $patternCenters;
	}

	public function getBottomLeft():FinderPattern{
		return $this->bottomLeft;
	}

	public function getTopLeft():FinderPattern{
		return $this->topLeft;
	}

	public function getTopRight():FinderPattern{
		return $this->topRight;
	}
}
