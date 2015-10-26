<?php
namespace itsoneiota\circuitbreaker\time;
/**
 * Time provider that provides the same timestamp whenever it's asked.
 */
class SystemTimeProvider implements TimeProvider {

    protected $time;

    public function __construct($time){
        $this->time = $time;
    }

    public function time(){
        return $time;
    }
}
