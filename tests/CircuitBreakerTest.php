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

}
