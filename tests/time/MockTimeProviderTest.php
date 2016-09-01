<?php
namespace itsoneiota\circuitbreaker\time;
/**
 * Tests for MockTimeProvider.
 *
 **/
class MockTimeProviderTest extends \PHPUnit_Framework_TestCase {

	/**
	 * It should the time in its constructor.
	 * @test
	 */
	public function canReturnTime() {
		$now = 1472730947;
		$sut = new MockTimeProvider(1472730947);
		$this->assertEquals(1472730947, $sut->time());
		sleep(1);
		$this->assertEquals(1472730947, $sut->time());
	}

	/**
	 * It should set the time.
	 * @test
	 */
	public function canSetTime() {
		$now = 1472730947;
		$sut = new MockTimeProvider(1472730947);
		$this->assertEquals(1472730947, $sut->time());

		$sut->set(1472730949);
		$this->assertEquals(1472730949, $sut->time());

		$sut->advance(3);
		$this->assertEquals(1472730952, $sut->time());
	}

}
