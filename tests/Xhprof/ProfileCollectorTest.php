<?php

namespace Xhprof;

use Phake;

class ProfileCollectorTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (!extension_loaded('xhprof')) {
            $this->markTestSkipped('xhprof not installed.');
        }
    }

    /**
     * @test
     */
    public function it_starts_stops_profile_and_delegates_to_backend()
    {
        $backend = \Phake::mock('Xhprof\Backend');
        $starter = \Phake::mock('Xhprof\StartDecision');

        \Phake::when($starter)->shouldProfile()->thenReturn(true);

        $collector = new ProfileCollector($backend, $starter);
        $collector->start();

        $collector->stop("dummyop");

        \Phake::verify($backend)->store(\Phake::anyParameters());
    }
}
