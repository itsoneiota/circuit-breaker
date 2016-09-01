<?php
namespace itsoneiota\circuitbreaker\random;

interface RandomNumberGenerator {

    /**
     * Generate a pseudo-random number.
     *
     * @param int $min
     * @param int $max
     * @return int $min <= return <= $max
     */
    public function rand($min,$max);

}
