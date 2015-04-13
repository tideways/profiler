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

    const MODE_NONE = 0;
    const MODE_BASIC = 1;
    const MODE_SAMPLING = 2;
    const MODE_PROFILING = 3;

    const EXTENSION_NONE = 0;
    const EXTENSION_XHPROF = 1;
    const EXTENSION_TIDEWAYS = 2;

    const EXT_FATAL            = 1;
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

    private static $trace;
    private static $startTime = false;
    private static $currentRootSpan;
    private static $shutdownRegistered = false;
    private static $error = false;
    private static $mode = self::MODE_NONE;
    private static $backend;
    private static $extension = self::EXTENSION_NONE;

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
        if (self::$mode !== self::MODE_NONE) {
            return;
        }

        $apiKey = $apiKey ?: (isset($_SERVER['TIDEWAYS_APIKEY']) ? $_SERVER['TIDEWAYS_APIKEY'] : ini_get("tideways.api_key"));
        $sampleRate = $sampleRate ?: (isset($_SERVER['TIDEWAYS_SAMPLERATE']) ? intval($_SERVER['TIDEWAYS_SAMPLERATE']) : ini_get("tideways.sample_rate"));

        if (strlen((string)$apiKey) === 0) {
            return;
        }

        self::init($apiKey);

        if (self::$extension === self::EXTENSION_NONE) {
            return;
        }

        if (self::$extension === self::EXTENSION_TIDEWAYS) {
            if (self::decideProfiling($sampleRate)) {
                tideways_enable(0, self::$defaultOptions);
                self::$mode = self::MODE_PROFILING;
            } else if (self::$defaultOptions['transaction_function']) {
                tideways_enable(
                    TIDEWAYS_FLAGS_NO_COMPILE | TIDEWAYS_FLAGS_NO_USERLAND | TIDEWAYS_FLAGS_NO_BUILTINS,
                    array('transaction_function' => self::$defaultOptions['transaction_function'])
                );
                self::$mode = self::MODE_SAMPLING;
            }
        } elseif (self::$extension === self::EXTENSION_XHPROF && self::decideProfiling($sampleRate)) {
            xhprof_enable(0, self::$defaultOptions);
            self::$mode = self::MODE_PROFILING;
        }
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

            if ($vars['time'] > time() && hash_hmac('sha256', $message, md5(self::$trace['apiKey'])) === $vars['hash']) {
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
        if (self::$mode > self::MODE_BASIC) {
            self::$mode = self::MODE_NONE;

            if (self::$extension === self::EXTENSION_XHPROF) {
                xhprof_disable();
            } else if (self::$extension === self::EXTENSION_TIDEWAYS) {
                tideways_disable();
            }
        }

        self::$startTime = false;
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

        if (function_exists('tideways_enable')) {
            self::$extension = self::EXTENSION_TIDEWAYS;
        } else if (function_exists('xhprof_enable')) {
            self::$extension = self::EXTENSION_XHPROF;
        }

        self::$mode = self::MODE_BASIC;
        self::$trace = array(
            'apiKey' => $apiKey,
            'id' => mt_rand(0, PHP_INT_MAX),
            'tx' => 'default',
        );
        self::$error = false;
        self::$startTime = microtime(true);
        self::$currentRootSpan = self::createRootSpan();
    }

    public static function setTransactionName($name)
    {
        self::$trace['tx'] = !empty($name) ? $name : 'empty';
    }

    public static function isStarted()
    {
        return self::$mode !== self::MODE_NONE;
    }

    public static function isProfiling()
    {
        return self::$mode === self::MODE_PROFILING;
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
        if (self::$mode !== self::MODE_PROFILING || !is_scalar($value)) {
            return;
        }

        self::$currentRootSpan->annotate(array($name => $value));
    }

    /**
     * Create a new trace span with the given category name.
     *
     * @example
     *
     *  $span = \Tideways\Profiler::createSpan('sql');
     *
     * @return \Tideways\Traces\Span
     */
    public static function createSpan($name)
    {
        return (self::$mode === self::MODE_PROFILING)
            ? \Tideways\Traces\PhpSpan::createSpan($name)
            : new \Tideways\Traces\NullSpan();
    }

    /**
     * @return int
     */
    public static function currentDuration()
    {
        return intval(round((microtime(true) - self::$startTime) * 1000));
    }

    /**
     * Stop all profiling actions and submit collected data.
     */
    public static function stop()
    {
        if (self::$mode === self::MODE_NONE) {
            return;
        }

        $mode = self::$mode;

        if (self::$trace['tx'] === 'default' && self::$extension === self::EXTENSION_TIDEWAYS) {
            self::$trace['tx'] = tideways_transaction_name() ?: 'default';
        }

        $profilingData = array();
        if ($mode > self::MODE_BASIC) {
            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $profilingData = tideways_disable();
            } elseif (self::$extension === self::EXTENSION_XHPROF) {
                $profilingData = xhprof_disable();
            }
        }

        if ($mode == self::MODE_PROFILING) {
            self::$trace['profdata'] = $profilingData;
            $annotations = array('mem' => ceil(memory_get_peak_usage() / 1024));

            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $annotations['xhpv'] = phpversion('tideways');
            } elseif (self::$extension === self::EXTENSION_XHPROF) {
                $annotations['xhpv'] = phpversion('xhprof');
            }

            if (extension_loaded('xdebug')) {
                $annotations['xdebug'] = '1';
            }

            if (isset($_SERVER['REQUEST_URI'])) {
                $annotations['title'] = '';
                if (isset($_SERVER['REQUEST_METHOD'])) {
                    $annotations['title'] = $_SERVER["REQUEST_METHOD"] . ' ';
                }

                if (isset($_SERVER['HTTP_HOST'])) {
                    $annotations['title'] .= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . self::getRequestUri();
                } elseif(isset($_SERVER['SERVER_ADDR'])) {
                    $annotations['title'] .= (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['SERVER_ADDR'] . self::getRequestUri();
                }

            } elseif (php_sapi_name() === "cli") {
                $annotations['title'] = basename($_SERVER['argv'][0]);
            }

            self::$currentRootSpan->annotate($annotations);
        }

        if (function_exists('tideways_last_detected_exception') && $exception = tideways_last_detected_exception()) {
            self::logException($exception);
        }

        $duration = self::currentDuration();

        self::$currentRootSpan->recordDuration($duration);
        self::$startTime = false;
        self::$mode = self::MODE_NONE;
        self::$trace['spans'] = \Tideways\Traces\PhpSpan::getSpans(); // hardoded as long only 1 impl exists.

        if ($mode === self::MODE_PROFILING) {
            self::$backend->socketStore(self::$trace);
        } else {
            self::$backend->udpStore(self::$trace);
        }
        self::$trace = null; // free memory
    }

    private static function createRootSpan()
    {
        \Tideways\Traces\PhpSpan::clear();

        $span = \Tideways\Traces\PhpSpan::createSpan('app');

        return $span;
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
        if (self::$error === true) {
            return;
        }

        if ($type === null) {
            $type = E_USER_ERROR;
        }

        $trace = is_array($trace)
            ? \Tideways\Profiler\BacktraceConverter::convertToString($trace)
            : $trace;

        self::$error = true;
        self::$currentRootSpan->annotate(array(
            "err" => $message,
            "err_source" => $file . ':' . $line,
            "exception" => 'EngineException', // Forward compatibility with PHP7
            "trace" => $trace,
        ));
    }

    public static function logException(\Exception $exception)
    {
        if (self::$error === true) {
            return;
        }

        self::$error = true;
        self::$currentRootSpan->annotate(array(
            "err" => $exception->getMessage(),
            "err_source" => $exception->getFile() . ':' . $exception->getLine(),
            "exception" => get_class($exception),
            "trace" => \Tideways\Profiler\BacktraceConverter::convertToString($exception->getTrace()),
        ));
    }

    public static function shutdown()
    {
        if (self::$mode === self::MODE_NONE) {
            return;
        }

        $lastError = error_get_last();

        if ($lastError && ($lastError["type"] === E_ERROR || $lastError["type"] === E_PARSE || $lastError["type"] === E_COMPILE_ERROR)) {
            $lastError['trace'] = function_exists('tideways_fatal_backtrace') ? tideways_fatal_backtrace() : null;

            self::logFatal($lastError['message'], $lastError['file'], $lastError['line'], $lastError['type'], $lastError['trace']);
        } elseif (function_exists("http_response_code") && http_response_code() >= 500) {
            self::logFatal("PHP request set error HTTP response code to '" . http_response_code() . "'.", "", 0, E_USER_ERROR);
        }

        self::stop();
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
