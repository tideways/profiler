<?php

require_once __DIR__ . '/../vendor/autoload.php';

\Tideways\Profiler::detectExceptionFunction('foo');
\Tideways\Profiler::start("foo", 100);

register_shutdown_function(function () {
    var_dump(\Tideways\Traces\PhpSpan::getSpans()[0]);
});

function foo(\Exception $e) {}

function bar() {
    foo(new \RuntimeException("foobar"));
}

bar("foo", "bar");
