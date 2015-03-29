<?php

/** Check for auto starting the Profiler in Web and CLI (via Env Variable) */
if (ini_get("tideways.auto_start") || isset($_SERVER["TIDEWAYS_AUTO_START"])) {
    if (\Tideways\Profiler::isStarted() === false) {
        if (php_sapi_name() !== "cli") {
            /**
             * In Web context we auto start with the framework transaction name
             * configured in INI or ENV variable.
             */
            if (ini_get("tideways.framework")) {
                \Tideways\Profiler::detectFramework(ini_get("tideways.framework"));
            } else if (isset($_SERVER['TIDEWAYS_FRAMEWORK'])) {
                \Tideways\Profiler::detectFramework($_SERVER["TIDEWAYS_FRAMEWORK"]);
            }
            \Tideways\Profiler::start();
        } else if (php_sapi_name() === "cli" && !empty($_SERVER["TIDEWAYS_SESSION"]) && isset($_SERVER['argv'])) {
            \Tideways\Profiler::start();
            \Tideways\Profiler::setTransactionName("cli:" . basename($_SERVER['argv'][0]));
        }
    }
}
