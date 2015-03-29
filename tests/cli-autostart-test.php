<?php

$_SERVER['TIDEWAYS_AUTO_START'] = 1;
$_SERVER['TIDEWAYS_APIKEY'] = $argv[1];

require_once __DIR__. '/../vendor/autoload.php';

function bar() {
}

function foo() {
    for ($i = 0; $i < 10; $i++) {
        bar();
    }
}

foo();
if (\Tideways\Profiler::isStarted()) {
    echo "Profiler started\n";
} else {
    echo "Profiler *NOT* started\n";
}
