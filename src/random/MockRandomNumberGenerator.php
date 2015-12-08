<?php
namespace itsoneiota\circuitbreaker\random;

/**
 * Deterministic random number generator.
 * Used purely for CircuitBreakerTest, where it's always used 100 times.
 * So this class just returns $min --> $max, on repeat.
 */
class MockRandomNumberGenerator implements RandomNumberGenerator {

    protected $counter = NULL;

    public function rand($min,$max){
        if (NULL === $this->counter || $this->counter == $max){
            $this->counter = $min;
        }else{
            $this->counter++;
        }
        return $this->counter;
    }

}
