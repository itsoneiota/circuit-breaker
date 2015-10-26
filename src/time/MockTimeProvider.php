<?php
namespace itsoneiota\circuitbreaker\time;
/**
 * A time provider for use in tests.
 */
class MockTimeProvider implements TimeProvider {

    protected $time;

    public function __construct($time){
        $this->time = $time;
    }

    public function time(){
        return $this->time;
    }

    public function advance($increment){
        $this->time += $increment;
    }

    public function set($time){
        $this->time = $time;
    }
}
