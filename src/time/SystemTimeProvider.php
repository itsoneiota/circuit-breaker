<?php
namespace itsoneiota\circuitbreaker\time;
/**
 * A dumb wrapper for time().
 */
class SystemTimeProvider implements TimeProvider {
    public function time(){
        return time();
    }
}
