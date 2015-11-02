<?php
namespace itsoneiota\circuitbreaker;
/**
 * A device used to detect high failure rates in calls to dependencies.
 */
class CircuitBreaker {

	// TODO: Inject a random number generator to make tests deterministic.

	const THROTTLE_SNAPBACK = 80; // Percentage throttle beyond which will the circuit will 'snap' back to fully closed.

	protected $circuitMonitor;

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
	 */
	public function __construct(CircuitMonitor $circuitMonitor) {
		$this->circuitMonitor = $circuitMonitor;
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
		if($this->isOperatingNormally($resultsForPreviousPeriod)){
			return TRUE;
		}else{
			return $this->tripResponse($resultsForPreviousPeriod);
		}
	}

	protected function isOperatingNormally($results) {
		$insufficientRequests = $results['totalRequests'] < $this->minimumRequestsBeforeTrigger;
		$failureRateBelowThreshold = $results['failureRate'] < $this->percentageFailureThreshold;
		$recovering = $results['throttle'] < 100;
		return $insufficientRequests || ($failureRateBelowThreshold && !$recovering);
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

		// Don't suddenly increase the throttle just because a limited number of requests succeeded.
		$threshold = min($successRate, ($prev['throttle'] * $this->recoveryFactor));

		if($threshold > self::THROTTLE_SNAPBACK){
			return TRUE;
		}
		$result = rand(0,100) < $threshold;
		return $result;
	}

}
