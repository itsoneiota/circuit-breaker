<?php
namespace itsoneiota\circuitbreaker;
use itsoneiota\circuitbreaker\random\MockRandomNumberGenerator;
/**
 * Tests for CircuitBreaker in combination with CircuitMonitor,
 * constructed using the builder.
 *
 **/
class CompleteCircuitBreakerTest extends \PHPUnit_Framework_TestCase {

	protected $sut;
	protected $cache;

	public function setUp() {
		$this->cache = new \itsoneiota\cache\MockCounter();
		$this->startTime = 1407424500;
		$this->timeProvider = new time\MockTimeProvider($this->startTime);
		$this->random = new MockRandomNumberGenerator();
		$this->sut = CircuitBreakerBuilder::create('myService')
			->withCache($this->cache)
			->withTimeProvider($this->timeProvider)
			->withRandomNumberGenerator($this->random)
			->build();
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
	 * It should be disabled by default.
	 * @test
	 */
	public function canDisableButStillRecordMetrics() {
		$this->sut = CircuitBreakerBuilder::create('myService')->disabled()->withCache($this->cache)->withTimeProvider($this->timeProvider)->build();
		$this->assertTrue($this->sut->isClosed());

		$this->sut->registerFailure();

		$this->timeProvider->set($this->startTime + 30);
		$this->sut->registerSuccess();

		$this->timeProvider->set($this->startTime + 59);
		$this->sut->registerFailure();

		// Next sample period. Previous period should be complete, with 2/3 failures.
		$this->timeProvider->set($this->startTime + 60);
		$this->assertTrue($this->sut->isClosed());

		$enabledClone = CircuitBreakerBuilder::create('myService')->enabled()->withCache($this->cache)->withTimeProvider($this->timeProvider)->build();
		$this->assertFalse($enabledClone->isClosed());
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
		$config = [
			'minimumRequestsBeforeTrigger'=>5
		];
		$this->sut = CircuitBreakerBuilder::create('myService')->withConfig($config)->withCache($this->cache)->withTimeProvider($this->timeProvider)->build();

		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->set(0);
		$this->sut->registerFailure();
		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->set(30);
		$this->sut->registerSuccess();
		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->set(58);
		$this->sut->registerFailure();
		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->set(59);
		$this->sut->registerFailure();
		$this->assertTrue($this->sut->isClosed());

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
		$this->sut->setProbabilisticDynamics(TRUE);

		$this->assertTrue($this->sut->isClosed());

		$this->timeProvider->set(0);
		$this->sut->registerFailure();

		$this->timeProvider->set(1);
		$this->sut->registerFailure();

		$this->timeProvider->set(30);
		$this->sut->registerSuccess();

		$this->timeProvider->set(59);
		$this->sut->registerFailure();

		// Next sample period. Previous period should be complete, with 3/4 failures.
		$this->timeProvider->set(60);
		$this->assertThrottle(25);
	}

	/**
	 * It should not snap to 100% closed after a period of limited throughput.
	 * @test
	 */
	public function canLimitClosingDynamics() {
		$this->sut->setProbabilisticDynamics(TRUE);
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
		$this->assertThrottle(20);

		// Throttle shouldn't exceed 40 in the next period, because it's 20% * 2.
		$this->timeProvider->set(130);
		$this->assertThrottle(40);

		// etc.
		$this->timeProvider->set(190);
		$this->assertThrottle(80);

		$this->timeProvider->set(250);
		$this->assertThrottle(100);
	}

	/**
	 * It should alter the recovery dynamics.
	 * @test
	 */
	public function canAlterRecoveryDynamics() {
		$this->sut->setProbabilisticDynamics(TRUE);
		$this->sut->setRecoveryFactor(4);
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
		$this->assertThrottle(20);

		// Throttle shouldn't exceed 80 in the next period, because it's 20% * 4.
		$this->timeProvider->set(130);
		$this->assertThrottle(80);

		$this->timeProvider->set(190);
		$this->assertThrottle(100);
	}

	/**
	 * It should alter the recovery dynamics.
	 * @test
	 * @large
	 */
	public function canIgnoreMinorErrorsWithRealisticStats() {
		$this->sut->setProbabilisticDynamics(TRUE);
		$periods = 10;
		for ($p=0; $p < $periods; $p++) {
			$time = ($p * 60) + 2;
			$this->timeProvider->set($time);
			$hundredsOfRequests = floor(rand(2,10));
			for ($h=0; $h < $hundredsOfRequests; $h++) {
				$failureRate = floor(rand(0,48));
				for ($i=0; $i < 100; $i++) {
					$this->assertTrue($this->sut->isClosed());
					if ($i < $failureRate) {
						$this->sut->registerFailure();
					}else{
						$this->sut->registerSuccess();
					}
				}
			}
		}
	}

	/**
	 * Make 100 requests and check that the throttle rate is about right.
	 */
	protected function assertThrottle($rate){
		$timesClosed = 0;
		for ($i=0; $i < 100; $i++) {
			if ($this->sut->isClosed()) {
				$this->sut->registerSuccess();
				$timesClosed++;
			}else{
				$this->sut->registerRejection();
			}
		}
		$this->assertEquals($rate, $timesClosed, "Closed $timesClosed times. Expected $rate");
	}

}
