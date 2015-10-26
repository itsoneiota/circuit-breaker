<?php
namespace itsoneiota\circuitbreaker;
/**
 * A device used to detect high failure rates in calls to dependencies.
 */
class CircuitBreaker {

	const DYNAMICS_DETERMINISTIC = 0;
	const DYNAMICS_PROBABILISTIC = 1;

	protected $serviceName;
	protected $cache;
	protected $timeProvider;
	protected $samplePeriod;
	protected $percentageFailureThreshold;
	protected $minimumRequestsBeforeTrigger;
	protected $isProbabilistic = FALSE;

	/**
	 * Constructor
	 *
	 * @param string $serviceName Name of the service. Used in cache keys.
	 * @param itsoneiota\cache\Cache $cache Cache used to persist the state.
	 * @param itsoneiota\circuitbreaker\time\TimeProvider $timeProvider Time provider.
	 * @param int $samplePeriod The period of time, in seconds, over which successes/failures will be aggregated.
	 * @param int $percentageFailureThreshold Percentage of requests in a sample period that must fail in order to open the circuit.
	 * @param int $minimumRequestsBeforeTrigger The minimum request count needed to trigger a break.
	 */
	public function __construct(
		$serviceName,
		\itsoneiota\cache\Cache $cache,
		time\TimeProvider $timeProvider,
		$samplePeriod=60,
		$percentageFailureThreshold=50,
		$minimumRequestsBeforeTrigger=3
	) {
		$this->serviceName = $serviceName;
		$this->cache = $cache;
		$this->timeProvider = $timeProvider;
		$this->samplePeriod = $samplePeriod;
		$this->percentageFailureThreshold = $percentageFailureThreshold;
		$this->minimumRequestsBeforeTrigger = $minimumRequestsBeforeTrigger;
	}

	/**
	 * Set the dynamics properties of the switch.
	 *
	 * Currently, only self::DYNAMICS_PROBABILISTIC.
	 *
	 * @param int $options Bitmask of DYNAMICS_* constants.
	 * @return void
	 */
	public function setDynamics($options) {
		$this->isProbabilistic = $options & self::DYNAMICS_PROBABILISTIC;
	}

	/**
	 * Register a successful request to the service.
	 *
	 * @return void
	 */
	public function registerSuccess() {
		$timeStamp = $this->timeProvider->time();
		$this->cache->increment($this->getSuccessesCacheKey($timeStamp),1,1);
	}

	/**
	 * Register a failed request to the service.
	 *
	 * @return void
	 */
	public function registerFailure() {
		$timeStamp = $this->timeProvider->time();
		$this->cache->increment($this->getFailuresCacheKey($timeStamp),1,1);
	}

	/**
	 * Is the circuit closed (i.e. functioning)?
	 *
	 * @return void
	 */
	public function isClosed() {
		$resultsForPreviousPeriod = $this->getResultsForPreviousPeriod();

		return $this->isOperatingNormally($resultsForPreviousPeriod) || $this->isRecovering($resultsForPreviousPeriod);
	}

	protected function isOperatingNormally($results) {
		return
			$results['totalRequests'] < $this->minimumRequestsBeforeTrigger ||
			$results['failureRate'] < $this->percentageFailureThreshold;
	}

	/**
	 * Is the circuit recovering?
	 * If the circuit is open and dynamics have been sent to probabilistic,
	 * this method will return true with the same probability as a successful request in the previous period.
	 *
	 * e.g. If, in the last period, 10% of requests were successful, this method will return TRUE
	 * on approximately 1 in 10 calls.
	 *
	 * @param array $resultsForPreviousPeriod Results from the previous time period.
	 * @return void
	 */
	protected function isRecovering($resultsForPreviousPeriod) {
		return $this->isProbabilistic ? rand(0,100) > $resultsForPreviousPeriod['failureRate'] : FALSE;
	}

	protected function getResultsForPreviousPeriod() {
		$timeStamp = $this->timeProvider->time();
		$results = [
			'successes'=>0,
			'failures'=>0,
			'totalRequests'=>0,
			'failureRate'=>0
		];

		$previousPeriod = $timeStamp - $this->samplePeriod;
		$successes = $this->cache->get($this->getSuccessesCacheKey($previousPeriod));
		$failures = $this->cache->get($this->getFailuresCacheKey($previousPeriod));

		$totalRequests = $successes + $failures;
		$failureRate = $totalRequests == 0 ? 0 : round(($failures/$totalRequests)*100);
		return [
			'successes'=>$successes,
			'failures'=>$failures,
			'totalRequests'=>$totalRequests,
			'failureRate'=>$failureRate
		];
	}

	protected function getPeriod($timeStamp) {
		return floor($timeStamp/$this->samplePeriod);
	}

	protected function getCacheName($timeStamp) {
		return $this->serviceName.'.'.$this->getPeriod($timeStamp);
	}

	protected function getSuccessesCacheKey($timeStamp) {
		return $this->getCacheName($timeStamp) . '.successes';
	}

	protected function getFailuresCacheKey($timeStamp) {
		return $this->getCacheName($timeStamp) . '.failures';
	}

}
