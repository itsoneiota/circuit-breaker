<?php
namespace itsoneiota\circuitbreaker\random;

/**
 * Simple wrapper for rand();
 */
class Rand implements RandomNumberGenerator {

    public function rand($min,$max){
        return rand($min,$max);
    }

}
