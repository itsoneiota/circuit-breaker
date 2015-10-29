<?php
namespace itsoneiota\circuitbreaker;
/**
 * Records and retrieves statistics about a circuit.
 */
class CircuitMonitor {

	const EVENT_SUCCESS = 'success';
	const EVENT_FAILURE = 'failure';
	const EVENT_REJECTION = 'rejection';

	const SAMPLE_PERIOD_DEFAULT = 60;

	protected $serviceName;
	protected $cache;
	protected $timeProvider;

	protected $samplePeriod;

	/**
	 * Constructor
	 *
	 * @param string $serviceName Name of the service. Used in cache keys.
	 * @param itsoneiota\cache\Cache $cache Cache used to persist the state.
	 * @param itsoneiota\circuitbreaker\time\TimeProvider $timeProvider Time provider.

	 */
	public function __construct( $serviceName, \itsoneiota\cache\Cache $cache, time\TimeProvider $timeProvider, $samplePeriod=self::SAMPLE_PERIOD_DEFAULT) {
		$this->serviceName = $serviceName;
		$this->cache = $cache;
		$this->timeProvider = $timeProvider;
        $this->setSamplePeriod($samplePeriod);
	}

	public function setSamplePeriod($samplePeriod) {
		if(!is_int($samplePeriod)){
			throw new \InvalidArgumentException('samplePeriod must be an int.');
		}
		$this->samplePeriod = $samplePeriod;
	}

	public function registerEvent($event){
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
