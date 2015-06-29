<?php

namespace Tideways\Traces;

class PhpSpanTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        PhpSpan::clear();
    }

    /**
     * @test
     */
    public function it_has_id()
    {
        $span = PhpSpan::createSpan('app');

        $this->assertInternalType('integer', $span->getId());
        $this->assertTrue(0 < $span->getId());

        $this->assertEquals($span->getId(), $span->getId(), "Generates ID only once.");
    }

    /**
     * @test
     */
    public function it_records_name()
    {
        PhpSpan::createSpan('app');

        $data = PhpSpan::getSpans();

        $this->assertCount(1, $data);
        $this->assertEquals('app', $data[0][PhpSpan::NAME]);
    }

    /**
     * @test
     */
    public function it_records_timings()
    {
        $span = PhpSpan::createSpan(1, 'app');
        $span->startTimer();
        $span->stopTimer();

        $data = PhpSpan::getSpans();

        $this->assertCount(1, $data);
        $this->assertContainsOnly('int', $data[0][PhpSpan::STARTS]);
        $this->assertContainsOnly('int', $data[0][PhpSpan::STOPS]);
    }

    /**
     * @test
     */
    public function it_records_annotations()
    {
        $annotations = array('foo' => 'bar', 'bar' => 'baz');

        $span = PhpSpan::createSpan(1, 'app');
        $span->annotate($annotations);

        $data = PhpSpan::getSpans();

        $this->assertCount(1, $data);
        $this->assertEquals($annotations, $data[0][PhpSpan::ANNOTATIONS]);
    }
}
