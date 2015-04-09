<?php

require_once __DIR__ . '/../vendor/autoload.php';

var_dump(\Tideways\Profiler::isStarted());

\Tideways\Profiler::start("foo", 100);

var_dump(\Tideways\Profiler::isProfiling());
var_dump(function_exists('tideways_fatal_backtrace'));

register_shutdown_function(function () {
    $reflClass = new \ReflectionClass('Tideways\Profiler');
    $property = $reflClass->getProperty('error');
    $property->setAccessible(true);
    var_dump($property->getValue());
});

function foo() {
    bar(new stdClass, array());
}

function bar() {
    unknown();
}

foo(123, "foo");
