<?php

namespace Tideways\Profiler;

class NetworkBackendTest extends \PHPUnit_Framework_TestCase
{
    public function testInavailableSocket()
    {
        $backend = new NetworkBackend("/tmp/unknown_profiler.sock");
        $backend->socketStore(array("foo" => "bar"));
    }

    public function testUnknownUdpPort()
    {
        $backend = new NetworkBackend(null, "udp://127.0.0.1:17423");
        $backend->udpStore(array(
            "foo" => "bar"
        ));
    }
}
