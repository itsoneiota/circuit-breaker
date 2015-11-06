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

	public function getServiceName(){
		return $this->serviceName;
	}

	public function setSamplePeriod($samplePeriod) {
		if(!is_int($samplePeriod)){
			throw new \InvalidArgumentException('samplePeriod must be an int.');
		}
		$this->samplePeriod = $samplePeriod;
	}

	public function registerEvent($event){
		$timestamp = $this->timeProvider->time();
		switch ($event) {
			case self::EVENT_SUCCESS:
				$key = $this->getSuccessesCacheKey($timestamp);
				break;
			case self::EVENT_FAILURE:
				$key = $this->getFailuresCacheKey($timestamp);
				break;
			case self::EVENT_REJECTION:
				$key = $this->getRejectionsCacheKey($timestamp);
				break;
			default:
				throw new \InvalidArgumentException('Unrecognised event: '.$event);
				break;
		}
		$this->cache->increment($key,1,1);
	}

	public function getResultsForPreviousPeriods($howMany){
		if(!is_int($howMany) || $howMany <= 0){
			throw new \InvalidArgumentException('howMany must be a positive integer.');
		}
		$timestamp = $this->timeProvider->time();
		$allKeys = [];
		$keysByPeriod = [];

		for ($i=(0-$howMany); $i < 0; $i++) {
			$period = $timestamp + ($i*$this->samplePeriod);
			$keys = $this->getKeysForPeriod($period);
			$keysByPeriod[$period] = $keys;
			$allKeys = array_merge($allKeys, array_values($keys));
		}
		$cacheValues = $this->cache->get($allKeys);
		$results = [];
		foreach ($keysByPeriod as $period => $keys) {
			$results[$period] = $this->buildResults($cacheValues, $keys, $period);
		}

		return $results;
	}

	protected function getKeysForPeriod($timestamp){
		$successesKey = $this->getSuccessesCacheKey($timestamp);
		$failuresKey = $this->getFailuresCacheKey($timestamp);
		$rejectionsKey = $this->getRejectionsCacheKey($timestamp);
		return ['successes'=>$successesKey, 'failures'=>$failuresKey, 'rejections'=>$rejectionsKey];
	}

	public function getResultsForPreviousPeriod() {
		$timestamp = $this->timeProvider->time();
		$previousPeriod = $timestamp - $this->samplePeriod;
		$results = $this->getResultsForPeriod($previousPeriod);
		return $results;
	}

	public function getResultsForPeriod($timestamp){
		$keys = $this->getKeysForPeriod($timestamp);
		$successesKey = $keys['successes'];
		$failuresKey = $keys['failures'];
		$rejectionsKey = $keys['rejections'];
		$cacheValues = $this->cache->get(array_values($keys));
		$results = $this->buildResults($cacheValues, $keys, $timestamp);
		return $results;
	}

	protected function buildResults($results, $keys, $timestamp){
		$successesKey = $keys['successes'];
		$failuresKey = $keys['failures'];
		$rejectionsKey = $keys['rejections'];

		$successes = isset($results[$successesKey]) ? intval($results[$successesKey]) : 0;
		$failures = isset($results[$failuresKey]) ? intval($results[$failuresKey]) : 0;
		$rejections = isset($results[$rejectionsKey]) ? intval($results[$rejectionsKey]) : 0;
		$totalRequests = $successes + $failures;
		$failureRate = $totalRequests != 0 ? round(($failures/$totalRequests)*100) : 0;
		$totalAttempts = $totalRequests + $rejections;
		$throttle = $totalAttempts != 0 ? 100-round(($rejections/$totalAttempts)*100) : 100;

		return [
			'periodStart' => $this->getPeriodStart($timestamp),
			'periodEnd' => $this->getPeriodEnd($timestamp),
			'successes'=>$successes,
			'failures'=>$failures,
			'rejections'=>$rejections,
			'totalRequests'=>$totalRequests,
			'failureRate'=>$failureRate,
			'throttle'=>$throttle
		];
	}

	protected function getPeriod($timestamp) {
		return floor($timestamp/$this->samplePeriod);
	}

	protected function getPeriodStart($timestamp) {
		return $this->getPeriod($timestamp) * $this->samplePeriod;
	}

	protected function getPeriodEnd($timestamp) {
		return (($this->getPeriod($timestamp)+1) * $this->samplePeriod) -1 ;
	}

	protected function getCacheName($timestamp) {
		return $this->serviceName.'.'.$this->getPeriod($timestamp);
	}

	protected function getSuccessesCacheKey($timestamp) {
		return $this->getCacheName($timestamp) . '.successes';
	}

	protected function getFailuresCacheKey($timestamp) {
		return $this->getCacheName($timestamp) . '.failures';
	}

	protected function getRejectionsCacheKey($timestamp) {
		return $this->getCacheName($timestamp) . '.rejections';
	}

}
