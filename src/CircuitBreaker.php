<?php
namespace itsoneiota\circuitbreaker;
use itsoneiota\circuitbreaker\random\RandomNumberGenerator;
/**
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

	// Configuration
	protected $enabled = TRUE;
	protected $percentageFailureThreshold = 50;
	protected $minimumRequestsBeforeTrigger = 3;
	protected $isProbabilistic = FALSE;
	protected $recoveryFactor = 2;

	/**
	 * Constructor
	 *
	 * @param string $serviceName Name of the service. Used in cache keys.
	 * @param itsoneiota\circuitbreaker\CircuitMonitor $circuitMonitor
	 * @param itsoneiota\circuitbreaker\random\RandomNumberGenerator $random
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
	 * Register a successful request to the service.
	 *
	 * @return void
	 */
	public function registerSuccess() {
		$this->circuitMonitor->registerEvent(CircuitMonitor::EVENT_SUCCESS);
	}

	/**
	 * Register a failed request to the service.
	 *
	 * @return void
	 */
	public function registerFailure() {
		$this->circuitMonitor->registerEvent(CircuitMonitor::EVENT_FAILURE);
	}

	/**
	 * Register a failed request to the service.
	 *
	 * @return void
	 */
	public function registerRejection() {
		$this->circuitMonitor->registerEvent(CircuitMonitor::EVENT_REJECTION);
	}

	/**
	 * Is the circuit closed (i.e. functioning)?
	 *
	 * @return void
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
	 * @param array $resultsForPreviousPeriod Results from the previous time period.
	 * @return boolean
	 */
	protected function tripResponse($prev) {
		if(!$this->isProbabilistic){
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
