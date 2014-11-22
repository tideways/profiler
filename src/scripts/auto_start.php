<?php

/** Check for auto starting the Profiler in Web and CLI (via Env Variable) */
if (ini_get("qafooprofiler.auto_start") || isset($_SERVER['QAFOOPROFILER_AUTO_START'])) {
    if (php_sapi_name() !== "cli") {
        /**
         * In Web context we auto start with the framework transaction name
         * configured in INI or ENV variable.
         */
        if (ini_get("qafooprofiler.transaction_name")) {
            \QafooLabs\Profiler::detectFrameworkTransaction(ini_get("qafooprofiler.transaction_name"));
        } else if (isset($_SERVER['QAFOOPROFILER_TRANSACTION_NAME'])) {
            \QafooLabs\Profiler::detectFrameworkTransaction($_SERVER['QAFOOPROFILER_TRANSACTION_NAME']);
        }
        \QafooLabs\Profiler::start();
    } else if (php_sapi_name() === "cli" && !empty($_SERVER['QAFOO_PROFILER_START'])) {
        \QafooLabs\Profiler::startDevelopment();

        $transactionName = "cli:" . basename($argv[0]);
        \QafooLabs\Profiler::setTransactionName($transactionName);
    }
}
