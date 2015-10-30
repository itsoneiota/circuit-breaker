<?php
namespace itsoneiota\circuitbreaker\ui;
use \itsoneiota\circuitbreaker\CircuitMonitor;

/**
 * Display of circuitbreaker status.
 */
class CircuitBreakerDisplay {

    protected $monitor;
    protected $periodsToShow = 20;
    protected $sparks = ['▁','▂','▃','▄','▅','▆','▇'];

    function __construct(CircuitMonitor $monitor){
        $this->monitor = $monitor;
    }

    function sparkLine($values, $min=NULL, $max=NULL){
        $min = NULL === $min ? call_user_func_array('min',$values) : $min;
        $max = NULL === $max ? call_user_func_array('max',$values) : $max;
        $range = $max - $min;
        $numSparks = count($this->sparks);
        $step = $range / $numSparks;

        $sparkLine = [];
        foreach ($values as $value) {
            $key = $step == 0 ? 0 : floor($value/$step);
            $key = min($key, $numSparks-1);
            $sparkLine[] = $this->sparks[$key];
        }

        return implode($sparkLine);
    }

    public function show(){
        $results = $this->monitor->getResultsForPreviousPeriods($this->periodsToShow);
        $successRates = [];
        $successes = [];
        $failures = [];
        $rejections = [];
        $throttles = [];

        foreach ($results as $result) {
            // $successes[] = $result['successes'];
            // $failures[] = $result['failures'];
            // $rejections[] = $result['rejections'];
            $failureRates[] = $result['failureRate'];
            $throttles[] = $result['throttle'];
        }
        $requestCounts = array_merge($successes,$failures,$rejections,[0]);
        $rMin = min($requestCounts);
        $rMax = max($requestCounts);

        $c = new CLIColours();
        echo $c->getColoredString($this->sparkLine($failureRates, 0, 100), 'red'), PHP_EOL;
        echo $c->getColoredString($this->sparkLine($throttles, 0, 100), 'cyan'), PHP_EOL;
    }
}
