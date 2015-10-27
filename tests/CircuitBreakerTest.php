<?php
namespace itsoneiota\circuitbreaker;
/**
 * Tests for CircuitBreaker.
 *
 **/
class CircuitBreakerTest extends \PHPUnit_Framework_TestCase {

	protected $sut;
	protected $cache;

	public function setUp() {
		$this->cache = new \itsoneiota\cache\MockCache();
		$this->startTime = 1407424500;
		$this->timeProvider = new time\MockTimeProvider($this->startTime);
		$this->sut = new CircuitBreaker('myService', $this->cache, $this->timeProvider);
	}

	public function registerRequests(array $requests){
		foreach ($requests as $time => $success) {
			$this->timeProvider->set($time);
			if ($success) {
				$this->sut->registerSuccess();
			}else{
				$this->sut->registerFailure();
			}
		}
	}

	/**
	 * It should stay closed if no requests are made.
	 * @test
	 */
	public function canStayClosedGivenNoInput() {
		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->advance(60); // Next minute.
		$this->assertTrue($this->sut->isClosed());
	}

	/**
	 * It should detect a failure rate and open the switch.
	 * @test
	 */
	public function canDetectFailureRate() {
		$this->assertTrue($this->sut->isClosed());

		$this->sut->registerFailure();

		$this->timeProvider->set($this->startTime + 30);
		$this->sut->registerSuccess();

		$this->timeProvider->set($this->startTime + 59);
		$this->sut->registerFailure();

		// Next sample period. Previous period should be complete, with 2/3 failures.
		$this->timeProvider->set($this->startTime + 60);
		$this->assertFalse($this->sut->isClosed());
	}

	/**
	 * It should stay closed if the minimum request threshold hasn't been met.
	 * @test
	 */
	public function canStayClosedIfDefaultMinimumRequestThresholdNotMet() {
		$currentTime = 1407424500; // Start of a minute.

		$this->assertTrue($this->sut->isClosed());

		$this->sut->registerFailure();

		$this->timeProvider->set($this->startTime + 59);
		$this->sut->registerFailure();

		$this->timeProvider->set($this->startTime + 60);
		$this->assertTrue($this->sut->isClosed());
	}

	/**
	 * It should stay closed if the minimum request threshold hasn't been met.
	 * @test
	 */
	public function canStayClosedIfCustomMinimumRequestThresholdNotMet() {
		$this->sut = new CircuitBreaker('myService', $this->cache, $this->timeProvider, 60, 50, 5);

		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->set(0);
		$this->sut->registerFailure();

		$this->timeProvider->set(30);
		$this->sut->registerSuccess();

		$this->timeProvider->set(58);
		$this->sut->registerFailure();

		$this->timeProvider->set(59);
		$this->sut->registerFailure();

		$this->timeProvider->set(60);
		$this->assertTrue($this->sut->isClosed());

		// Cheekily add another request to the previous minute.
		$this->timeProvider->set(57);
		$this->sut->registerFailure();
		$this->timeProvider->set(60);
		$this->assertFalse($this->sut->isClosed());
	}

	/**
	 * It should close with a probability roughly equal to the success rate in the previous period.
	 * @test
	 */
	public function canCloseProbably() {
		$this->sut->setDynamics(CircuitBreaker::DYNAMICS_PROBABILISTIC);

		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->set(0);
		$this->sut->registerFailure();

		$this->timeProvider->set(30);
		$this->sut->registerSuccess();

		$this->timeProvider->set(59);
		$this->sut->registerFailure();

		// Next sample period. Previous period should be complete, with 2/3 failures.
		$this->timeProvider->set(60);
		$successes = 0;
		$failures = 0;
		for ($i=0; $i < 60; $i++) {
			$this->timeProvider->set(60+$i);
			if ($this->sut->isClosed()) {
				$successes++;
			}else{
				$failures++;
			}
		}

		$this->assertTrue($failures>30);
		$this->assertTrue($failures<50);
		$this->assertTrue($successes>10);
		$this->assertTrue($successes<30);
	}

	/**
	 * It should not snap to 100% closed after a period of limited throughput.
	 * @test
	 */
	public function canLimitClosingDynamics() {
		// var_dump($this->sut->getResultsForPreviousPeriod());
		$this->sut->setDynamics(CircuitBreaker::DYNAMICS_PROBABILISTIC);
		$this->assertTrue($this->sut->isClosed());

		$this->registerRequests([
			1 => FALSE,
			2 => FALSE,
			3 => FALSE,
			4 => FALSE,
			5 => FALSE,
			6 => FALSE,
			7 => FALSE,
			8 => FALSE,
			9 => TRUE,
			10 => TRUE
		]);

		// Next sample period. Previous period should be complete, with 80% failures.
		$this->timeProvider->set(65);
		$this->assertApproximateThrottle(20);

		// Throttle shouldn't exceed 40 in the next period, because it's 20% * 2.
		$this->timeProvider->set(130);
		$this->assertApproximateThrottle(40);

		// etc.
		$this->timeProvider->set(190);
		$this->assertApproximateThrottle(80);

		$this->timeProvider->set(250);
		$this->assertApproximateThrottle(100);
	}

	/**
	 * Make 100 requests and check that the throttle rate is about right.
	 */
	protected function assertApproximateThrottle($rate){
		$timesClosed = 0;
		for ($i=0; $i < 100; $i++) {
			if ($this->sut->isClosed()) {
				$timesClosed++;
				$this->sut->registerSuccess();
			}else{
				$this->sut->registerRejection();
			}
		}
		$this->assertTrue(abs($rate-$timesClosed) < ($rate/2), "Closed $timesClosed times. Expected ~$rate");
	}

}
