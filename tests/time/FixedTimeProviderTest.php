<?php
namespace itsoneiota\circuitbreaker\time;
/**
 * Tests for FixedTimeProvider.
 *
 **/
class FixedTimeProviderTest extends \PHPUnit_Framework_TestCase {

	/**
	 * It should the time in its constructor.
	 * @test
	 */
	public function canReturnFixedTime() {
		$now = 1472730947;
		$sut = new FixedTimeProvider(1472730947);
		$this->assertEquals(1472730947, $sut->time());
		$this->assertEquals(1472730947, $sut->time());
	}

}
