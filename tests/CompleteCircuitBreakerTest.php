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
		$this->cache = new \itsoneiota\cache\MockCache();
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

	public function getRealisticStats(){
		return json_decode(
		'[
		    {
		      "periodStart": 1449744480,
		      "periodEnd": 1449744509,
		      "successes": 350,
		      "failures": 5,
		      "rejections": 0,
		      "failureRate": 1,
		      "throttle": 100,
		      "period": 1449744505,
		      "requestsMade": 355,
		      "traffic": 355
		    },
		    {
		      "periodStart": 1449744510,
		      "periodEnd": 1449744539,
		      "successes": 473,
		      "failures": 12,
		      "rejections": 0,
		      "failureRate": 2,
		      "throttle": 100,
		      "period": 1449744535,
		      "requestsMade": 485,
		      "traffic": 485
		    },
		    {
		      "periodStart": 1449744540,
		      "periodEnd": 1449744569,
		      "successes": 379,
		      "failures": 9,
		      "rejections": 0,
		      "failureRate": 2,
		      "throttle": 100,
		      "period": 1449744565,
		      "requestsMade": 388,
		      "traffic": 388
		    },
		    {
		      "periodStart": 1449744570,
		      "periodEnd": 1449744599,
		      "successes": 409,
		      "failures": 7,
		      "rejections": 0,
		      "failureRate": 2,
		      "throttle": 100,
		      "period": 1449744595,
		      "requestsMade": 416,
		      "traffic": 416
		    },
		    {
		      "periodStart": 1449744600,
		      "periodEnd": 1449744629,
		      "successes": 374,
		      "failures": 5,
		      "rejections": 0,
		      "failureRate": 1,
		      "throttle": 100,
		      "period": 1449744625,
		      "requestsMade": 379,
		      "traffic": 379
		    },
		    {
		      "periodStart": 1449744630,
		      "periodEnd": 1449744659,
		      "successes": 410,
		      "failures": 6,
		      "rejections": 0,
		      "failureRate": 1,
		      "throttle": 100,
		      "period": 1449744655,
		      "requestsMade": 416,
		      "traffic": 416
		    },
		    {
		      "periodStart": 1449744660,
		      "periodEnd": 1449744689,
		      "successes": 395,
		      "failures": 6,
		      "rejections": 0,
		      "failureRate": 1,
		      "throttle": 100,
		      "period": 1449744685,
		      "requestsMade": 401,
		      "traffic": 401
		    },
		    {
		      "periodStart": 1449744690,
		      "periodEnd": 1449744719,
		      "successes": 412,
		      "failures": 14,
		      "rejections": 0,
		      "failureRate": 3,
		      "throttle": 100,
		      "period": 1449744715,
		      "requestsMade": 426,
		      "traffic": 426
		    },
		    {
		      "periodStart": 1449744720,
		      "periodEnd": 1449744749,
		      "successes": 364,
		      "failures": 17,
		      "rejections": 0,
		      "failureRate": 4,
		      "throttle": 100,
		      "period": 1449744745,
		      "requestsMade": 381,
		      "traffic": 381
		    },
		    {
		      "periodStart": 1449744750,
		      "periodEnd": 1449744779,
		      "successes": 349,
		      "failures": 11,
		      "rejections": 0,
		      "failureRate": 3,
		      "throttle": 100,
		      "period": 1449744775,
		      "requestsMade": 360,
		      "traffic": 360
		    },
		    {
		      "periodStart": 1449744780,
		      "periodEnd": 1449744809,
		      "successes": 336,
		      "failures": 13,
		      "rejections": 0,
		      "failureRate": 4,
		      "throttle": 100,
		      "period": 1449744805,
		      "requestsMade": 349,
		      "traffic": 349
		    },
		    {
		      "periodStart": 1449744810,
		      "periodEnd": 1449744839,
		      "successes": 275,
		      "failures": 11,
		      "rejections": 0,
		      "failureRate": 4,
		      "throttle": 100,
		      "period": 1449744835,
		      "requestsMade": 286,
		      "traffic": 286
		    },
		    {
		      "periodStart": 1449744840,
		      "periodEnd": 1449744869,
		      "successes": 308,
		      "failures": 22,
		      "rejections": 0,
		      "failureRate": 7,
		      "throttle": 100,
		      "period": 1449744865,
		      "requestsMade": 330,
		      "traffic": 330
		    },
		    {
		      "periodStart": 1449744870,
		      "periodEnd": 1449744899,
		      "successes": 282,
		      "failures": 13,
		      "rejections": 0,
		      "failureRate": 4,
		      "throttle": 100,
		      "period": 1449744895,
		      "requestsMade": 295,
		      "traffic": 295
		    },
		    {
		      "periodStart": 1449744900,
		      "periodEnd": 1449744929,
		      "successes": 335,
		      "failures": 13,
		      "rejections": 0,
		      "failureRate": 4,
		      "throttle": 100,
		      "period": 1449744925,
		      "requestsMade": 348,
		      "traffic": 348
		    },
		    {
		      "periodStart": 1449744930,
		      "periodEnd": 1449744959,
		      "successes": 383,
		      "failures": 9,
		      "rejections": 0,
		      "failureRate": 2,
		      "throttle": 100,
		      "period": 1449744955,
		      "requestsMade": 392,
		      "traffic": 392
		    },
		    {
		      "periodStart": 1449744960,
		      "periodEnd": 1449744989,
		      "successes": 348,
		      "failures": 10,
		      "rejections": 0,
		      "failureRate": 3,
		      "throttle": 100,
		      "period": 1449744985,
		      "requestsMade": 358,
		      "traffic": 358
		    },
		    {
		      "periodStart": 1449744990,
		      "periodEnd": 1449745019,
		      "successes": 324,
		      "failures": 13,
		      "rejections": 3,
		      "failureRate": 4,
		      "throttle": 99,
		      "period": 1449745015,
		      "requestsMade": 337,
		      "traffic": 340
		    }
		  ]'
	);
	}

	/**
	 * It should alter the recovery dynamics.
	 * @test
	 */
	public function canIgnoreMinorErrorsWithRealisticStats() {
		$this->sut->setProbabilisticDynamics(TRUE);
		$periods = $this->getRealisticStats();
		foreach ($periods as $period) {
			$this->timeProvider->set($period->periodStart);
			$this->assertThrottle(100);
			for ($i=0; $i < $period->successes; $i++) {
				$this->sut->registerSuccess();
			}
			for ($i=0; $i < $period->failures; $i++) {
				$this->sut->registerFailure();
			}
		}
	}

	/**
	 * It should alter the recovery dynamics.
	 * @test
	 */
	public function canBehaveWhileBeingEnabled() {
		$this->sut->setProbabilisticDynamics(TRUE);
		$this->sut->setEnabled(FALSE);
		$periods = $this->getRealisticStats();
		foreach ($periods as $p => $period) {
			if ($p == 8) {
				$this->sut->setEnabled(TRUE);
			}
			$this->timeProvider->set($period->periodStart);
			$this->assertThrottle(100);
			for ($i=0; $i < $period->successes; $i++) {
				$this->sut->registerSuccess();
			}
			for ($i=0; $i < $period->failures; $i++) {
				$this->sut->registerFailure();
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
