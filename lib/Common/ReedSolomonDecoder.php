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

/**
 * <p>Implements Reed-Solomon decoding, as the name implies.</p>
 *
 * <p>The algorithm will not be explained here, but the following references were helpful
 * in creating this implementation:</p>
 *
 * <ul>
 * <li>Bruce Maggs.
 * <a href="http://www.cs.cmu.edu/afs/cs.cmu.edu/project/pscico-guyb/realworld/www/rs_decode.ps">
 * "Decoding Reed-Solomon Codes"</a> (see discussion of Forney's Formula)</li>
 * <li>J.I. Hall. <a href="www.mth.msu.edu/~jhall/classes/codenotes/GRS.pdf">
 * "Chapter 5. Generalized Reed-Solomon Codes"</a>
 * (see discussion of Euclidean algorithm)</li>
 * </ul>
 *
 * <p>Much credit is due to William Rucklidge since portions of this code are an indirect
 * port of his C++ Reed-Solomon implementation.</p>
 *
 * @author Sean Owen
 * @author William Rucklidge
 * @author sanfordsquires
 */
final class ReedSolomonDecoder{

	/**
	 * <p>Decodes given set of received codewords, which include both data and error-correction
	 * codewords. Really, this means it uses Reed-Solomon to detect and correct errors, in-place,
	 * in the input.</p>
	 *
	 * @param array $received data and error-correction codewords
	 * @param int   $twoS     number of error-correction codewords available
	 *
	 * @throws ReedSolomonException if decoding fails for any reason
	 */
	public function decode(array &$received, int $twoS):void{
		$poly                 = new GenericGFPoly($received);
		$syndromeCoefficients = \array_fill(0, $twoS, 0);
		$noError              = true;

		for($i = 0; $i < $twoS; $i++){
			$eval                                                         = $poly->evaluateAt(GF256::exp($i));
			$syndromeCoefficients[\count($syndromeCoefficients) - 1 - $i] = $eval;

			if($eval !== 0){
				$noError = false;
			}
		}

		if($noError){
			return;
		}

		$syndrome            = new GenericGFPoly($syndromeCoefficients);
		$sigmaOmega          = $this->runEuclideanAlgorithm(GF256::buildMonomial($twoS, 1), $syndrome, $twoS);
		$sigma               = $sigmaOmega[0];
		$omega               = $sigmaOmega[1];
		$errorLocations      = $this->findErrorLocations($sigma);
		$errorMagnitudes     = $this->findErrorMagnitudes($omega, $errorLocations);
		$errorLocationsCount = \count($errorLocations);

		for($i = 0; $i < $errorLocationsCount; $i++){
			$position = \count($received) - 1 - GF256::log($errorLocations[$i]);

			if($position < 0){
				throw new ReedSolomonException('Bad error location');
			}

			$received[$position] = GF256::addOrSubtract($received[$position], $errorMagnitudes[$i]);
		}

	}

	/**
	 * @throws \Zxing\Common\ReedSolomonException
	 */
	private function runEuclideanAlgorithm(GenericGFPoly $a, GenericGFPoly $b, int $R):array{
		// Assume a's degree is >= b's
		if($a->getDegree() < $b->getDegree()){
			$temp = $a;
			$a    = $b;
			$b    = $temp;
		}

		$rLast = $a;
		$r     = $b;
		$tLast = new GenericGFPoly([0]);
		$t     = new GenericGFPoly([1]);

		// Run Euclidean algorithm until r's degree is less than R/2
		while($r->getDegree() >= $R / 2){
			$rLastLast = $rLast;
			$tLastLast = $tLast;
			$rLast     = $r;
			$tLast     = $t;

			// Divide rLastLast by rLast, with quotient in q and remainder in r
			if($rLast->isZero()){
				// Oops, Euclidean algorithm already terminated?
				throw new ReedSolomonException('r_{i-1} was zero');
			}

			$r                      = $rLastLast;
			$q                      = new GenericGFPoly([0]);
			$denominatorLeadingTerm = $rLast->getCoefficient($rLast->getDegree());
			$dltInverse             = GF256::inverse($denominatorLeadingTerm);

			while($r->getDegree() >= $rLast->getDegree() && !$r->isZero()){
				$degreeDiff = $r->getDegree() - $rLast->getDegree();
				$scale      = GF256::multiply($r->getCoefficient($r->getDegree()), $dltInverse);
				$q          = $q->addOrSubtract(GF256::buildMonomial($degreeDiff, $scale));
				$r          = $r->addOrSubtract($rLast->multiplyByMonomial($degreeDiff, $scale));
			}

			$t = $q->multiply($tLast)->addOrSubtract($tLastLast);

			if($r->getDegree() >= $rLast->getDegree()){
				throw new ReedSolomonException('Division algorithm failed to reduce polynomial?');
			}
		}

		$sigmaTildeAtZero = $t->getCoefficient(0);

		if($sigmaTildeAtZero === 0){
			throw new ReedSolomonException('sigmaTilde(0) was zero');
		}

		$inverse = GF256::inverse($sigmaTildeAtZero);
		$sigma   = $t->multiplyInt($inverse);
		$omega   = $r->multiplyInt($inverse);

		return [$sigma, $omega];
	}

	/**
	 * @throws \Zxing\Common\ReedSolomonException
	 */
	private function findErrorLocations(GenericGFPoly $errorLocator):array{
		// This is a direct application of Chien's search
		$numErrors = $errorLocator->getDegree();

		if($numErrors === 1){ // shortcut
			return [$errorLocator->getCoefficient(1)];
		}

		$result = \array_fill(0, $numErrors, 0);
		$e      = 0;

		for($i = 1; $i < 256 && $e < $numErrors; $i++){
			if($errorLocator->evaluateAt($i) === 0){
				$result[$e] = GF256::inverse($i);
				$e++;
			}
		}

		if($e !== $numErrors){
			throw new ReedSolomonException('Error locator degree does not match number of roots');
		}

		return $result;
	}

	private function findErrorMagnitudes(GenericGFPoly $errorEvaluator, array $errorLocations):array{
		// This is directly applying Forney's Formula
		$s      = \count($errorLocations);
		$result = \array_fill(0, $s, 0);

		for($i = 0; $i < $s; $i++){
			$xiInverse   = GF256::inverse($errorLocations[$i]);
			$denominator = 1;

			for($j = 0; $j < $s; $j++){
				if($i !== $j){
#					$denominator = GF256::multiply($denominator, GF256::addOrSubtract(1, GF256::multiply($errorLocations[$j], $xiInverse)));
					// Above should work but fails on some Apple and Linux JDKs due to a Hotspot bug.
					// Below is a funny-looking workaround from Steven Parkes
					$term        = GF256::multiply($errorLocations[$j], $xiInverse);
					$denominator = GF256::multiply($denominator, (($term & 0x1) === 0 ? $term | 1 : $term & ~1));
				}
			}

			$result[$i] = GF256::multiply($errorEvaluator->evaluateAt($xiInverse), GF256::inverse($denominator));
		}

		return $result;
	}

}
