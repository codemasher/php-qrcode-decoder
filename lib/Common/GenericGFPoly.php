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

namespace Zxing\Common;

use InvalidArgumentException;

/**
 * <p>Represents a polynomial whose coefficients are elements of a GF.
 * Instances of this class are immutable.</p>
 *
 * <p>Much credit is due to William Rucklidge since portions of this code are an indirect
 * port of his C++ Reed-Solomon implementation.</p>
 *
 * @author Sean Owen
 */
final class GenericGFPoly{

	private array $coefficients;

	/**
	 * @param array $coefficients array coefficients as ints representing elements of GF(size), arranged
	 *                            from most significant (highest-power term) coefficient to least significant
	 *
	 * @throws InvalidArgumentException if argument is null or empty, or if leading coefficient is 0 and this is not a
	 *                                  constant polynomial (that is, it is not the monomial "0")
	 */
	public function __construct(array $coefficients){

		if(empty($coefficients)){
			throw new InvalidArgumentException('arg $coefficients is empty');
		}

		$coefficientsLength = \count($coefficients);

		if($coefficientsLength > 1 && $coefficients[0] === 0){
			// Leading term must be non-zero for anything except the constant polynomial "0"
			$firstNonZero = 1;
			while($firstNonZero < $coefficientsLength && $coefficients[$firstNonZero] === 0){
				$firstNonZero++;
			}

			if($firstNonZero === $coefficientsLength){
				$this->coefficients = [0];
			}
			else{
				$this->coefficients = \array_fill(0, $coefficientsLength - $firstNonZero, 0);
				$this->coefficients = arraycopy($coefficients, $firstNonZero, $this->coefficients, 0, \count($this->coefficients));
			}
		}
		else{
			$this->coefficients = $coefficients;
		}
	}

	/**
	 * @return int evaluation of this polynomial at a given point
	 */
	public function evaluateAt(int $a):int{

		if($a === 0){
			// Just return the x^0 coefficient
			return $this->getCoefficient(0);
		}

		$size = \count($this->coefficients);

		if($a === 1){
			// Just the sum of the coefficients
			$result = 0;
			foreach($this->coefficients as $coefficient){
				$result = GF256::addOrSubtract($result, $coefficient);
			}

			return $result;
		}

		$result = $this->coefficients[0];

		for($i = 1; $i < $size; $i++){
			$result = GF256::addOrSubtract(GF256::multiply($a, $result), $this->coefficients[$i]);
		}

		return $result;
	}

	/**
	 * @return int $coefficient of x^degree term in this polynomial
	 */
	public function getCoefficient(int $degree):int{
		return $this->coefficients[\count($this->coefficients) - 1 - $degree];
	}

	/**
	 * @param GenericGFPoly $other
	 *
	 * @return \Zxing\Common\GenericGFPoly
	 */
	public function multiply(GenericGFPoly $other):GenericGFPoly{

		if($this->isZero() || $other->isZero()){
			return new self([0]);
		}

		$aCoefficients = $this->coefficients;
		$aLength       = \count($aCoefficients);
		$bCoefficients = $other->coefficients;
		$bLength       = \count($bCoefficients);
		$product       = \array_fill(0, $aLength + $bLength - 1, 0);

		for($i = 0; $i < $aLength; $i++){
			$aCoeff = $aCoefficients[$i];

			for($j = 0; $j < $bLength; $j++){
				$product[$i + $j] = GF256::addOrSubtract(
					$product[$i + $j],
					GF256::multiply($aCoeff, $bCoefficients[$j])
				);
			}
		}

		return new self($product);
	}

	public function multiplyInt(int $scalar):GenericGFPoly{

		if($scalar === 0){
			return new self([0]);
		}

		if($scalar === 1){
			return $this;
		}

		$size    = \count($this->coefficients);
		$product = \array_fill(0, $size, 0);

		for($i = 0; $i < $size; $i++){
			$product[$i] = GF256::multiply($this->coefficients[$i], $scalar);
		}

		return new self($product);
	}

	/**
	 * @return bool true if this polynomial is the monomial "0"
	 */
	public function isZero():bool{
		return $this->coefficients[0] === 0;
	}

	public function multiplyByMonomial(int $degree, int $coefficient):GenericGFPoly{

		if($degree < 0){
			throw new InvalidArgumentException();
		}

		if($coefficient === 0){
			return new self([0]);
		}

		$size    = \count($this->coefficients);
		$product = \array_fill(0, $size + $degree, 0);

		for($i = 0; $i < $size; $i++){
			$product[$i] = GF256::multiply($this->coefficients[$i], $coefficient);
		}

		return new self($product);
	}

	/**
	 * @return int $degree of this polynomial
	 */
	public function getDegree():int{
		return \count($this->coefficients) - 1;
	}

	public function addOrSubtract(GenericGFPoly $other):GenericGFPoly{

		if($this->isZero()){
			return $other;
		}

		if($other->isZero()){
			return $this;
		}

		$smallerCoefficients = $this->coefficients;
		$largerCoefficients  = $other->coefficients;

		if(\count($smallerCoefficients) > \count($largerCoefficients)){
			$temp                = $smallerCoefficients;
			$smallerCoefficients = $largerCoefficients;
			$largerCoefficients  = $temp;
		}

		$sumDiff    = \array_fill(0, \count($largerCoefficients), 0);
		$lengthDiff = \count($largerCoefficients) - \count($smallerCoefficients);
		// Copy high-order terms only found in higher-degree polynomial's coefficients
		$sumDiff = arraycopy($largerCoefficients, 0, $sumDiff, 0, $lengthDiff);

		$countLargerCoefficients = \count($largerCoefficients);

		for($i = $lengthDiff; $i < $countLargerCoefficients; $i++){
			$sumDiff[$i] = GF256::addOrSubtract($smallerCoefficients[$i - $lengthDiff], $largerCoefficients[$i]);
		}

		return new self($sumDiff);
	}

}
