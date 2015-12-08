<?php
namespace itsoneiota\circuitbreaker;
use itsoneiota\circuitbreaker\random\MockRandomNumberGenerator;
/**
 * Tests for CircuitBreaker.
 *
 **/
class CircuitBreakerTest extends \PHPUnit_Framework_TestCase {

	protected $sut;
	protected $cache;

	public function setUp() {
		$this->circuitMonitor = new MockCircuitMonitor();
		$this->random = new MockRandomNumberGenerator();
		$this->sut = new CircuitBreaker($this->circuitMonitor, $this->random);
	}

	/**
	 * It should stay closed if no requests are made.
	 * @test
	 */
	public function canStayClosedGivenNoInput() {
		// Mock CM returns no requests by default.
		$this->assertTrue($this->sut->isClosed());
	}

	/**
	 * It should register events with the CM.
	 * @test
	 */
	public function canRegisterEvents() {
		$this->sut->registerSuccess();
		$this->sut->registerSuccess();
		$this->sut->registerSuccess();

		$this->sut->registerFailure();
		$this->sut->registerFailure();
		$this->sut->registerFailure();
		$this->sut->registerFailure();
		$this->sut->registerFailure();

		$this->sut->registerRejection();
		$this->sut->registerRejection();

		$this->assertEquals(3, $this->circuitMonitor->events[CircuitMonitor::EVENT_SUCCESS]);
		$this->assertEquals(5, $this->circuitMonitor->events[CircuitMonitor::EVENT_FAILURE]);
		$this->assertEquals(2, $this->circuitMonitor->events[CircuitMonitor::EVENT_REJECTION]);
	}

	/**
	 * It should register events with the CM, even when disabled.
	 * @test
	 */
	public function canRegisterEventsWhenDisabled() {
		$this->sut->setEnabled(FALSE);

		$this->sut->registerSuccess();
		$this->sut->registerSuccess();
		$this->sut->registerSuccess();

		$this->sut->registerFailure();
		$this->sut->registerFailure();
		$this->sut->registerFailure();
		$this->sut->registerFailure();
		$this->sut->registerFailure();

		$this->sut->registerRejection();
		$this->sut->registerRejection();

		$this->assertEquals(3, $this->circuitMonitor->events[CircuitMonitor::EVENT_SUCCESS]);
		$this->assertEquals(5, $this->circuitMonitor->events[CircuitMonitor::EVENT_FAILURE]);
		$this->assertEquals(2, $this->circuitMonitor->events[CircuitMonitor::EVENT_REJECTION]);
	}

	/**
	 * It should detect a failure rate and open the switch.
	 * @test
	 */
	public function canOpenWithFailure() {
		$this->assertTrue($this->sut->isClosed());

		$this->circuitMonitor->previousResults = [
			'successes'=>4,
			'failures'=>6,
			'rejections'=>0,
			'totalRequests'=>10,
			'failureRate'=>60,
			'throttle'=>100
		];

		$this->assertFalse($this->sut->isClosed());
	}

	/**
	 * It should be disabled by default.
	 * @test
	 */
	public function canStayClosedWhenDisabled() {
		$this->circuitMonitor->previousResults = [
			'successes'=>4,
			'failures'=>6,
			'rejections'=>0,
			'totalRequests'=>10,
			'failureRate'=>60,
			'throttle'=>100
		];
		$this->assertFalse($this->sut->isClosed());
		$this->sut->setEnabled(FALSE);
		$this->assertTrue($this->sut->isClosed());
	}

	/**
	 * It should stay closed if the minimum request threshold hasn't been met.
	 * @test
	 */
	public function canStayClosedIfDefaultMinimumRequestThresholdNotMet() {
		$this->circuitMonitor->previousResults = [
			'successes'=>0,
			'failures'=>2,
			'rejections'=>0,
			'totalRequests'=>2,
			'failureRate'=>100,
			'throttle'=>100
		];
		$this->assertTrue($this->sut->isClosed());
	}

	/**
	 * It should stay closed if the minimum request threshold hasn't been met.
	 * @test
	 */
	public function canStayClosedIfCustomMinimumRequestThresholdNotMet() {
		$this->sut->setMinimumRequestsBeforeTrigger(5);
		$this->circuitMonitor->previousResults = [
			'successes'=>0,
			'failures'=>4,
			'rejections'=>0,
			'totalRequests'=>4,
			'failureRate'=>100,
			'throttle'=>100
		];
		$this->assertTrue($this->sut->isClosed());
		$this->circuitMonitor->previousResults = [
			'successes'=>0,
			'failures'=>5,
			'rejections'=>0,
			'totalRequests'=>5,
			'failureRate'=>100,
			'throttle'=>100
		];
		$this->assertFalse($this->sut->isClosed());
	}

	/**
	 * It should close with a probability roughly equal to the success rate in the previous period.
	 * @test
	 */
	public function canCloseProbably() {
		$this->sut->setProbabilisticDynamics(TRUE);
		$this->assertTrue($this->sut->isClosed());

		$this->circuitMonitor->previousResults = [
			'successes'=>1,
			'failures'=>2,
			'rejections'=>0,
			'totalRequests'=>3,
			'failureRate'=>67,
			'throttle'=>100
		];

		$this->assertThrottle(33);
	}

	/**
	 * It should handle floats returned from throttle.
	 * @test
	 */
	public function canHandleFloatThrottle() {
		$this->sut->setProbabilisticDynamics(TRUE);

		$this->circuitMonitor->previousResults = [
			'successes'=>90,
			'failures'=>10,
			'rejections'=>0,
			'totalRequests'=>100,
			'failureRate'=>10.87687685,
			'throttle'=>99.9
		];

		$this->assertTrue($this->sut->isClosed());
		$this->assertThrottle(100);
	}

	/**
	 * It should not snap to 100% closed after a period of limited throughput.
	 * @test
	 */
	public function canLimitClosingDynamics() {
		$this->sut->setProbabilisticDynamics(TRUE);
		$this->assertTrue($this->sut->isClosed());

		$this->circuitMonitor->previousResults = [
			'successes'=>20,
			'failures'=>80,
			'rejections'=>0,
			'totalRequests'=>100,
			'failureRate'=>80,
			'throttle'=>100
		];
		$this->assertThrottle(20);

		$this->circuitMonitor->previousResults = [
			'successes'=>20,
			'failures'=>0,
			'rejections'=>80,
			'totalRequests'=>20,
			'failureRate'=>0,
			'throttle'=>20
		];

		// Throttle shouldn't exceed 40 in the next period, because it's 20% * 2.
		$this->assertThrottle(40);

		$this->circuitMonitor->previousResults = [
			'successes'=>40,
			'failures'=>0,
			'rejections'=>60,
			'totalRequests'=>40,
			'failureRate'=>0,
			'throttle'=>40
		];

		$this->assertThrottle(80);

		$this->circuitMonitor->previousResults = [
			'successes'=>80,
			'failures'=>0,
			'rejections'=>20,
			'totalRequests'=>80,
			'failureRate'=>0,
			'throttle'=>80
		];

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

		$this->circuitMonitor->previousResults = [
			'successes'=>20,
			'failures'=>80,
			'rejections'=>0,
			'totalRequests'=>100,
			'failureRate'=>80,
			'throttle'=>100
		];

		$this->assertThrottle(20);

		$this->circuitMonitor->previousResults = [
			'successes'=>20,
			'failures'=>0,
			'rejections'=>80,
			'totalRequests'=>20,
			'failureRate'=>0,
			'throttle'=>20
		];

		// Throttle shouldn't exceed 80 in the next period, because it's 20% * 4.
		$this->assertThrottle(80);

		$this->circuitMonitor->previousResults = [
			'successes'=>80,
			'failures'=>0,
			'rejections'=>20,
			'totalRequests'=>80,
			'failureRate'=>0,
			'throttle'=>80
		];

		$this->assertThrottle(100);
	}

	/**
	 * It should recover steadily after a 100% failure rate.
	 * @test
	 */
	public function canRecoverFromAbsoluteFailure() {
		$this->sut->setProbabilisticDynamics(TRUE);
		$this->sut->setRecoveryFactor(2);
		$this->assertTrue($this->sut->isClosed());

		$this->circuitMonitor->previousResults = [
			'successes'=>0,
			'failures'=>100,
			'rejections'=>0,
			'totalRequests'=>100,
			'failureRate'=>100,
			'throttle'=>100
		];

		$this->assertThrottle(0);

		$this->circuitMonitor->previousResults = [
			'successes'=>0,
			'failures'=>0,
			'rejections'=>100,
			'totalRequests'=>0,
			'failureRate'=>0,
			'throttle'=>0
		];

		// The throttle needs to step up, since multiplying by 0 won't work.
		$this->assertThrottle(10);

		$this->circuitMonitor->previousResults = [
			'successes'=>20,
			'failures'=>0,
			'rejections'=>80,
			'totalRequests'=>20,
			'failureRate'=>0,
			'throttle'=>20
		];

		$this->assertThrottle(40);
	}

	/**
	 * Make 100 requests and check that the throttle rate is correct.
	 */
	protected function assertThrottle($rate){
		$timesClosed = 0;
		for ($i=0; $i < 100; $i++) {
			if ($this->sut->isClosed()) {
				$timesClosed++;
			}
		}
		$this->assertEquals($rate, $timesClosed, "Closed $timesClosed times. Expected $rate");
	}

}
