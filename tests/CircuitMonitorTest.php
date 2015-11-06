<?php
namespace itsoneiota\circuitbreaker;
/**
 * Tests for CircuitMonitor.
 *
 **/
class CircuitMonitorTest extends \PHPUnit_Framework_TestCase {

	protected $sut;

	public function setUp() {
		$this->cache = new \itsoneiota\cache\MockCache();
		$this->startTime = 1407424500;
		$this->timeProvider = new time\MockTimeProvider($this->startTime);
		$this->sut = new CircuitMonitor('myService', $this->cache, $this->timeProvider);
	}

	public function registerEvents(array $events){
		foreach ($events as $time => $event) {
			$this->timeProvider->set($time);
			$this->sut->registerEvent($event);
		}
	}

	/**
	 * It should still calculate if no requests are made.
	 * @test
	 */
	public function canCalculateFailureRateGivenNoInput() {
		$results = $this->sut->getResultsForPreviousPeriod();

        $this->assertEquals(0, $results['successes']);
        $this->assertEquals(0, $results['failures']);
        $this->assertEquals(0, $results['rejections']);
        $this->assertEquals(0, $results['totalRequests']);
        $this->assertEquals(0, $results['failureRate']);
        $this->assertEquals(100, $results['throttle']);
	}

	/**
	 * It should detect a failure rate and open the switch.
	 * @test
	 */
	public function canDetectFailureRate() {
        $this->registerEvents([
			1 => CircuitMonitor::EVENT_FAILURE,
			2 => CircuitMonitor::EVENT_FAILURE,
			3 => CircuitMonitor::EVENT_FAILURE,
			4 => CircuitMonitor::EVENT_FAILURE,
			5 => CircuitMonitor::EVENT_FAILURE,
			6 => CircuitMonitor::EVENT_FAILURE,
			7 => CircuitMonitor::EVENT_FAILURE,
			8 => CircuitMonitor::EVENT_SUCCESS,
			9 => CircuitMonitor::EVENT_SUCCESS,
			10 => CircuitMonitor::EVENT_SUCCESS
		]);

		$this->timeProvider->set(60);
		$results = $this->sut->getResultsForPreviousPeriod();

        $this->assertEquals(3, $results['successes']);
        $this->assertEquals(7, $results['failures']);
        $this->assertEquals(0, $results['rejections']);
        $this->assertEquals(10, $results['totalRequests']);
        $this->assertEquals(70, $results['failureRate']);
        $this->assertEquals(100, $results['throttle']);
	}

	/**
	 * If requests are rejected by the breaker, they shouldn't count as failures.
	 * @test
	 */
	public function canExcludeRejectionsFromFailureRate() {
        $this->registerEvents([
			1 => CircuitMonitor::EVENT_FAILURE,
			2 => CircuitMonitor::EVENT_FAILURE,
			3 => CircuitMonitor::EVENT_FAILURE,
			4 => CircuitMonitor::EVENT_FAILURE,
			5 => CircuitMonitor::EVENT_SUCCESS,
			6 => CircuitMonitor::EVENT_REJECTION,
            7 => CircuitMonitor::EVENT_REJECTION,
            8 => CircuitMonitor::EVENT_REJECTION,
            9 => CircuitMonitor::EVENT_REJECTION,
            10 => CircuitMonitor::EVENT_REJECTION
		]);

        $this->timeProvider->set(60);
		$results = $this->sut->getResultsForPreviousPeriod();

        $this->assertEquals(1, $results['successes']);
        $this->assertEquals(4, $results['failures']);
        $this->assertEquals(5, $results['rejections']);
        $this->assertEquals(5, $results['totalRequests']);
        $this->assertEquals(80, $results['failureRate']);
        $this->assertEquals(50, $results['throttle']);
	}

	/**
	 * If cache returns an int as a string, make sure it's cast.
	 * @test
	 */
	public function canHandleStringsReturnedFromCache() {
        $this->registerEvents([
			1 => CircuitMonitor::EVENT_FAILURE,
			2 => CircuitMonitor::EVENT_FAILURE,
			3 => CircuitMonitor::EVENT_FAILURE,
			4 => CircuitMonitor::EVENT_FAILURE,
			5 => CircuitMonitor::EVENT_SUCCESS,
			6 => CircuitMonitor::EVENT_REJECTION,
            7 => CircuitMonitor::EVENT_REJECTION,
            8 => CircuitMonitor::EVENT_REJECTION,
            9 => CircuitMonitor::EVENT_REJECTION,
            10 => CircuitMonitor::EVENT_REJECTION
		]);

        $this->timeProvider->set(60);

		foreach ($this->cache->getContents() as $key => $value) {
			$this->cache->set($key, (string)$value);
		}


		$results = $this->sut->getResultsForPreviousPeriod();
        $this->assertSame(1, $results['successes']);
        $this->assertSame(4, $results['failures']);
        $this->assertSame(5, $results['rejections']);
        $this->assertSame(5, $results['totalRequests']);

		$failureRate = $results['failureRate'];
        $this->assertEquals(80, $failureRate);
		$this->assertTrue(is_numeric($failureRate));

		$throttle = $results['throttle'];
        $this->assertEquals(50, $throttle);
		$this->assertTrue(is_numeric($throttle));
	}

}
