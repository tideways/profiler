<?php

namespace Tideways;

class ProfilerTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        if (function_exists('tideways_prepend_overwritten') && tideways_prepend_overwritten()) {
            $this->markTestSkipped('Cannot run tests when tideways is installed and loaded globally. Run with -dtideways.auto_prepend_library=0');
        }
        \Tideways\Profiler::stop();
        \Tideways\Profiler::setBackend(null);
        tideways_disable();

        if (\Tideways\Profiler::isStarted()) {
            $this->fail('Profiler is already running');
        }
        unset(
            $_SERVER['HTTP_X_TIDEWAYS_PROFILER'],
            $_SERVER['HTTP_X_TW_ROOTID'],
            $_SERVER['HTTP_X_TW_SPANID'],
            $_SERVER['HTTP_X_TW_TRACEID'],
            $_SERVER['REMOTE_ADDR'],
            $_SERVER['HTTP_X_FORWARDED_FOR']
        );
    }

    public function testStartStopProfile()
    {
        $alwaysSample = 100;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('socketStore');

        \Tideways\Profiler::start('foo', $alwaysSample);
        \Tideways\Profiler::setTransactionName(__CLASS__ . '::' . __METHOD__);

        $this->assertTrue(\Tideways\Profiler::isTracing());
        $this->assertTrue(\Tideways\Profiler::isStarted());

        \Tideways\Profiler::stop();

        $this->assertFalse(\Tideways\Profiler::isTracing());
        $this->assertFalse(\Tideways\Profiler::isStarted());
    }

    public function testStartStopMeasurement()
    {

        $neverProfile = 0;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('udpStore');

        \Tideways\Profiler::start('foo', $neverProfile);

        $this->assertFalse(\Tideways\Profiler::isProfiling());
        $this->assertTrue(\Tideways\Profiler::isStarted());

        \Tideways\Profiler::stop();

        $this->assertFalse(\Tideways\Profiler::isProfiling());
        $this->assertFalse(\Tideways\Profiler::isStarted());
    }

    public function testSamplingIgnoresUserlandSpans()
    {
        $neverProfile = 0;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('udpStore')->will($this->returnCallback(function($trace) {
            $this->assertCount(1, $trace['spans']);
        }));

        \Tideways\Profiler::start('foo', $neverProfile);

        $span = \Tideways\Profiler::createSpan('sql');
        $span->startTimer();
        $span->annotate(array('foo' => 'bar'));
        $span->stopTimer();

        $span = \Tideways\Profiler::createSpan('redis');
        $span->startTimer();
        $span->annotate(array('foo' => 'bar'));
        $span->stopTimer();

        \Tideways\Profiler::stop();
    }

    public function testProfilingKeepsUserlandSpans()
    {
        $alwaysProfile = 100;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('socketStore')->will($this->returnCallback(function($trace) {
            $this->assertCount(3, $trace['spans']);
        }));

        \Tideways\Profiler::start('foo', $alwaysProfile);

        $span = \Tideways\Profiler::createSpan('sql');
        $span->startTimer();
        $span->annotate(array('foo' => 'bar'));
        $span->stopTimer();

        $span = \Tideways\Profiler::createSpan('redis');
        $span->startTimer();
        $span->annotate(array('foo' => 'bar'));
        $span->stopTimer();

        \Tideways\Profiler::stop();
    }

    public function testTransactionNamePassedToTrace()
    {
        $neverProfile = 0;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('udpStore')->will($this->returnCallback(function($trace) {
            $this->assertEquals('foobar', $trace['tx']);
        }));

        \Tideways\Profiler::start('foo', $neverProfile);
        \Tideways\Profiler::setTransactionName('foobar');
        \Tideways\Profiler::stop();
    }

    public function testLogFatalPassedToTrace()
    {
        $neverProfile = 0;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('socketStore')->will($this->returnCallback(function($trace) {
            $annotations = $trace['spans'][0]['a'];
            $this->assertEquals('errmsg', $annotations['err_msg']);
            $this->assertEquals('foo.php:11', $annotations['err_source']);
        }));

        \Tideways\Profiler::start('foo', $neverProfile);
        \Tideways\Profiler::logFatal('errmsg', 'foo.php', 11);
        \Tideways\Profiler::stop();
    }

    public function testLogExceptionPassedToTrace()
    {
        $neverProfile = 0;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('socketStore')->will($this->returnCallback(function($trace) {
            $annotations = $trace['spans'][0]['a'];
            $this->assertEquals('Testing exceptions', $annotations['err_msg']);
            $this->assertContains('ProfilerTest.php:', $annotations['err_source']);
            $this->assertEquals('RuntimeException', $annotations['err_exception']);
        }));

        $exception = new \RuntimeException('Testing exceptions');

        \Tideways\Profiler::start('foo', $neverProfile);
        \Tideways\Profiler::logException($exception);
        \Tideways\Profiler::stop();
    }

    private function createBackend()
    {
        $backend = $this->getMock('Tideways\Profiler\Backend');
        \Tideways\Profiler::setBackend($backend);

        return $backend;
    }

    public function testDetectFramework()
    {
        \Tideways\Profiler::detectFramework(\Tideways\Profiler::FRAMEWORK_SYMFONY2_FRAMEWORK);
        \Tideways\Profiler::start('foo');

        $reflection = new \ReflectionClass('Tideways\Profiler');
        $property = $reflection->getProperty('defaultOptions');
        $property->setAccessible(true);
        $options = $property->getValue();

        $this->assertEquals('Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver::createController', $options['transaction_function']);
    }

    public function testCreateSpan()
    {
        \Tideways\Profiler::start('foo', 100);

        $span = \Tideways\Profiler::createSpan('sql');

        $this->assertInstanceOf('Tideways\Traces\Span', $span);
    }

    public function testForceProfilingHash()
    {
        $time = time() + 100;
        $message = 'method=&time='.$time.'&user=usr';
        $hash = hash_hmac('sha256', $message, md5('foo'));
        $_SERVER['HTTP_X_TIDEWAYS_PROFILER'] = $message . '&hash=' . $hash;

        \Tideways\Profiler::start('foo', 0);

        $this->assertTrue(\Tideways\Profiler::isProfiling());

        \Tideways\Profiler::stop();
    }

    public function testExpiredProfilingHash()
    {
        $time = time() - 100;
        $message = 'method=&time='.$time.'&user=usr';
        $hash = hash_hmac('sha256', $message, md5('foo'));
        $_SERVER['HTTP_X_TIDEWAYS_PROFILER'] = $message . '&hash=' . $hash;

        \Tideways\Profiler::start('foo', 0);

        $this->assertFalse(\Tideways\Profiler::isProfiling());

        \Tideways\Profiler::stop();
    }

    public function testWrongProfilingHash()
    {
        $time = time() - 100;
        $message = 'method=&time='.$time.'&user=usr';
        $_SERVER['HTTP_X_TIDEWAYS_PROFILER'] = $message . '&hash=wrong';

        \Tideways\Profiler::start('foo', 0);

        $this->assertFalse(\Tideways\Profiler::isProfiling());

        \Tideways\Profiler::stop();
    }

    public function testWatchCallbackConfiguration()
    {
        if (!extension_loaded('tideways')) {
            $this->markTestSkipped('Requires "tideways" extension.');
        }

        $this->assertFalse(\Tideways\Profiler::isStarted());
        $this->assertFalse(\Tideways\Profiler::isTracing());

        $callback = function ($context) {
            $span = \Tideways\Profiler::createSpan('foo');
            $span->annotate(array('title' => $context['fn']));
            return $span->getId();
        };

        \Tideways\Profiler::watch('array_merge');
        \Tideways\Profiler::watchCallback('implode', $callback);

        \Tideways\Profiler::start(array('api_key' => 'foo', 'sample_rate' => 100));
        $this->assertTrue(\Tideways\Profiler::isTracing());
        $this->assertTrue(\Tideways\Profiler::isStarted());

        \Tideways\Profiler::watch('array_flip');
        \Tideways\Profiler::watchCallback('explode', $callback);

        $result = implode(';', array_merge(array_flip(array('foo' => 0)), explode(',', 'bar,baz')));

        $this->assertEquals('foo;bar;baz', $result);

        \Tideways\Profiler::stop();

        $spans = implode(";", array_map(function ($span) {
            return '[' . $span['n'] . ']' . $span['a']['title'];
        }, tideways_get_spans()));

        $this->assertEquals('[app]phpunit;[php]array_flip;[foo]explode;[php]array_merge;[foo]implode', $spans);
    }

    public function testLogFatalAndExceptionWhenNotProfling()
    {
        \Tideways\Profiler::stop();

        $reflClass = new \ReflectionClass('Tideways\Profiler');
        $property = $reflClass->getProperty('currentRootSpan');
        $property->setAccessible(true);
        $property->setValue(null);

        \Tideways\Profiler::logFatal('', '', 0);
        \Tideways\Profiler::logException(new \Exception());
    }

    public function testLogMetadataForTrace()
    {
        $alwaysProfile = 100;

        $backend = self::createBackend();
        $backend->expects($this->once())->method('socketStore')->will($this->returnCallback(function($trace) {
            $annotations = $trace['spans'][0]['a'];

            $this->assertEquals(phpversion(), $annotations['php']);
            $this->assertEquals(extension_loaded('xdebug'), isset($annotations['xdebug']));
            $this->assertArrayHasKey('xhpv', $annotations);
            $this->assertArrayHasKey('title', $annotations);
        }));

        \Tideways\Profiler::start('foo', $alwaysProfile);
        \Tideways\Profiler::stop();
    }

    public function testMonitorNone()
    {
        \Tideways\Profiler::start(['api_key' => 'foo', 'monitor' => 'NONE', 'sample_rate' => 0]);
        $this->assertFalse(\Tideways\Profiler::isStarted());
    }
}
