<?php
namespace itsoneiota\circuitbreaker\random;

/**
 * Deterministic random number generator.
 * Used purely for CircuitBreakerTest, where it's always used 100 times.
 * So this class just returns 0 --> 99, on repeat.
 */
class MockRandomNumberGenerator implements RandomNumberGenerator {

    protected $counter = 0;

    public function rand($min,$max){
        return (++$this->counter) % 100;
    }

}
