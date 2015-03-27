<?php

namespace Tideways\Profiler;

class CurlBackendTest extends \PHPUnit_Framework_TestCase
{
    private $oldEnv = array();

    protected function setUp()
    {
        $vars = array('http_proxy', 'https_proxy', 'no_proxy');
        foreach ($vars as $currentVar) {
            $this->oldEnv[$currentVar] = getenv($currentVar);
            putenv($currentVar);
        }
    }

    protected function tearDown()
    {
        foreach ($this->oldEnv as $var => $value) {
            putenv("$var=$value");
        }
    }

    public function testNoProxyEnvVars()
    {
        $curlBackend = new CurlBackend;
        $this->assertNull($curlBackend->getProxy());
    }

    public function testHttpsProxy()
    {
        putenv('https_proxy=http://1.2.3.4:8443/');
        putenv('http_proxy=http://1.2.3.4:8080/');
        $curlBackend = new CurlBackend;
        $this->assertSame('http://1.2.3.4:8443/', $curlBackend->getProxy());
    }

    public function testHttpProxyOnly()
    {
        putenv('http_proxy=http://1.2.3.4:8080/');
        $curlBackend = new CurlBackend;
        $this->assertSame('http://1.2.3.4:8080/', $curlBackend->getProxy());
    }

    public function testSkipProxyForHost()
    {
        putenv('https_proxy=http://1.2.3.4:8443/');
        putenv('http_proxy=http://1.2.3.4:8080/');
        putenv('no_proxy=localhost,127.0.0.1,profiler.qafoolabs.com,.example.com');
        $curlBackend = new CurlBackend;
        $this->assertNull($curlBackend->getProxy());
    }

    public function testSkipProxyForDomain()
    {
        putenv('https_proxy=http://1.2.3.4:8443/');
        putenv('http_proxy=http://1.2.3.4:8080/');
        putenv('no_proxy=localhost,127.0.0.1,.qafoolabs.com,.example.com');
        $curlBackend = new CurlBackend;
        $this->assertNull($curlBackend->getProxy());
    }

    public function testDoNotSkipProxy()
    {
        putenv('https_proxy=http://1.2.3.4:8443/');
        putenv('http_proxy=http://1.2.3.4:8080/');
        putenv('no_proxy=localhost,127.0.0.1,.example.com');
        $curlBackend = new CurlBackend;
        $this->assertSame('http://1.2.3.4:8443/', $curlBackend->getProxy());
    }
}
