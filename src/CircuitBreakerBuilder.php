<?php
namespace itsoneiota\circuitbreaker;
use \itsoneiota\cache\Counter;
use \itsoneiota\circuitbreaker\time\TimeProvider;
use \itsoneiota\circuitbreaker\time\SystemTimeProvider;
use itsoneiota\circuitbreaker\random\RandomNumberGenerator;
use \itsoneiota\count\StatsD;

class CircuitBreakerBuilder {

    protected $serviceName;
    protected $logger;
    protected $counter;
    protected $counterBuilder;
    protected $memcachedHost;
    protected $memcachedPort;
    protected $timeProvider;
    protected $random;
    protected $samplePeriod = CircuitMonitor::SAMPLE_PERIOD_DEFAULT;
    protected $stats;
    protected $config = [];

    public static function create($serviceName){
        return new CircuitBreakerBuilder($serviceName);
    }

    public function __construct($serviceName){
        $this->serviceName = $serviceName;
    }

    protected function logError($message){
        if ($this->logger) {
            // Definition of critical: Application component unavailable, unexpected exception.
            $config = $this->config;
            $config['memcachedHost'] = $this->memcachedHost;
            $config['memcachedPort'] = $this->memcachedPort;
            $configJSON = json_encode($config);
            $this->logger->critical($message . ' ' . $configJSON);
        }
    }

    /**
     * Set a logger to report errors that occur while building.
     * Without a logger, errors will be silenced.
     *
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function withLogger(\Psr\Log\LoggerInterface $logger){
        $this->logger = $logger;
        return $this;
    }

    public function withCache(Counter $counter=NULL){
        $this->counter = $counter;
        return $this;
    }

    public function withCounterBuilder(callable $counterBuilder){
        $this->counterBuilder = $counterBuilder;
        return $this;
    }

    public function withMemcachedServer($host,$port){
        $this->memcachedHost = $host;
        $this->memcachedPort = $port;

        return $this;
    }

    public function withTimeProvider(TimeProvider $timeProvider){
        $this->timeProvider = $timeProvider;
        return $this;
    }

    public function withRandomNumberGenerator(RandomNumberGenerator $random){
        $this->random = $random;
        return $this;
    }

    public function withFixedTime($timestamp){
        $this->timeProvider = new FixedTimeProvider($timestamp);
        return $this;
    }

    public function withSystemTime(){
        $this->timeProvider = new SystemTimeProvider();
        return $this;
    }

    public function withSamplePeriod($samplePeriod){
        $this->samplePeriod = $samplePeriod;
        return $this;
    }

    public function enabled(){
        $this->config['enabled'] = TRUE;
        return $this;
    }

    public function disabled(){
        $this->config['enabled'] = FALSE;
        return $this;
    }

    public function withConfig(array $config){
        // Pluck the sample period out of the config,
        // as it's the only bit that goes to the monitor.
        if (isset($config['samplePeriod'])) {
            $this->withSamplePeriod($config['samplePeriod']);
        }
        $this->config = $config;
        return $this;
    }

    public function withMinimumRequestsBeforeTrigger($min){
        $this->config['minimumRequestsBeforeTrigger'] = $min;
        return $this;
    }

    public function withDeterministicDynamics(){
        $this->config['probabilisticDynamics'] = FALSE;
        return $this;
    }

    public function withProbabilisticDynamics(){
        $this->config['probabilisticDynamics'] = TRUE;
        return $this;
    }

    public function withRecoveryFactor($factor){
        $this->config['recoverFactor'] = $factor;
        return $this;
    }

    public function withStatsCollector(StatsD $stats){
      $this->stats = $stats;
      return $this;
    }

    protected function buildMemcached(){
        $memcached = new \Memcached();
        $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, TRUE);
    	if(!$memcached->addServer($this->memcachedHost,$this->memcachedPort)){
    		$message = $memcached->getResultMessage()."(".$memcached->getResultCode().")";
    		throw new \RuntimeException("Can't connect to memcached: ".$host.", message: $port");
    	}

        return $memcached;
    }

    protected function buildCounterFromMemcachedServer(){
        $mc = $this->buildMemcached();
        $keyPrefix = 'CircuitBreaker-'.$this->serviceName;
        $expiration = $this->samplePeriod * 100;
        $counter = new \itsoneiota\cache\MemcachedCounter($mc, $keyPrefix, $expiration);
        return $counter;
    }

    protected function buildCounter(){
        if(NULL !== $this->counter){
            return $this->counter;
        }
        $counter = NULL;
        if(NULL !== $this->counterBuilder){
            $counter = $this->tryCounterBuilder($this->counterBuilder);
        }
        if(NULL === $counter && NULL !== $this->memcachedHost){
            $counter = $this->tryCacheBuilder([$this,'buildCacheFromMemcachedServer']);
        }
        if($counter){
            return $counter;
        }
        return new \itsoneiota\cache\InMemoryCounter();
    }

    protected function tryCounterBuilder(callable $builder){
        try {
            $counter = $builder();
            if(is_object($counter) && is_a($counter, '\itsoneiota\cache\Counter')){
                return $counter;
            }
        } catch (\Exception $e) {
            $this->logError('Failed to build counter. ' . $e->getMessage());
        }
        return NULL;
    }

    public function buildMonitor(){
        $counter = $this->buildCounter();
		$timeProvider = NULL !== $this->timeProvider ? $this->timeProvider : new SystemTimeProvider();
		return new CircuitMonitor($this->serviceName, $counter, $timeProvider, $this->samplePeriod);
    }

    protected function configureBreaker(CircuitBreaker $breaker){
        $configKeys = [
			'enabled',
			'percentageFailureThreshold',
			'minimumRequestsBeforeTrigger',
			'probabilisticDynamics',
			'recoveryFactor'
		];
		foreach ($configKeys as $key) {
			if (array_key_exists($key, $this->config)) {
				$method = 'set'. ucfirst($key);
                $value = $this->config[$key];
				$breaker->$method($value);
			}
		}
    }

    protected function getRandomNumberGenerator(){
        if ($this->random) {
            return $this->random;
        }
        return new random\Rand();
    }

    public function build(){
        $monitor = $this->buildMonitor();
        $random = $this->getRandomNumberGenerator();
		$cb = new CircuitBreaker($monitor, $random);
        if($this->stats){
          $cb->setStatsCollector($this->stats, "circuitbreaker.{$this->serviceName}");
        }
        $this->configureBreaker($cb);

        return $cb;
    }

}
