<?php
namespace itsoneiota\circuitbreaker;
/**
 * A device used to detect high failure rates in calls to dependencies.
 */
class CircuitBreaker {

	const DYNAMICS_DETERMINISTIC = 0;
	const DYNAMICS_PROBABILISTIC = 1;

	const RECOVERY_RATE = 2;
	const THROTTLE_SNAPBACK = 80; // Percentage throttle beyond which will the circuit will 'snap' back to fully closed.

	const EVENT_SUCCESS = 'success';
	const EVENT_FAILURE = 'failure';
	const EVENT_REJECTION = 'rejection';

	protected $serviceName;
	protected $cache;
	protected $timeProvider;

	protected $enabled = TRUE;
	protected $samplePeriod = 60;
	protected $percentageFailureThreshold = 50;
	protected $minimumRequestsBeforeTrigger = 3;
	protected $isProbabilistic = FALSE;

	/**
	 * Constructor
	 *
	 * @param string $serviceName Name of the service. Used in cache keys.
	 * @param itsoneiota\cache\Cache $cache Cache used to persist the state.
	 * @param itsoneiota\circuitbreaker\time\TimeProvider $timeProvider Time provider.
	 * @param array $config Configuration array. Allowed keys: enabled, samplePeriod, percentageFailureThreshold, minimumRequestsBeforeTrigger.
	 */
	public function __construct(
		$serviceName,
		\itsoneiota\cache\Cache $cache,
		time\TimeProvider $timeProvider,
		array $config = []
	) {
		$this->serviceName = $serviceName;
		$this->cache = $cache;
		$this->timeProvider = $timeProvider;

		// TODO: Validate keys
		$configKeys = [
			'enabled',
			'samplePeriod',
			'percentageFailureThreshold',
			'minimumRequestsBeforeTrigger'
		];
		foreach ($configKeys as $key) {
			if (array_key_exists($key, $config)) {
				$this->{$key} = $config[$key];
			}
		}
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
		$this->registerEvent(self::EVENT_SUCCESS);
	}

	/**
	 * Register a failed request to the service.
	 *
	 * @return void
	 */
	public function registerFailure() {
		$this->registerEvent(self::EVENT_FAILURE);
	}

	/**
	 * Register a failed request to the service.
	 *
	 * @return void
	 */
	public function registerRejection() {
		$this->registerEvent(self::EVENT_REJECTION);
	}

	protected function registerEvent($event){
		$timeStamp = $this->timeProvider->time();
		switch ($event) {
			case self::EVENT_SUCCESS:
				$key = $this->getSuccessesCacheKey($timeStamp);
				break;
			case self::EVENT_FAILURE:
				$key = $this->getFailuresCacheKey($timeStamp);
				break;
			case self::EVENT_REJECTION:
				$key = $this->getRejectionsCacheKey($timeStamp);
				break;
			default:
				throw new \InvalidArgumentException('Unrecognised event: '.$event);
				break;
		}
		$this->cache->increment($key,1,1);
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
		$resultsForPreviousPeriod = $this->getResultsForPreviousPeriod();
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
		$threshold = min($successRate, ($prev['throttle'] * self::RECOVERY_RATE));

		if($threshold > self::THROTTLE_SNAPBACK){
			return TRUE;
		}
		$result = rand(0,100) < $threshold;
		return $result;
	}

	public function getResultsForPreviousPeriod() {
		$timeStamp = $this->timeProvider->time();
		$results = [
			'successes'=>0,
			'failures'=>0,
			'rejections'=>0,
			'totalRequests'=>0,
			'failureRate'=>0,
			'throttle'=>100
		];

		$previousPeriod = $timeStamp - $this->samplePeriod;
		$successesKey = $this->getSuccessesCacheKey($previousPeriod);
		$failuresKey = $this->getFailuresCacheKey($previousPeriod);
		$rejectionsKey = $this->getRejectionsCacheKey($previousPeriod);
		$results = $this->cache->get([$successesKey, $failuresKey, $rejectionsKey]);
		$successes = NULL === $results[$successesKey] ? 0 : $results[$successesKey];
		$failures = NULL === $results[$failuresKey] ? 0 : $results[$failuresKey];
		$rejections = NULL === $results[$rejectionsKey] ? 0 : $results[$rejectionsKey];

		$totalRequests = $successes + $failures;
		$failureRate = $totalRequests == 0 ? 0 : round(($failures/$totalRequests)*100);
		$totalAttempts = $totalRequests + $rejections;
		$throttle = $totalAttempts == 0 ? 100 : 100-round(($rejections/$totalAttempts)*100);

		return [
			'successes'=>$successes,
			'failures'=>$failures,
			'rejections'=>$rejections,
			'totalRequests'=>$totalRequests,
			'failureRate'=>$failureRate,
			'throttle'=>$throttle
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

	protected function getRejectionsCacheKey($timeStamp) {
		return $this->getCacheName($timeStamp) . '.rejections';
	}

}
