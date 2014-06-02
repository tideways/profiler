<?php

namespace Xhprof\StartDecisions;

class ApiKeyHashStartTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @test
     */
    public function it_should_profile_when_apikey_matches_hash()
    {
        $_GET['_qprofile'] = md5('foo');
        $decision = new ApiKeyHashStart('foo');

        $this->assertTrue($decision->shouldProfile());
    }

    /**
     * @test
     */
    public function it_should_not_profile_when_apikey_matches_hash()
    {
        $_GET['_qprofile'] = md5('foo');
        $decision = new ApiKeyHashStart('bar');

        $this->assertFalse($decision->shouldProfile());
    }
}
