<?php

namespace Xhprof;

use org\bovigo\vfs\vfsStream;

class XhuiFileBackendTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_stores_profile_as_gzcompressed_json()
    {
        $root = vfsStream::setup('profiles');

        $backend = new XhuiFileBackend(vfsStream::url('profiles'));
        $backend->store(array("main()" => array('ct' => 1, 'wt' => 2)), "operation", "hostname", "1.2.3.4");

        $files = scandir(vfsStream::url('profiles'));
        $this->assertCount(1, $files);
        $data = json_decode(gzuncompress(file_get_contents(vfsStream::url('profiles') . '/' . $files[0])), true);

        $this->assertEquals(array("main()" => array('ct' => 1, 'wt' => 2)), $data['profile']);
        $this->assertEquals('operation', $data['operation']);
        $this->assertEquals(array('hostname' => 'hostname', 'ip' => '1.2.3.4'), $data['server']);
    }
}
