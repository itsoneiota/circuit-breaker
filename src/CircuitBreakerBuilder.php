<?php
namespace itsoneiota\circuitbreaker;
use \itsoneiota\cache\Cache;
use \itsoneiota\circuitbreaker\time\TimeProvider;
use \itsoneiota\circuitbreaker\time\SystemTimeProvider;

class CircuitBreakerBuilder {

    protected $serviceName;
    protected $cache;
    protected $cacheBuilder;
    protected $memcachedHost;
    protected $memcachedPort;
    protected $timeProvider;
    protected $samplePeriod = CircuitMonitor::SAMPLE_PERIOD_DEFAULT;
    protected $config = [];

    public static function create($serviceName){
        return new CircuitBreakerBuilder($serviceName);
    }

    public function __construct($serviceName){
        $this->serviceName = $serviceName;
    }

    public function withCache(Cache $cache=NULL){
        $this->cache = $cache;
        return $this;
    }

    public function withCacheBuilder(callable $cacheBuilder){
        $this->cacheBuilder = $cacheBuilder;
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

    protected function buildMemcached(){
        $memcached = new \Memcached();
        $memcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, TRUE);
    	if(!$memcached->addServer($this->memcachedHost,$this->memcachedPort)){
    		$message = $memcached->getResultMessage()."(".$memcached->getResultCode().")";
    		throw new InvalidArgumentException("Can't connect to memcached: ".$host.", message: $port");
    	}

        return $memcached;
    }

    protected function buildCacheFromMemcachedServer(){
        $mc = $this->buildMemcached();
        $keyPrefix = 'CircuitBreaker-'.$this->serviceName;
        $expiration = $this->samplePeriod * 100;
        $cache = new \itsoneiota\cache\Cache($mc, $keyPrefix, $expiration);
        return $cache;
    }

    protected function buildCache(){
        if(NULL !== $this->cache){
            return $this->cache;
        }
        $cache = NULL;
        if(NULL !== $this->cacheBuilder){
            $cache = $this->tryCacheBuilder($this->cacheBuilder);
        }
        if(NULL === $cache && NULL !== $this->memcachedHost){
            $cache = $this->tryCacheBuilder([$this,'buildCacheFromMemcachedServer']);
        }
        if(NULL !== $cache){
            return new \itsoneiota\cache\InMemoryCacheFront($cache);
        }
        return new \itsoneiota\cache\InMemoryCache();
    }

    protected function tryCacheBuilder(callable $builder){
        try {
            $cache = $builder();
            if(is_object($cache) && is_a($cache, '\itsoneiota\cache\Cache')){
                return $cache;
            }
        } catch (\Exception $e) {
        }
        return NULL;
    }

    public function buildMonitor(){
        $cache = $this->buildCache();
		$timeProvider = NULL !== $this->timeProvider ? $this->timeProvider : new SystemTimeProvider();
		return new CircuitMonitor($this->serviceName, $cache, $timeProvider, $this->samplePeriod);
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

    public function build(){
        $monitor = $this->buildMonitor();
		$cb = new CircuitBreaker($monitor);
        $this->configureBreaker($cb);

        return $cb;
    }

}
