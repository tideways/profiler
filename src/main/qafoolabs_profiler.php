<?php
/**
 * QafooLabs Profiler - Xhprof Client
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

/**
 * Start profilling in development mode.
 *
 * Profiles generated in this mode will be sent to the UI directly
 * via HTTP POST request. This can have signifcant overhead and
 * is not recommended in production at all. Requests to the API
 * are rate-limited as well, so you will get failures after 1000
 * requests per hour.
 *
 * @param string $apiKey
 */
function qafoolabs_profiler_development_start($apiKey)
{
    if ( ! isset($GLOBALS['qafoolabs_profiler'])) {
        $GLOBALS['qafoolabs_profiler'] = new Xhprof\ProfileCollector(
            new Xhprof\QafooDeveloperBackend($apiKey),
            new Xhprof\StartDecisions\AlwaysStart()
        );
    }

    $GLOBALS['qafoolabs_profiler']->start();
}

/**
 * Start profiling in production mode.
 *
 * Generated profiles and application peformance measurements
 * are sent to the local Qafoo Profiler Daemon. Only a small
 * amount of requests is fully profiled (1% by default).
 *
 * You can explicitly request a profile by adding a query parameter
 * &_qprofile=md5($apiKey). You find this hash in the Qafoo Profiler
 * web backend.
 *
 * @param string $apiKey
 * @param int $samplePercentage
 */
function qafoolabs_profiler_start($apiKey, $samplePercentage = 1)
{
    if ( ! isset($GLOBALS['qafoolabs_profiler'])) {
        $GLOBALS['qafoolabs_profiler'] = new Xhprof\ProfileCollector(
            new Xhprof\QafooProfilerBackend($apiKey),
            new Xhprof\StartDecisions\OrStart(
                new Xhprof\StartDecisions\ApiKeyHashStart($apiKey),
                ($samplePercentage === 100)
                    ? new Xhprof\StartDecisions\AlwaysStart()
                    : new Xhprof\StartDecisions\SampleStart($samplePercentage)
            )
        );
    }

    $GLOBALS['qafoolabs_profiler']->start();
}

/**
 * Is the profiler running at the moment?
 *
 * @return bool
 */
function qafoolabs_profiler_is_started()
{
    if ( ! isset($GLOBALS['qafoolabs_profiler'])) {
        return false;
    }

    $GLOBALS['qafoolabs_profiler']->isStarted();
}

/**
 * Stop the profiling and send measurement or profile data to backend.
 *
 * @return void
 */
function qafoolabs_profiler_stop()
{
    if ( ! isset($GLOBALS['qafoolabs_profiler'])) {
        return;
    }

    $GLOBALS['qafoolabs_profiler']->stop();
    $GLOBALS['qafoolabs_profiler'] = null;
}

/**
 * Set the name of the operation currently recorded.
 *
 * If you dont set this value, the library will try to guess
 * an operation name from the Request or CLI data.
 *
 * @param string $name
 * @return void
 */
function qafoolabs_profiler_set_operation_name($name)
{
    if ( ! isset($GLOBALS['qafoolabs_profiler'])) {
        return;
    }

    $GLOBALS['qafoolabs_profiler']->setOperationName($name);
}

/**
 * Set the Operation type
 *
 * This is normally auto-detected by checking for the PHP SAPI name "cli"
 * for worker, cronjob and background processes.
 *
 * @param int $type
 * @return void
 */
function qafoolabs_profiler_set_operation_type($type)
{
    if ( ! isset($GLOBALS['qafoolabs_profiler'])) {
        return;
    }

    $GLOBALS['qafoolabs_profiler']->setOperationType($type);
}

/**
 * Add a custom measurement datapoint to the profiling data.
 *
 * This can be for example a specific SQL query and its wall time.
 * You can attach the custom data to a specific function or
 * method call by setting the $parent parameter to something different
 * than main(), for example mysql_query.
 *
 * @param string $name
 * @param int $wallTime in Microseconds
 * @param string $parent
 * @return void
 */
function qafoolabs_profiler_add_custom_measurement($name, $wallTime, $parent = 'main()')
{
    if ( ! isset($GLOBALS['qafoolabs_profiler'])) {
        return;
    }

    $GLOBALS['qafoolabs_profiler']->addCustomMeasurement($name, $wallTime, $parent);
}
