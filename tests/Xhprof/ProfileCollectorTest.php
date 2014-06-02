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

        \Phake::verify($backend)->storeProfile(\Phake::anyParameters());
    }

    /**
     * @test
     */
    public function it_records_measurement_when_not_profiling()
    {
        $backend = \Phake::mock('Xhprof\Backend');
        $starter = \Phake::mock('Xhprof\StartDecision');

        \Phake::when($starter)->shouldProfile()->thenReturn(false);

        $collector = new ProfileCollector($backend, $starter);
        $collector->start();

        $collector->stop("dummyop");

        \Phake::verify($backend)->storeMeasurement(\Phake::anyParameters());
    }

    /**
     * @test
     */
    public function it_collects_custom_measurements()
    {
        $backend = \Phake::mock('Xhprof\Backend');
        $starter = \Phake::mock('Xhprof\StartDecision');

        \Phake::when($starter)->shouldProfile()->thenReturn(true);

        $collector = new ProfileCollector($backend, $starter);
        $collector->start();
        $collector->addCustomMeasurement('SELECT 100', 100, 'main()');
        $collector->addCustomMeasurement('SELECT 100', 50, 'main()');

        $collector->stop("dummyop");

        \Phake::verify($backend)->storeProfile('dummyop', $this->isType('array'), array('main()' => array('SELECT 100' => array('wt' => 150, 'ct' => 2))));
    }
}
