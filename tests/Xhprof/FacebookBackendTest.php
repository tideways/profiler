<?php

namespace Xhprof;

use org\bovigo\vfs\vfsStream;

class FacebookBackendTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_stores_profile_as_gzcompressed_json()
    {
        $root = vfsStream::setup('profiles');

        $backend = new FacebookBackend(vfsStream::url('profiles'), 'app');
        $backend->storeProfile("operation", array("main()" => array('ct' => 1, 'wt' => 2)), array());

        $files = scandir(vfsStream::url('profiles'));
        $this->assertCount(1, $files);
        $data = unserialize(file_get_contents(vfsStream::url('profiles') . '/' . $files[0]));

        $this->assertEquals(array("main()" => array('ct' => 1, 'wt' => 2)), $data);
    }
}
