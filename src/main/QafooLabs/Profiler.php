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
 *      $id = QafooLabs\Profiler::startCustomTimer('solr', 'q=foo');
 *      QafooLabs\Profiler::stopCustomTimer($id);
 */
class Profiler
{
    const TYPE_WEB = 1;
    const TYPE_WORKER = 2;

    private static $apiKey;
    private static $started = false;
    private static $shutdownRegistered = false;
    private static $operationName;
    private static $customVars;
    private static $customTimers;
    private static $customTimerCount = 0;
    private static $operationType;
    private static $error;
    private static $profiling = false;
    private static $sampling = false;
    private static $correlationId;
    private static $backend;
    private static $uid;

    private static function getDefaultArgumentFunctions()
    {
        return array(
            'PDOStatement::execute',
            'PDO::exec',
            'PDO::query',
            'mysql_query',
            'mysqli_query',
            'mysqli::query',
            'curl_exec',
            'file_get_contents',
            'file_put_contents',
            'Twig_Template::render',
            'Smarty::fetch',
            'Smarty_Internal_TemplateBase::fetch',
        );
    }

    private static function getDefaultLayerFunctions()
    {
        return array(
            'PDO::__construct' => 'db',
            'PDO::exec' => 'db',
            'PDO::query' => 'db',
            'PDO::commit' => 'db',
            'PDOStatement::execute' => 'db',
            'mysql_query' => 'db',
            'mysqli_query' => 'db',
            'mysqli::query' => 'db',
            'curl_exec' => 'http',
            'curl_multi_exec' => 'http',
            'curl_multi_select' => 'http',
            'file_get_contents' => 'io',
            'file_put_contents' => 'io',
            'fopen' => 'io',
            'fsockopen' => 'io',
            'fgets' => 'io',
            'fputs' => 'io',
            'fwrite' => 'io',
            'file_exists' => 'io',
            'MemcachePool::get' => 'cache',
            'MemcachePool::set' => 'cache',
            'Memcache::connect' => 'cache',
            'apc_fetch' => 'cache',
            'apc_store' => 'cache',
        );
    }

    public static function setBackend(Profiler\Backend $backend)
    {
        self::$backend = $backend;
    }

    /**
     * Start profiling in development mode.
     *
     * This will always generate a full profile and send it to the profiler via cURL.
     */
    public static function startDevelopment($apiKey, array $options = array())
    {
        self::setBackend(new Profiler\CurlBackend());
        self::start($apiKey, 100, $options);
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
     * @param int               $sampleRate Sample rate in full percent (1= 1%, 20 = 20%). Defaults to every fifth request
     * @param array             $options XHProf options.
     *
     * @return void
     */
    public static function start($apiKey, $sampleRate = 20, array $options = array())
    {
        if (self::$started) {
            return;
        }

        if (strlen($apiKey) === 0) {
            return;
        }

        if (isset($_SERVER['QAFOO_PROFILER_DISABLED']) && $_SERVER['QAFOO_PROFILER_DISABLED']) {
            return;
        }

        self::init(php_sapi_name() == "cli" ? self::TYPE_WORKER : self::TYPE_WEB, $apiKey);

        if (function_exists("xhprof_enable") == false) {
            return;
        }

        $sampleRate = isset($_SERVER['QAFOO_PROFILER_SAMPLERATE']) ? intval($_SERVER['QAFOO_PROFILER_SAMPLERATE']) : $sampleRate;
        $flags = isset($_SERVER['QAFOO_PROFILER_FLAGS']) ? intval($_SERVER['QAFOO_PROFILER_FLAGS']) : 0;

        self::$profiling = self::decideProfiling($sampleRate);

        if (self::$profiling == true) {
            if (isset($_SERVER['QAFOO_PROFILER_ENABLE_ARGUMENTS']) && $_SERVER['QAFOO_PROFILER_ENABLE_ARGUMENTS']) {
                if (!isset($options['argument_functions'])) {
                    $options['argument_functions'] = self::getDefaultArgumentFunctions();
                }
            }

            xhprof_enable($flags, $options); // full profiling mode
            return;
        }

        if (!isset($_SERVER['QAFOO_PROFILER_ENABLE_LAYERS']) || !$_SERVER['QAFOO_PROFILER_ENABLE_LAYERS']) {
            return;
        }

        // careless hack to do this with a custom version, need to fork own 'xhprof' extension soon.
        if (version_compare(phpversion('xhprof'), '0.9.7') < 0) {
            return;
        }

        if (!isset($options['layers'])) {
            $options['layers'] = self::getDefaultLayerFunctions();
        }

        xhprof_layers_enable($options['layers']);
        self::$sampling = true;
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

        $rand = rand(1, 100);

        return $rand <= $treshold;
    }

    /**
     * Ignore this transaction and don't collect profiling or performance measurements.
     *
     * @return void
     */
    public static function ignoreTransaction()
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
        self::$customVars = array();
        self::$customTimers = array();
        self::$customTimerCount = 0;
        self::$operationName = 'default';
        self::$error = false;
        self::$operationType = $type;
        self::$started = microtime(true);
        self::$uid = null;
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
        self::$operationName = !empty($name) ? $name : 'empty';
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

    /**
     * Add a custom variable to this profile.
     *
     * Examples are the Request URL, UserId, Correlation Ids and more.
     *
     * Please do *NOT* set private data in custom variables as this
     * data is not encrypted on our servers.
     *
     * Only accepts scalar values.
     *
     * The key 'url' is a magic value and should contain the request
     * url if you want to transmit it. The Profiler UI will specially
     * display it.
     *
     * @param string $name
     * @param scalar $value
     * @return void
     */
    public static function setCustomVariable($name, $value)
    {
        if (!self::$profiling || !is_scalar($value)) {
            return;
        }

        self::$customVars[$name] = $value;
    }

    public static function getCustomVariable($name)
    {
        return isset(self::$customVars[$name])
            ? self::$customVars[$name]
            : null;
    }

    /**
     * Stop all profiling actions and submit collected data.
     */
    public static function stop()
    {
        if (self::$started == false) {
            return;
        }

        $data = null;
        $sampling = self::$sampling;

        if (self::$sampling || self::$profiling) {
            $data = xhprof_disable();
        }

        $duration = intval(round((microtime(true) - self::$started) * 1000));

        self::$started = false;
        self::$profiling = false;
        self::$sampling = false;

        if (self::$error) {
            self::storeError(self::$operationName, self::$error, $duration);
        }

        if (!self::$operationName) {
            return;
        }

        if (!$sampling && $data) {
            self::storeProfile(self::$operationName, $data, self::$customTimers, self::$operationType);
        } else {
            $callData = array();

            if ($sampling) {
                $callData = $data;
                $duration = intval(round($data['main()']['wt'] / 1000));
            }

            self::storeMeasurement(
                self::$operationName,
                $duration,
                self::$operationType,
                $callData,
                (self::$error !== false)
            );
        }
    }

    private static function storeError($operationName, $errorData, $duration)
    {
        self::$backend->storeError(
            array(
                "op" => ($operationName === null ? '__unknown__' : $operationName),
                "error" => $errorData,
                "apiKey" => self::$apiKey,
                "wt" => $duration,
                "cid" => (string)self::$correlationId
            )
        );
    }

    private static function storeProfile($operationName, $data, $customTimers, $operationType)
    {
        if (!isset($data["main()"]["wt"]) || !$data["main()"]["wt"]) {
            return;
        }

        if (isset($_SERVER['REQUEST_METHOD']) && !isset(self::$customVars['method'])) {
            self::$customVars['method'] = $_SERVER["REQUEST_METHOD"];
        }

        if (isset($_SERVER['REQUEST_URI']) && !isset(self::$customVars['url'])) {
            self::$customVars['url'] = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . self::getRequestUri();
        }

        self::$backend->storeProfile(array(
            "uid" => self::getProfileTraceUuid(),
            "op" => $operationName,
            "data" => $data,
            "custom" => $customTimers,
            "vars" => self::$customVars ?: null,
            "apiKey" => self::$apiKey,
            "ot" => $operationType,
            "mem" => round(memory_get_peak_usage() / 1024),
            "cid" => (string)self::$correlationId,
        ));
    }

    private static function storeMeasurement($operationName, $duration, $operationType, array $callData, $isError)
    {
        self::$backend->storeMeasurement(array(
            "op" => $operationName,
            "ot" => $operationType,
            "wt" => $duration,
            "mem" => round(memory_get_peak_usage() / 1024),
            "apiKey" => self::$apiKey,
            "c" => $callData,
            "err" => $isError,
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

        return $_SERVER["REQUEST_METHOD"] . " " . self::getRequestUri();
    }

    protected static function getRequestUri()
    {
        return strpos($_SERVER["REQUEST_URI"], "?")
            ? substr($_SERVER["REQUEST_URI"], 0, strpos($_SERVER["REQUEST_URI"], "?"))
            : $_SERVER["REQUEST_URI"];
    }

    public static function logFatal($message, $file, $line, $type = null, $trace = null)
    {
        if (self::$error) {
            return;
        }

        if ($type === null) {
            $type = E_USER_ERROR;
        }

        $message = Profiler\SqlAnonymizer::anonymize($message);

        self::$error = array(
            "message" => $message,
            "file" => $file,
            "line" => $line,
            "type" => $type,
            "trace" => $trace ? self::anonymizeTrace($trace) : null,
        );
    }

    public static function logException(\Exception $e)
    {
        $exceptionClass = get_class($e);
        $exceptionCode = $e->getCode();

        $message = Profiler\SqlAnonymizer::anonymize($e->getMessage());

        self::$error = array(
            "message" => $message,
            "file" => $e->getFile(),
            "line" => $e->getLine(),
            "type" => $exceptionClass . ($exceptionCode != 0 ? sprintf('(%s)', $exceptionCode) : ''),
            "trace" => self::anonymizeTrace($e->getTrace()),
        );
    }

    private static function anonymizeTrace(array $trace)
    {
        foreach ($trace as $traceLineId => $traceLine) {
            if (isset($traceLine['args'])) {

                foreach ($traceLine['args'] as $argId => $arg) {
                    if (is_object($arg)) {
                        $traceLine['args'][$argId] = get_class($arg);
                    } else {
                        $traceLine['args'][$argId] = gettype($arg);
                    }
                }
                $trace[$traceLineId] = $traceLine;

            }
        }
        return $trace;
    }

    public static function shutdown()
    {
        if (version_compare(phpversion('xhprof'), '0.9.7') < 0) {
            $lastError = error_get_last();
        } else {
            $lastError = xhprof_last_fatal_error();
        }

        if ($lastError && ($lastError["type"] === E_ERROR || $lastError["type"] === E_PARSE || $lastError["type"] === E_COMPILE_ERROR)) {
            self::logFatal($lastError["message"], $lastError["file"], $lastError["line"], $lastError["type"]);
        }

        if (function_exists("http_response_code") && http_response_code() >= 500) {
            self::logFatal("PHP request set error HTTP response code to '" . http_response_code() . "'.", "", 0, E_USER_ERROR);
        }

        self::stop();
    }

    /**
     * Get a unique identifier for the current profile trace.
     *
     * Base64 encoded version of the binary representation of a UUID.
     *
     * @return string
     */
    public static function getProfileTraceUuid()
    {
        if (self::$uid === null) {
            $uuid = base64_encode(
                pack(
                    "h*",
                    sprintf(
                        '%04x%04x%04x%04x%04x%04x%04x%04x',
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0x0fff) | 0x4000,
                        mt_rand(0, 0x3fff) | 0x8000,
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff),
                        mt_rand(0, 0xffff)
                    )
                )
            );
            $uuid = str_replace("/", "_", $uuid);
            $uuid = str_replace("+", "-", $uuid);

            self::$uid = substr($uuid, 0, strlen($uuid) - 2);
        }

        return self::$uid;
    }

    /**
     * Render HTML that the profiling toolbar picks up to display inline development information.
     *
     * This method does not display the html, it just returns it.
     *
     * @return string
     */
    public static function renderToolbarBootstrapHtml()
    {
        if (self::$started == false || self::$sampling === true) {
            return;
        }

        return sprintf(
            '<div id="QafooLabs-Profiler-Profile-Id" data-trace-id="%s" style="display:none !important;" aria-hidden="true"></div>',
            self::getProfileTraceUuid()
        );
    }
}
