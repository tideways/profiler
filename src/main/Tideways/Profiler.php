<?php
/**
 * Tideways
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Tideways;

/**
 * Tideways PHP API
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
 *      Tideways\Profiler::start($apiKey);
 *      Tideways\Profiler::setTransactionName("my tx name");
 *
 * Calling the {@link stop()} method is not necessary as it is
 * called automatically from a shutdown handler, if you are timing
 * worker processes however it is necessary:
 *
 *      Tideways\Profiler::stop();
 *
 * The method {@link setTransactionName} is required, failing to call
 * it will result in discarding of the data. You can automatically
 * guess a name using the following snippet:
 *
 *      Tideways\Profiler::useRequestAsTransactionName();
 *
 * If you want to collect custom timing data you can use for SQL:
 *
 *      $sql = "SELECT 1";
 *      $id = Tideways\Profiler::startSqlCustomTimer($sql);
 *      mysql_query($sql);
 *      Tideways\Profiler::stopCustomTimer($id);
 *
 * Or for any other timing data:
 *
 *      $id = Tideways\Profiler::startCustomTimer('solr', 'q=foo');
 *      Tideways\Profiler::stopCustomTimer($id);
 */
class Profiler
{
    const VERSION = '1.5.0';

    const EXT_FATAL            = 1;
    const EXT_LAYERS           = 2;
    const EXT_EXCEPTION        = 4;
    const EXT_TRANSACTION_NAME = 8;

    const FRAMEWORK_ZEND_FRAMEWORK1    = 'zend1';
    const FRAMEWORK_ZEND_FRAMEWORK2    = 'zend2';
    const FRAMEWORK_SYMFONY2_COMPONENT = 'symfony2c';
    const FRAMEWORK_SYMFONY2_FRAMEWORK = 'symfony2';
    const FRAMEWORK_OXID               = 'oxid';
    const FRAMEWORK_SHOPWARE           = 'shopware';
    const FRAMEWORK_WORDPRESS          = 'wordpress';

    /**
     * Default XHProf/Tideways hierachical profiling options.
     */
    private static $defaultOptions = array(
        'ignored_functions' => array(
            'call_user_func',
            'call_user_func_array',
            'array_filter',
            'array_map',
            'array_reduce',
            'array_walk',
            'array_walk_recursive',
            'Symfony\Component\DependencyInjection\Container::get',
        ),
        'transaction_function' => null,
        'argument_functions' => array(
            'PDOStatement::execute',
            'PDO::exec',
            'PDO::query',
            'mysql_query',
            'mysqli_query',
            'mysqli::query',
            'pg_query',
            'pg_query_params',
            'pg_execute',
            'curl_exec',
            'Twig_Template::render',
            'Smarty::fetch',
            'Smarty_Internal_TemplateBase::fetch',
        ),
    );

    private static $apiKey;
    private static $started = false;
    private static $shutdownRegistered = false;
    private static $operationName;
    private static $customVars;
    private static $customTimers;
    private static $customTimerCount = 0;
    private static $error;
    private static $profiling = false;
    private static $sampling = false;
    private static $correlationId;
    private static $backend;
    private static $uid;
    private static $extensionPrefix;
    private static $extensionFlags = 0;

    public static function setBackend(Profiler\Backend $backend)
    {
        self::$backend = $backend;
    }

    public static function detectExceptionFunction($function)
    {
        self::$defaultOptions['exception_function'] = $function;
    }

    /**
     * Instruct Tideways Profiler to automatically detect transaction names during profiling.
     *
     * @param string $function - A transaction function name
     */
    public static function detectFrameworkTransaction($function)
    {
        self::detectFramework($function);
    }

    /**
     * Configure detecting framework transactions and ignoring unnecessary layer calls.
     *
     * If the framework is not from the list of known frameworks it is assumed to
     * be a function name that is the transaction function.
     *
     * @param string $framework
     */
    public static function detectFramework($framework)
    {
        switch ($framework) {
            case self::FRAMEWORK_ZEND_FRAMEWORK1:
                self::$defaultOptions['transaction_function'] = 'Zend_Controller_Action::dispatch';
                break;

            case self::FRAMEWORK_ZEND_FRAMEWORK2:
                self::$defaultOptions['transaction_function'] = 'Zend\\MVC\\Controller\\ControllerManager::get';
                break;

            case self::FRAMEWORK_SYMFONY2_COMPONENT:
                self::$defaultOptions['transaction_function'] = 'Symfony\Component\HttpKernel\Controller\ControllerResolver::createController';
                break;

            case self::FRAMEWORK_SYMFONY2_FRAMEWORK:
                self::$defaultOptions['transaction_function'] = 'Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver::createController';
                break;

            case self::FRAMEWORK_OXID:
                self::$defaultOptions['transaction_function'] = 'oxView::setClassName';
                break;

            case self::FRAMEWORK_SHOPWARE:
                self::$defaultOptions['transaction_function'] = 'Enlight_Controller_Action::dispatch';
                break;

            case self::FRAMEWORK_WORDPRESS:
                self::$defaultOptions['transaction_function'] = 'get_query_template';
                break;

            default:
                self::$defaultOptions['transaction_function'] = $framework;
                break;
        }
    }

    /**
     * Add more ignore functions to profiling options.
     *
     * @param array<string> $functionNames
     * @return void
     */
    public static function addIgnoreFunctions(array $functionNames)
    {
        foreach ($functionNames as $functionName) {
            self::$defaultOptions['ignored_functions'][] = $functionName;
        }
    }

    /**
     * Start profiling in development mode.
     *
     * This will always generate a full profile and send it to the profiler.
     * It adds a correlation id that forces the profile into "developer"
     * traces and activates the memory profiling as well.
     *
     * WARNING: This method can cause huge performance impact on production setups.
     */
    public static function startDevelopment($apiKey = null, array $options = array())
    {
        self::start($apiKey, 100, $options, 4);

        if (!self::$correlationId) {
            self::$correlationId = "dev-trace";
        }
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
     * 2. _qprofiler Query Parameter (string key is deprecated or array)
     * 3. Cookie TIDEWAYS_SESSION
     * 4. TIDEWAYS_SAMPLERATE environment variable.
     *
     * start() automatically invokes a register shutdown handler that stops and
     * transmits the profiling data to the local daemon for further processing.
     *
     * @param string            $apiKey Application key can be found in "Settings" tab of Profiler UI
     * @param int               $sampleRate Sample rate in full percent (1= 1%, 20 = 20%). Defaults to every fifth request
     *
     * @return void
     */
    public static function start($apiKey = null, $sampleRate = null)
    {
        if (self::$started) {
            return;
        }

        $configApiKey = isset($_SERVER['TIDEWAYS_APIKEY']) ? $_SERVER['TIDEWAYS_APIKEY'] : ini_get("tideways.api_key");
        $apiKey = $apiKey ?: $configApiKey;

        $configSampleRate = isset($_SERVER['TIDEWAYS_SAMPLERATE']) ? intval($_SERVER['TIDEWAYS_SAMPLERATE']) : ini_get("tideways.sample_rate");
        $sampleRate = $sampleRate ?: $configSampleRate;

        if (strlen((string)$apiKey) === 0) {
            return;
        }

        self::init($apiKey);

        if (!self::$extensionPrefix) {
            return;
        }

        self::$profiling = self::decideProfiling($sampleRate);

        if (self::$profiling == true) {
            $enable = self::$extensionPrefix . '_enable';
            $enable(0, self::$defaultOptions); // full profiling mode
            return;
        }

        if ((self::$extensionFlags & self::EXT_LAYERS) === 0) {
            return;
        }

        if (!self::$defaultOptions['transaction_function']) {
            return;
        }

        tideways_enable(
            TIDEWAYS_FLAGS_NO_COMPILE | TIDEWAYS_FLAGS_NO_USERLAND | TIDEWAYS_FLAGS_NO_BUILTINS,
            array('transaction_function' => self::$defaultOptions['transaction_function'])
        );

        self::$sampling = true;
    }

    private static function decideProfiling($treshold)
    {
        $vars = array();

        if (isset($_SERVER['HTTP_X_TIDEWAYS_PROFILER']) && is_string($_SERVER['HTTP_X_TIDEWAYS_PROFILER'])) {
            parse_str($_SERVER['HTTP_X_TIDEWAYS_PROFILER'], $vars);
        } else if (isset($_SERVER['TIDEWAYS_SESSION']) && is_string($_SERVER['TIDEWAYS_SESSION'])) {
            parse_str($_SERVER['TIDEWAYS_SESSION'], $vars);
        } else if (isset($_COOKIE['TIDEWAYS_SESSION']) && is_string($_COOKIE['TIDEWAYS_SESSION'])) {
            parse_str($_COOKIE['TIDEWAYS_SESSION'], $vars);
        } else if (isset($_GET['_tideways']) && is_array($_GET['_tideways'])) {
            $vars = $_GET['_tideways'];
        }

        if (isset($_SERVER['TIDEWAYS_DISABLE_SESSIONS']) && $_SERVER['TIDEWAYS_DISABLE_SESSIONS']) {
            $vars = array();
        }

        if (isset($vars['hash'], $vars['time'], $vars['user'], $vars['method'])) {
            $message = 'method=' . $vars['method'] . '&time=' . $vars['time'] . '&user=' . $vars['user'];

            if ($vars['time'] > time() && hash_hmac('sha256', $message, md5(self::$apiKey)) === $vars['hash']) {
                self::$correlationId = 'dev-user-' . $vars['user'];

                return true;
            }
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

            $disable = self::$extensionPrefix . '_disable';
            $disable();
        }

        self::$started = false;
    }

    private static function init($apiKey)
    {
        if (self::$shutdownRegistered == false) {
            register_shutdown_function(array("Tideways\\Profiler", "shutdown"));
            self::$shutdownRegistered = true;
        }

        if (self::$backend === null) {
            self::$backend = new Profiler\NetworkBackend(
                ini_get('tideways.connection') ?: 'unix:///var/run/tideways/tidewaysd.sock',
                ini_get('tideways.udp_connection') ?: '127.0.0.1:8135'
            );
        }

        self::$profiling = false;
        self::$sampling = false;
        self::$apiKey = $apiKey;
        self::$customVars = array();
        self::$customTimers = array();
        self::$customTimerCount = 0;
        self::$operationName = 'default';
        self::$error = false;
        self::$started = microtime(true);
        self::$uid = null;

        if (function_exists('tideways_enable')) {
            $version = phpversion('tideways');

            self::$extensionPrefix = 'tideways';
            self::$extensionFlags = (self::EXT_EXCEPTION | self::EXT_FATAL | self::EXT_TRANSACTION_NAME);
            self::$extensionFlags |= (version_compare($version, "1.2.2") >= 0) ? self::EXT_LAYERS : 0;
            self::$customVars['xhpv'] = 'tw-' . $version;
        } else if (function_exists('xhprof_enable')) {
            self::$extensionPrefix = 'xhprof';
            self::$customVars['xhpv'] = 'xhp-' . phpversion('xhprof');
        } else if (function_exists('uprofiler_enable')) {
            self::$extensionPrefix = 'uprofiler';
            self::$customVars['xhpv'] = 'up-' . phpversion('uprofiler');
        }
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
     * Tideways service.
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

        if (self::$operationName === 'default' && (self::$extensionFlags & self::EXT_TRANSACTION_NAME) > 0) {
            self::$operationName = tideways_transaction_name() ?: 'default';
        }

        if (function_exists('tideways_last_detected_exception') && $exception = tideways_last_detected_exception()) {
            self::logException($exception);
        }

        if (self::$sampling || self::$profiling) {
            $disable = self::$extensionPrefix . '_disable';
            $data = $disable();
        }

        $duration = intval(round((microtime(true) - self::$started) * 1000));

        self::$started = false;
        self::$profiling = false;
        self::$sampling = false;

        if (self::$error) {
            self::storeError(self::$operationName, self::$error, $duration);
        }

        if (!$sampling && $data) {
            self::storeProfile(self::$operationName, $data, self::$customTimers);
        } else {
            self::storeMeasurement(self::$operationName, $duration, (self::$error !== false));
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

    public static function setDefaultCustomVariables()
    {
        if (isset($_SERVER['REQUEST_METHOD']) && !isset(self::$customVars['method'])) {
            self::$customVars['method'] = $_SERVER["REQUEST_METHOD"];
        }

        if (isset($_SERVER['REQUEST_URI']) && !isset(self::$customVars['url'])) {
            if (isset($_SERVER['HTTP_HOST'])) {
                self::$customVars['url'] = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . self::getRequestUri();
            } elseif(isset($_SERVER['SERVER_ADDR'])) {
                self::$customVars['url'] = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['SERVER_ADDR'] . self::getRequestUri();
            }
        }
    }

    private static function storeProfile($operationName, $data, $customTimers)
    {
        if (!isset($data["main()"]["wt"]) || !$data["main()"]["wt"]) {
            return;
        }

        self::setDefaultCustomVariables();

        self::$backend->storeProfile(array(
            "uid" => self::getProfileTraceUuid(),
            "op" => $operationName,
            "data" => $data,
            "custom" => $customTimers,
            "vars" => self::$customVars ?: null,
            "apiKey" => self::$apiKey,
            "mem" => round(memory_get_peak_usage() / 1024),
            "cid" => (string)self::$correlationId,
        ));
    }

    private static function storeMeasurement($operationName, $duration, $isError)
    {
        self::$backend->storeMeasurement(array(
            "op" => $operationName,
            "wt" => $duration,
            "mem" => round(memory_get_peak_usage() / 1024),
            "apiKey" => self::$apiKey,
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
            return "cli:" . basename($_SERVER["argv"][0]);
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

        $trace = is_array($trace)
            ? \Tideways\Profiler\BacktraceConverter::convertToString($trace)
            : $trace;

        $message = Profiler\SqlAnonymizer::anonymize($message);

        self::$error = array(
            "message" => $message,
            "file" => $file,
            "line" => $line,
            "type" => $type,
            "trace" => $trace,
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
            "trace" => \Tideways\Profiler\BacktraceConverter::convertToString($e->getTrace()),
        );
    }

    public static function shutdown()
    {
        if (function_exists('tideways_fatal_backtrace')) {
            $lastError = error_get_last();
            $lastError['trace'] = tideways_fatal_backtrace();
        } else if (function_exists('tideways_last_fatal_error')) {
            $lastError = tideways_last_fatal_error();
        } else {
            $lastError = error_get_last();
        }

        if ($lastError && ($lastError["type"] === E_ERROR || $lastError["type"] === E_PARSE || $lastError["type"] === E_COMPILE_ERROR)) {
            self::logFatal(
                $lastError["message"],
                $lastError["file"],
                $lastError["line"],
                $lastError["type"],
                isset($lastError["trace"]) ? $lastError["trace"] : null
            );
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
            '<div id="Tideways-Profiler-Profile-Id" data-trace-id="%s" style="display:none !important;" aria-hidden="true"></div>',
            self::getProfileTraceUuid()
        );
    }

    /**
     * Check for auto starting the Profiler in Web and CLI (via Env Variable)
     */
    public static function autoStart()
    {
        if (ini_get("tideways.auto_start") || isset($_SERVER["TIDEWAYS_AUTO_START"])) {
            if (self::isStarted() === false) {
                if (php_sapi_name() !== "cli") {
                    /**
                     * In Web context we auto start with the framework transaction name
                     * configured in INI or ENV variable.
                     */
                    if (ini_get("tideways.framework")) {
                        self::detectFramework(ini_get("tideways.framework"));
                    } else if (isset($_SERVER['TIDEWAYS_FRAMEWORK'])) {
                        self::detectFramework($_SERVER["TIDEWAYS_FRAMEWORK"]);
                    }
                    self::start();
                } else if (php_sapi_name() === "cli" && !empty($_SERVER["TIDEWAYS_SESSION"]) && isset($_SERVER['argv'])) {
                    self::start();
                    self::setTransactionName("cli:" . basename($_SERVER['argv'][0]));
                }
            }
        }

        if (self::requiresDelegateToOriginalPrependFile()) {
            require_once ini_get("auto_prepend_file");
        }
    }

    /**
     * @return bool
     */
    private static function requiresDelegateToOriginalPrependFile()
    {
        return ini_get('tideways.auto_prepend_library') &&
               tideways_prepend_overwritten() &&
               ini_get("auto_prepend_file") != "" &&
               file_exists(ini_get("auto_prepend_file"));
    }
}
