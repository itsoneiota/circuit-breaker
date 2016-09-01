<?php
namespace itsoneiota\circuitbreaker;
/**
 * Tests for CircuitBreaker.
 *
 **/
class CircuitBreakerBuilderTest extends \PHPUnit_Framework_TestCase {

	protected $sut;

	public function setUp() {
		$this->sut = new CircuitBreakerBuilder('myService');
	}

    /**
     * It should log build failures to the given logger.
     * @test
     */
     public function canLogBuildFailures() {
        $badCacheBuilder = function(){
            throw new \Exception('Failed to build cache.');
        };

        $logger = $this->getMockBuilder('\Psr\Log\LoggerInterface')->disableOriginalConstructor()->getMock();
        $this->sut->withLogger($logger)->withCacheBuilder($badCacheBuilder);

        $logger->expects($this->once())->method('critical');

        $breaker = $this->sut->build();
     }

	 /**
	  * It should configure the breaker.
	  * @test
	  */
	  public function canConfigure() {
		  $config = [
			  	'percentageFailureThreshold' => 75,
			  	'enabled' => true,
				'samplePeriod' => 60,
			    'minimumRequestsBeforeTrigger' => 3,
			    'probabilisticDynamics' => true,
			    'recoveryFactor' => 2
		  ];
		  $cb = \itsoneiota\circuitbreaker\CircuitBreakerBuilder::create('myService')
			->withConfig($config)
			->build();
	  }
}
