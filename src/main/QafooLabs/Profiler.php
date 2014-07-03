<?php
/**
 * QafooLabs Profiler
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace QafooLabs;

// Guard against PECL extension
if (class_exists('QafooLabs\Profiler')) {
    return;
}

/**
 * QafooLabs Profiler PHP API
 *
 * Contains all methods to gather measurements and profile data with
 * Xhprof and send to local Profiler Collector Daemon.
 *
 * This class is intentionally monolithic and static to allow
 * users to easily copy it into their projects or auto-prepend PHP
 * scripts.
 *
 * @example
 *
 *      QafooLabs\Profiler::start($apiKey);
 *      QafooLabs\Profiler::setTransactionName("my tx name");
 *
 * Calling the {@link stop()} method is not necessary as it is
 * called automatically from a shutdown handler, if you are timing
 * worker processes however it is necessary:
 *
 *      QafooLabs\Profiler::stop();
 *
 * The method {@link setTransactionName} is required, failing to call
 * it will result in discarding of the data. You can automatically
 * guess a name using the following snippet:
 *
 *      QafooLabs\Profiler::useRequestAsTransactionName();
 *
 * If you want to collect custom timing data you can use for SQL:
 *
 *      $sql = "SELECT 1";
 *      $id = QafooLabs\Profiler::startSqlCustomTimer($sql);
 *      mysql_query($sql);
 *      QafooLabs\Profiler::stopCustomTimer($id);
 *
 * Or for any other timing data:
 *
 *      $id = QafooLabs\Profiler::startCustomTimer(('solr', 'q=foo');
 *      QafooLabs\Profiler::stopCustomTimer($id);
 */
class Profiler
{
    const TYPE_WEB = 1;
    const TYPE_WORKER = 2;
    const TYPE_DEV = 3;

    private static $apiKey;
    private static $started = false;
    private static $shutdownRegistered = false;
    private static $operationName;
    private static $customTimers;
    private static $customTimerCount = 0;
    private static $operationType;
    private static $error;
    private static $profiling = false;
    private static $sampling = false;
    private static $correlationId;
    private static $backend;
    private static $callIds;

    public static function setBackend(Profiler\Backend $backend)
    {
        self::$backend = $backend;
    }

    /**
     * Start profiling in development mode.
     *
     * This will always generate a full profile and send it to the profiler via cURL.
     */
    public static function startDevelopment($apiKey, $flags = 0, array $options = array())
    {
        if (self::$started) {
            return;
        }

        if (strlen($apiKey) === 0) {
            return;
        }

        self::init(self::TYPE_DEV, $apiKey);
        self::$profiling = function_exists("xhprof_enable");

        if (self::$profiling == false) {
            return;
        }

        xhprof_enable($flags, $options);
    }

    /**
     * Start production profiling for the application.
     *
     * There are three modes for profiling:
     *
     * 1. Wall-time only profiling of the complete request (no overhead)
     * 2. Full profile/trace using xhprof (depending of # function calls
     *    significant overhead)
     * 3. Whitelist-profiling mode only interesting functions.
     *    (5-40% overhead, requires custom xhprof version >= 0.95)
     *
     * Decisions to profile are made based on a sample-rate and random picks.
     * You can influence the sample rate and pick a value that suites your
     * application. Applications with lower request rates need a much higher
     * transaction rate (25-50%) than applications with high load (<= 1%).
     *
     * Factors that influence sample rate:
     *
     * 1. Second parameter $sampleRate to start() method.
     * 2. _qprofiler Query Parameter equals md5 hash of API-Key sets sample rate to 100%
     * 3. X-QPTreshold and X-QPHash HTTP Headers. The hash is a sha256 hmac of the treshold with the API-Key.
     * 4. QAFOO_PROFILER_TRESHOLD environment variable.
     *
     * start() automatically invokes a register shutdown handler that stops and
     * transmits the profiling data to the local daemon for further processing.
     *
     * @param string            $apiKey Application key can be found in "Settings" tab of Profiler UI
     * @param int               $sampleRate Sample rate in one 100th of a percent (100 = 1%). Defaults to every tenth request
     * @param array<string,int> $callIds Key-value pairs of functions to call-id for low-overhead sample mode.
     *
     * @return void
     */
    public static function start($apiKey, $sampleRate = 1000, array $callIds = array())
    {
        if (self::$started) {
            return;
        }

        if (strlen($apiKey) === 0) {
            return;
        }

        self::init(php_sapi_name() == "cli" ? self::TYPE_WORKER : self::TYPE_WEB, $apiKey);

        if (function_exists("xhprof_enable") == false) {
            return;
        }

        if (is_bool($sampleRate)) {
            $sampleRate = (int)$sampleRate * 10000;
        }

        self::$profiling = self::decideProfiling($sampleRate);

        if (self::$profiling == true) {
            xhprof_enable(); // full profiling mode
            return;
        }

        // careless hack to do this with a custom version, need to fork own 'xhprof' extension soon.
        if (version_compare(phpversion('xhprof'), '0.9.5') < 0) {
            return;
        }

        if (!$callIds && strpos($apiKey, '..') === false && file_exists('/etc/qafooprofiler/' . $apiKey . '.ini')) {
            $callIds = parse_ini_file('/etc/qafooprofiler/' . $apiKey . '.ini');
        }

        if ($callIds) {
            xhprof_enable(0, array('functions' => array_values($callIds)));
            self::$sampling = true;
            self::$callIds = $callIds;
        }
    }

    private static function decideProfiling($treshold)
    {
        if (isset($_GET["_qprofiler"]) && $_GET["_qprofiler"] === md5(self::$apiKey)) {
            return true;
        }

        if (isset($_SERVER["HTTP_X_QPTRESHOLD"]) && isset($_SERVER["HTTP_X_QPHASH"])) {
            if (hash_hmac("sha256", $_SERVER["HTTP_X_QPTRESHOLD"], self::$apiKey) === $_SERVER["HTTP_X_QPHASH"]) {
                $treshold = intval($_SERVER["HTTP_X_QPTRESHOLD"]);

                if (isset($_SERVER["HTTP_X_QPCORRELATIONID"])) {
                    self::$correlationId = strval($_SERVER["HTTP_X_QPCORRELATIONID"]);
                }
            }
        }
        if (isset($_SERVER["QAFOO_PROFILER_TRESHOLD"])) {
            $treshold = intval($_SERVER["QAFOO_PROFILER_TRESHOLD"]);
        }

        $rand = rand(1, 10000);

        return $rand <= $treshold;
    }

    /**
     * Ignore this transaction and don't collect profiling or performance measurements.
     *
     * @return void
     */
    public function ignoreTransaction()
    {
        if (self::$started && (self::$profiling || self::$sampling)) {
            self::$profiling = false;
            self::$sampling = false;

            xhprof_disable();
        }

        self::$started = false;
    }

    private static function init($type, $apiKey)
    {
        if (self::$shutdownRegistered == false) {
            register_shutdown_function(array("QafooLabs\\Profiler", "shutdown"));
            self::$shutdownRegistered = true;
        }

        if (self::$backend === null) {
            self::$backend = new Profiler\NetworkBackend();
        }

        self::$profiling = false;
        self::$sampling = false;
        self::$apiKey = $apiKey;
        self::$customTimers = array();
        self::$customTimerCount = 0;
        self::$operationName = null;
        self::$error = false;
        self::$operationType = $type;
        self::$started = microtime(true);
        self::$callIds = null;
    }

    /**
     * Return transaction hash for real user monitoring.
     *
     * @return string
     */
    public static function getTransactionHash()
    {
        return substr(sha1(self::$operationName), 0, 12);
    }

    /**
     * Return api hash used for real user monitoring.
     *
     * @return string
     */
    public static function getApiHash()
    {
        return sha1(self::$apiKey);
    }

    public static function setCorrelationId($id)
    {
        self::$correlationId = $id;
    }

    public static function setTransactionName($name)
    {
        self::$operationName = $name;
    }

    /**
     * Use {@link setTransactionName} instead.
     *
     * @deprecated
     */
    public static function setOperationName($name)
    {
        self::$operationName = $name;
    }

    /**
     * Start a custom timer for SQL execution with the give SQL query.
     *
     * Returns the timer id to be passed to {@link stopCustomTimer()}
     * for completing the timing process. Queries passed to this
     * method are anonymized using {@link SqlAnonymizer::anonymize()}.
     *
     * @param string $query
     * @return integer|bool
     */
    public static function startSqlCustomTimer($query)
    {
        return self::startCustomTimer('sql', Profiler\SqlAnonymizer::anonymize($query));
    }

    /**
     * Start a custom timer for the given group and using the given description.
     *
     * Data passed as description it not anonymized. It is your responsibility to
     * strip the data of any input that might cause private data to be sent to
     * Qafoo Profiler service.
     *
     * @param string $group
     * @param string $description
     * @return integer|bool
     */
    public static function startCustomTimer($group, $description)
    {
        if (self::$started == false || self::$profiling == false) {
            return false;
        }

        self::$customTimers[self::$customTimerCount] = array("s" => microtime(true), "id" => $description, "group" => $group);
        self::$customTimerCount++;

        return self::$customTimerCount - 1;
    }

    /**
     * Stop the custom timer with given id.
     *
     * @return bool
     */
    public static function stopCustomTimer($id)
    {
        if ($id === false || !isset(self::$customTimers[$id]) || isset(self::$customTimers[$id]["wt"])) {
            return false;
        }

        self::$customTimers[$id]["wt"] = intval(round((microtime(true) - self::$customTimers[$id]["s"]) * 1000000));
        unset(self::$customTimers[$id]["s"]);
        return true;
    }

    /**
     * Return all current custom timers.
     *
     * @return array
     */
    public static function getCustomTimers()
    {
        return self::$customTimers;
    }

    public static function isStarted()
    {
        return self::$started !== false;
    }

    public static function isProfiling()
    {
        return self::$profiling;
    }

    public static function stop()
    {
        if (self::$started == false) {
            return;
        }

        $data = null;
        $sampling = self::$sampling;

        if (self::$profiling || self::$sampling) {
            $data = xhprof_disable();
        }
        $duration = microtime(true) - self::$started;

        self::$started = false;
        self::$profiling = false;
        self::$sampling = false;

        if (!self::$operationName) {
            return;
        }

        if (self::$error && self::$operationType !== self::TYPE_DEV) {
            self::storeError(self::$operationName, self::$error);
            return;
        }

        if (self::$operationType === self::TYPE_DEV) {
            self::storeDevProfile(self::$operationName, $data, self::$customTimers);
            return;
        }

        if (!$sampling && $data) {
            self::storeProfile(self::$operationName, $data, self::$customTimers, self::$operationType);
        } else {
            $callData = array();

            if ($sampling) {
                // TODO: Can we force Xhprof extension to do this directly?
                $parsedData = array();

                foreach ($data as $parentChild => $childData) {
                    if ($parentChild === 'main()') {
                        continue;
                    }

                    list ($parent, $child) = explode('==>', $parentChild);

                    if (!isset($parsedData[$child])) {
                        $parsedData[$child] = array('wt' => 0, 'ct' => 0);
                    }
                    $parsedData[$child]['wt'] += $childData['wt'];
                    $parsedData[$child]['ct'] += $childData['ct'];
                }

                foreach (self::$callIds as $callId => $fn) {
                    if (isset($parsedData[$fn])) {
                        $callData["c$callId"] = $parsedData[$fn];
                    }
                }

                $duration = intval(round($data['main()']['wt'] / 1000));
            } else {
                $duration = intval(round($duration * 1000));
            }

            self::storeMeasurement(self::$operationName, $duration, self::$operationType, $callData);
        }
    }

    private static function storeError($operationName, $errorData)
    {
        self::$backend->storeError(
            array(
                "op" => $operationName,
                "error" => $errorData,
                "apiKey" => self::$apiKey,
                "cid" => (string)self::$correlationId
            )
        );
    }

    private static function storeDevProfile($operationName, $data, $customTimers)
    {
        if (!isset($data["main()"]["wt"]) || !$data["main()"]["wt"]) {
            return;
        }

        self::$backend->storeDevProfile(array(
            "apiKey" => self::$apiKey,
            "op" => $operationName,
            "ot" => self::TYPE_DEV,
            "cid" => (string)self::$correlationId,
            "mem" => round(memory_get_peak_usage() / 1024),
            "data" => $data,
            "custom" => $customTimers,
            "server" => gethostname(),
        ));
    }

    private static function storeProfile($operationName, $data, $customTimers, $operationType)
    {
        if (!isset($data["main()"]["wt"]) || !$data["main()"]["wt"]) {
            return;
        }
        self::$backend->storeProfile(array(
            "op" => $operationName,
            "data" => $data,
            "custom" => $customTimers,
            "apiKey" => self::$apiKey,
            "ot" => $operationType,
            "mem" => round(memory_get_peak_usage() / 1024),
            "cid" => (string)self::$correlationId,
        ));
    }

    private static function storeMeasurement($operationName, $duration, $operationType, array $callData)
    {
        self::$backend->storeMeasurement(array(
            "op" => $operationName,
            "ot" => $operationType,
            "wt" => $duration,
            "mem" => round(memory_get_peak_usage() / 1024),
            "apiKey" => self::$apiKey,
            "c" => $callData
        ));
    }

    /**
     * Use Request or Script information for the transaction name.
     *
     * @return void
     */
    public static function useRequestAsTransactionName()
    {
        self::setTransactionName(self::guessOperationName());
    }

    /**
     * Use {@link useRequestAsTransactionName()} instead.
     *
     * @deprecated
     */
    public static function guessOperationName()
    {
        if (php_sapi_name() === "cli") {
            return basename($_SERVER["argv"][0]);
        }

        $uri = strpos($_SERVER["REQUEST_URI"], "?")
            ? substr($_SERVER["REQUEST_URI"], 0, strpos($_SERVER["REQUEST_URI"], "?"))
            : $_SERVER["REQUEST_URI"];

        return $_SERVER["REQUEST_METHOD"] . " " . $uri;
    }

    public static function logFatal($message, $file, $line, $type = null)
    {
        if (self::$error) {
            return;
        }

        if ($type === null) {
            $type = E_USER_ERROR;
        }

        self::$error = array("message" => $message, "file" => $file, "line" => $line, "type" => $type);
    }

    public static function shutdown()
    {
        $lastError = error_get_last();

        if ($lastError && ($lastError["type"] === E_ERROR || $lastError["type"] === E_PARSE || $lastError["type"] === E_COMPILE_ERROR)) {
            self::logFatal($lastError["message"], $lastError["file"], $lastError["line"], $lastError["type"]);
        }

        if (function_exists("http_response_code") && http_response_code() >= 500) {
            self::logFatal("PHP request set error HTTP response code to '" . http_response_code() . "'.", "", 0, E_USER_ERROR);
        }

        self::stop();
    }
}
