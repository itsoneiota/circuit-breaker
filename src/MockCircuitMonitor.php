<?php
namespace itsoneiota\circuitbreaker;
/**
 * Records and retrieves statistics about a circuit.
 */
class MockCircuitMonitor extends CircuitMonitor {

	public $events = [
		self::EVENT_SUCCESS => 0,
		self::EVENT_FAILURE => 0,
		self::EVENT_REJECTION => 0
	];

	public $previousResults = [
		'successes'=>0,
		'failures'=>0,
		'rejections'=>0,
		'totalRequests'=>0,
		'failureRate'=>0,
		'throttle'=>100
	];

	public function __construct(){}

	public function setSamplePeriod($samplePeriod) {
		if(!is_int($samplePeriod)){
			throw new \InvalidArgumentException('samplePeriod must be an int.');
		}
		$this->samplePeriod = $samplePeriod;
	}

	public function registerEvent($event){
		$this->events[$event]++;
	}

	public function getResultsForPreviousPeriod() {
		return $this->previousResults;
	}

	public function getResultsForPeriod($timestamp){}

}
