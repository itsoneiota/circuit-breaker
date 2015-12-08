<?php
namespace itsoneiota\circuitbreaker\random;
/**
 * Tests for MockRandomNumberGenerator.
 *
 **/
class MockRandomNumberGeneratorTest extends \PHPUnit_Framework_TestCase {

	protected $sut;
	public function setUp() {
		$this->sut = new MockRandomNumberGenerator();
	}

	/**
	 * It should return every number from min (inclusive) to max (inclusive).
	 * @test
	 */
	public function canReturnFullRangeOfValues() {
		$min = 10;
        $max = 103;
        for ($i=$min; $i <= $max; $i++) {
            $this->assertEquals($i, $this->sut->rand($min,$max));
        }
        // Can wrap around.
        for ($i=$min; $i <= $max; $i++) {
            $this->assertEquals($i, $this->sut->rand($min,$max));
        }
	}

    /**
	 * It should return every number from 0 (inclusive) to 100 (inclusive).
	 * @test
	 */
	public function canDoOneHundred() {
		$min = 0;
        $max = 100;
        for ($i=0; $i <= 100; $i++) {
            $this->assertEquals($i, $this->sut->rand(0,100));
        }
	}


}
