<?php

namespace itsoneiota\circuitbreaker;

use itsoneiota\circuitbreaker\random\RandomNumberGenerator;
use itsoneiota\count\StatsD;

/**
 * Class CircuitBreaker
 * @package itsoneiota\circuitbreaker
 *
 * A device used to detect high failure rates in calls to dependencies.
 */
class CircuitBreaker {

	// If the circuit is fully open, and is letting 0 traffic through,
	// this figure will be the percentage throttle in the next timestep.
	const FIRST_RECOVERY_STEP = 10; // %

 	// Percentage throttle beyond which will the circuit
	// will 'snap' back to fully closed.
	const THROTTLE_SNAPBACK = 80; // %

	// Dependencies
	protected $circuitMonitor;
	protected $random;
    /** @var StatsD */
	protected $stats;
    protected $statsPrefix;

	// Configuration
	protected $enabled = TRUE;
	protected $percentageFailureThreshold = 50;
	protected $minimumRequestsBeforeTrigger = 3;
	protected $isProbabilistic = TRUE;
	protected $recoveryFactor = 2;

    /**
     * CircuitBreaker constructor.
     *
     * @param CircuitMonitor        $circuitMonitor
     * @param RandomNumberGenerator $random
     */
	public function __construct(CircuitMonitor $circuitMonitor, RandomNumberGenerator $random) {
		$this->circuitMonitor = $circuitMonitor;
		$this->random = $random;
	}

	public function getMonitor(){
		return $this->circuitMonitor;
	}

	public function setEnabled($enabled) {
		if(!is_bool($enabled)){
			throw new \InvalidArgumentException('enabled must be boolean.');
		}
		$this->enabled = $enabled;
	}

	public function setMinimumRequestsBeforeTrigger($minimumRequestsBeforeTrigger) {
		if(!is_int($minimumRequestsBeforeTrigger)){
			throw new \InvalidArgumentException('minimumRequestsBeforeTrigger must be an int.');
		}
		$this->minimumRequestsBeforeTrigger = $minimumRequestsBeforeTrigger;
	}

	public function setPercentageFailureThreshold($percentageFailureThreshold) {
		if(!is_numeric($percentageFailureThreshold)){
			throw new \InvalidArgumentException('percentageFailureThreshold must be an int.');
		}
		$this->percentageFailureThreshold = $percentageFailureThreshold;
	}

	public function setProbabilisticDynamics($probabilistic) {
		if(!is_bool($probabilistic)){
			throw new \InvalidArgumentException('probabilistic must be boolean.');
		}
		$this->isProbabilistic = $probabilistic;
	}

	public function setRecoveryFactor($recoveryFactor) {
		if(!is_numeric($recoveryFactor) || $recoveryFactor <= 1){
			throw new \InvalidArgumentException('recoveryFactor must be a number >1.');
		}
		$this->recoveryFactor = $recoveryFactor;
	}

    /**
     * @param StatsD $stats
     * @param string $prefix
     */
	public function setStatsCollector(StatsD $stats, $prefix){
      $this->stats = $stats;
      $this->statsPrefix = $prefix;
    }

	/**
	 * Register a successful request to the service.
	 *
	 * @return void
	 */
	public function registerSuccess() {
		$this->registerEvent(CircuitMonitor::EVENT_SUCCESS);
	}

	/**
	 * Register a failed request to the service.
	 *
	 * @return void
	 */
	public function registerFailure() {
		$this->registerEvent(CircuitMonitor::EVENT_FAILURE);
	}

	/**
	 * Register a failed request to the service.
	 *
	 * @return void
	 */
	public function registerRejection() {
		$this->registerEvent(CircuitMonitor::EVENT_REJECTION);
	}

    protected function registerEvent($event){
      $this->circuitMonitor->registerEvent($event);
      if($this->stats){
        $this->stats->increment("{$this->statsPrefix}.{$event}");
      }
    }

	/**
	 * Is the circuit closed (i.e. functioning)?
	 *
	 * @return bool
	 */
	public function isClosed() {
		if(!$this->enabled){
			return TRUE;
		}
		$resultsForPreviousPeriod = $this->circuitMonitor->getResultsForPreviousPeriod();
		if($this->hasTripped($resultsForPreviousPeriod)){
			return $this->tripResponse($resultsForPreviousPeriod);
		}else{
			return TRUE;
		}
	}

    /**
     * Has the circuit been tripped?
     *
     * @param $results
     *
     * @return bool
     */
	protected function hasTripped($results) {
		$sufficientRequests = $results['totalRequests'] >= $this->minimumRequestsBeforeTrigger;
		$failureRateMeetsThreshold = $results['failureRate'] >= $this->percentageFailureThreshold;

		/**
		 *  This is belt and braces.
		 * By comparing throttle to the snapback value rather than 100,
		 * we get a quick decision, and avoid the possibility of rejecting requests unnecessarily.
		 */
		$recovering = $results['throttle'] < self::THROTTLE_SNAPBACK;
		
		return ($sufficientRequests && $failureRateMeetsThreshold) || $recovering;
	}

	/**
	 * How should we respond to a tripped circuit?
	 * If the circuit is open and dynamics have been sent to probabilistic,
	 * this method will return true with the same probability as a successful request in the previous period.
	 *
	 * e.g. If, in the last period, 10% of requests were successful, this method will return TRUE
	 * on approximately 1 in 10 calls.
	 *
	 * If the circuit dynamics are deterministic, the circuit will be open.
	 *
	 * @param array $prev Results from the previous time period.
	 * @return boolean
	 */
	protected function tripResponse($prev) {
		if(!$this->isProbabilistic){
			//If we're deterministic, the switch is either open or closed. this is maybe not ideal. as if we're at 100 percent and rejecting everything the CB will never close again. 
			//But if we ramp it up again, we may as well be using the probabilistic version. 
			//I think it's fine to keep this as is, for circuits that require human interaction to resolve. But in that case we'll need a manual way to override it. 
			return FALSE;
			
		}
		$successRate = 100-$prev['failureRate'];

		/**
		 * Don't suddenly increase the throttle just because a limited number of requests succeeded.
		 */
		$newThrottle = ($prev['throttle'] * $this->recoveryFactor);

		/**
		 * If the throttle was 0 in the previous period, multiplying it won't do any good.
		 * Add a fixed step to start the recovery.
		 */
		if($newThrottle == 0){
			$newThrottle = self::FIRST_RECOVERY_STEP;
		}
		$threshold = min($successRate, $newThrottle);

		if($threshold > self::THROTTLE_SNAPBACK){
			
			return TRUE;
		}

		$closed = $this->random->rand(0,100) < $threshold;
		
		return $closed;
	}

}
