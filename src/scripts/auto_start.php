<?php

/** Check for auto starting the Profiler in Web and CLI (via Env Variable) */
if (ini_get("tideways.auto_start") || isset($_SERVER['TIDEWAYS_AUTO_START'])) {
    if (php_sapi_name() !== "cli") {
        /**
         * In Web context we auto start with the framework transaction name
         * configured in INI or ENV variable.
         */
        if (ini_get("tideways.transaction_function")) {
            \Tideways\Profiler::detectFrameworkTransaction(ini_get("tideways.transaction_function"));
        } else if (isset($_SERVER['TIDEWAYS_TRANSACTION_NAME'])) {
            \Tideways\Profiler::detectFrameworkTransaction($_SERVER['TIDEWAYS_TRANSACTION_NAME']);
        }
        \Tideways\Profiler::start();
    } else if (php_sapi_name() === "cli" && !empty($_SERVER['TIDEWAYS_START'])) {
        \Tideways\Profiler::startDevelopment();

        $transactionName = "cli:" . basename($argv[0]);
        \Tideways\Profiler::setTransactionName($transactionName);
    }
}
