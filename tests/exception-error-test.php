<?php

require_once __DIR__ . '/../vendor/autoload.php';

\Tideways\Profiler::detectExceptionFunction('foo');
\Tideways\Profiler::start("foo", 100);

register_shutdown_function(function () {
    $reflClass = new \ReflectionClass('Tideways\Profiler');
    $property = $reflClass->getProperty('error');
    $property->setAccessible(true);
    var_dump($property->getValue());
});

function foo(\Exception $e) {}

function bar() {
    foo(new \RuntimeException("foobar"));
}

bar("foo", "bar");
