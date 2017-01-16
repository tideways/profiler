<?php
/**
 * Tideways
 *
 * Copyright 2014-2016 Tideways GmbH
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *    http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Tideways\Traces;

/**
 * Abstraction for trace spans.
 *
 * Different implementations based on support
 */
abstract class Span
{
    /**
     * Create Child span
     * @private
     */
    abstract public function createSpan($name = null);

    /**
     * @private
     * @return array
     */
    abstract public function getSpans();

    /**
     * 32/64 bit random integer.
     *
     * @return int
     */
    public abstract function getId();

    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public abstract function startTimer();

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public abstract function stopTimer();

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public abstract function annotate(array $annotations);
}

namespace Tideways\Traces;

class NullSpan extends Span
{
    public function createSpan($name = null)
    {
        return $this;
    }

    public function getSpans()
    {
        return array();
    }

    public function getId()
    {
        return 0;
    }

    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public function startTimer()
    {
    }

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public function stopTimer()
    {
    }

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public function annotate(array $annotations)
    {
    }
}

namespace Tideways\Traces;

use Tideways\Profiler;

/**
 * When Tideways PHP extension is not installed the span API
 * is handled in memory.
 */
class PhpSpan extends Span
{
    const ID = 'i';
    const NAME = 'n';
    const STARTS = 'b';
    const STOPS = 'e';
    const ANNOTATIONS = 'a';

    /**
     * @var array
     */
    private static $spans = array();
    private static $startTime = false;

    /**
     * @var bool
     */
    private $timerRunning = false;

    /**
     * @var int
     */
    private $idx;

    static public function clear()
    {
        self::$spans = array();
        self::$startTime = microtime(true);
    }

    public function createSpan($name = null)
    {
        $idx = count(self::$spans);
        return new self($idx, $name);
    }

    /**
     * @return int
     */
    private static function currentDuration()
    {
        return intval(round((microtime(true) - self::$startTime) * 1000000));
    }

    public function getSpans()
    {
        return self::$spans;
    }

    public function __construct($idx, $name = null)
    {
        $this->idx = $idx;
        self::$spans[$idx] = array(
            self::STARTS => array(),
            self::STOPS => array(),
            self::ANNOTATIONS => array(),
        );
        if ($name) {
            self::$spans[$idx][self::NAME] = $name;
        }
    }

    public function getId()
    {
        if (!isset(self::$spans[$this->idx][self::ID])) {
            self::$spans[$this->idx][self::ID] = \Tideways\Profiler::generateRandomId();
        }

        return self::$spans[$this->idx][self::ID];
    }

    public function startTimer()
    {
        if ($this->timerRunning) {
            return;
        }

        self::$spans[$this->idx][self::STARTS][] = self::currentDuration();
        $this->timerRunning = true;
    }

    public function stopTimer()
    {
        if (!$this->timerRunning) {
            return;
        }

        self::$spans[$this->idx][self::STOPS][] = self::currentDuration();
        $this->timerRunning = false;
    }

    public function annotate(array $annotations)
    {
        foreach ($annotations as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            self::$spans[$this->idx][self::ANNOTATIONS][$name] = (string)$value;
        }
    }
}

namespace Tideways\Traces;

class TwExtensionSpan extends Span
{
    /**
     * @var int
     */
    private $idx;

    public function createSpan($name = null)
    {
        return new self(tideways_span_create($name));
    }

    public function getSpans()
    {
        return tideways_get_spans();
    }

    public function __construct($idx)
    {
        $this->idx = $idx;
    }

    /**
     * 32/64 bit random integer.
     *
     * @return int
     */
    public function getId()
    {
        return $this->idx;
    }

    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public function startTimer()
    {
        tideways_span_timer_start($this->idx);
    }

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public function stopTimer()
    {
        tideways_span_timer_stop($this->idx);
    }

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public function annotate(array $annotations)
    {
        tideways_span_annotate($this->idx, $annotations);
    }
}

namespace Tideways\Profiler;

/**
 * Low-level abstraction for storage of profiling data.
 */
interface Backend
{
    public function socketStore(array $trace);
    public function udpStore(array $trace);
}

namespace Tideways\Profiler;

class NetworkBackend implements Backend
{
    /**
     * Old v1 type profile format.
     *
     * @var string
     */
    const TYPE_PROFILE = 'profile';
    /**
     * v2 type traces
     */
    const TYPE_TRACE = 'trace';

    private $socketFile;
    private $udp;

    public function __construct($socketFile = "unix:///var/run/tideways/tidewaysd.sock", $udp = "127.0.0.1:8135")
    {
        $this->socketFile = $socketFile;
        $this->udp = $udp;
    }

    /**
     * To avoid user apps messing up socket errors that Tideways can produce
     * when the daemon is not reachable, this error handler is used
     * wrapped around daemons to guard user apps from erroring.
     */
    public static function ignoreErrorsHandler($errno, $errstr, $errfile, $errline)
    {
        // ignore all errors!
    }

    public function socketStore(array $trace)
    {
        if (!function_exists('json_encode')) {
            \Tideways\Profiler::log(1, "ext/json must be installed and activated to use Tideways.");
            return;
        }

        set_error_handler(array(__CLASS__, "ignoreErrorsHandler"));
        $fp = stream_socket_client($this->socketFile);

        if ($fp == false) {
            \Tideways\Profiler::log(1, "Cannot connect to socket for storing trace.");
            restore_error_handler();
            return;
        }

        $payload = json_encode(array('type' => self::TYPE_TRACE, 'payload' => $trace));

        $timeout = (int)ini_get('tideways.timeout');

        // We always enforce a timeout, even when the user configures
        // tideways.timeout=0 manually
        if (!$timeout) {
            $timeout = 10000;
        }

        if ($trace['keep']) {
            // as a dev trace we collect more data and the developer can be
            // waiting a little longer to make sure the socket gets everything.
            $timeout *= 10;
        }

        stream_set_timeout($fp, 0, $timeout); // 10 milliseconds max

        if (fwrite($fp, $payload) < strlen($payload)) {
            \Tideways\Profiler::log(1, "Could not write payload to socket.");
        }
        fclose($fp);
        restore_error_handler();
        \Tideways\Profiler::log(3, "Sent trace to socket.");
    }

    public function udpStore(array $trace)
    {
        if (!function_exists('json_encode')) {
            \Tideways\Profiler::log(1, "ext/json must be installed and activated to use Tideways.");
            return;
        }

        set_error_handler(array(__CLASS__, "ignoreErrorsHandler"));
        $fp = stream_socket_client("udp://" . $this->udp);

        if ($fp == false) {
            \Tideways\Profiler::log(1, "Cannot connect to UDP port for storing trace.");
            restore_error_handler();
            return;
        }

        unset($trace['id']);

        $payload = json_encode($trace);
        // Golang is very strict about json types.
        $payload = str_replace('"a":[]', '"a":{}', $payload);

        stream_set_timeout($fp, 0, 200);
        if (fwrite($fp, $payload) < strlen($payload)) {
            \Tideways\Profiler::log(1, "Could not write payload to UDP port.");
        }
        fclose($fp);
        restore_error_handler();
        \Tideways\Profiler::log(3, "Sent trace to UDP port.");
    }
}

namespace Tideways\Profiler;

/**
 * Convert a Backtrace to a String like {@see Exception::getTraceAsString()} would do.
 */
class BacktraceConverter
{
    static public function convertToString(array $backtrace)
    {
        $trace = '';

        foreach ($backtrace as $k => $v) {
            if (!isset($v['function'])) {
                continue;
            }

            if (!isset($v['file'])) {
                $v['file'] = '';
            }

            if (!isset($v['line'])) {
                $v['line'] = '';
            }

            $args = '';
            if (isset($v['args'])) {
                $args = implode(', ', array_map(function ($arg) {
                    return (is_object($arg)) ? get_class($arg) : gettype($arg);
                }, $v['args']));
            }

            $trace .= '#' . ($k) . ' ';
            if (isset($v['file'])) {
                $trace .= $v['file'] . '(' . $v['line'] . '): ';
            }

            if (isset($v['class'])) {
                $trace .= $v['class'] . '->';
            }

            $trace .= $v['function'] . '(' . $args .')' . "\n";
        }

        return $trace;
    }
}

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
 * it will result in discarding of the data.
 */
class Profiler
{
    const MODE_DISABLED  = 0;
    const MODE_NONE = 0;
    const MODE_BASIC = 1;
    const MODE_PROFILING = 2;
    const MODE_TRACING = 4;
    const MODE_FULL = 6;

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
    const FRAMEWORK_LARAVEL            = 'laravel';
    const FRAMEWORK_MAGENTO            = 'magento';
    const FRAMEWORK_MAGENTO2           = 'magento2';
    const FRAMEWORK_PRESTA16           = 'presta16';
    const FRAMEWORK_DRUPAL8            = 'drupal8';
    const FRAMEWORK_TYPO3              = 'typo3';
    const FRAMEWORK_FLOW               = 'flow';
    const FRAMEWORK_FLOW4              = 'flow4';
    const FRAMEWORK_CAKE2              = 'cake2';
    const FRAMEWORK_CAKE3              = 'cake3';
    const FRAMEWORK_YII                = 'yii';
    const FRAMEWORK_YII2               = 'yii2';

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
        'exception_function' => null,
        'watches' => array(),
        'callbacks' => array(),
        'framework' => null,
    );

    private static $trace;
    private static $currentRootSpan;
    private static $shutdownRegistered = false;
    private static $error = false;
    private static $mode = self::MODE_DISABLED;
    private static $backend;
    private static $extension = self::EXTENSION_NONE;
    private static $logLevel = 0;

    public static function setBackend(Profiler\Backend $backend = null)
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
        self::$defaultOptions['framework'] = $framework;
        $cli = (php_sapi_name() === 'cli');

        switch ($framework) {
            case self::FRAMEWORK_ZEND_FRAMEWORK1:
                self::$defaultOptions['transaction_function'] = 'Zend_Controller_Action::dispatch';
                self::$defaultOptions['exception_function'] = 'Zend_Controller_Response_Abstract::setException';
                break;

            case self::FRAMEWORK_ZEND_FRAMEWORK2:
                self::$defaultOptions['transaction_function'] = 'Zend\\Mvc\\Controller\\ControllerManager::get';
                break;

            case self::FRAMEWORK_SYMFONY2_COMPONENT:
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Symfony\Component\HttpKernel\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_SYMFONY2_FRAMEWORK:
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_OXID:
                self::$defaultOptions['transaction_function'] = 'oxView::setClassName';
                self::$defaultOptions['exception_function'] = 'oxShopControl::_handleBaseException';
                break;

            case self::FRAMEWORK_SHOPWARE:
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Enlight_Controller_Action::dispatch';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Zend_Controller_Response_Abstract::setException';
                break;

            case self::FRAMEWORK_WORDPRESS:
                self::$defaultOptions['transaction_function'] = 'get_query_template';
                break;

            case self::FRAMEWORK_LARAVEL:
                self::$defaultOptions['transaction_function'] = $cli
                    ? 'Symfony\Component\Console\Application::find'
                    : 'Illuminate\Routing\Controller::callAction';
                self::$defaultOptions['exception_function'] = $cli
                    ? 'Symfony\Component\Console\Application::renderException'
                    : 'Illuminate\Foundation\Http\Kernel::reportException';
                break;

            case self::FRAMEWORK_MAGENTO:
                self::$defaultOptions['transaction_function'] = 'Mage_Core_Controller_Varien_Action::dispatch';
                self::$defaultOptions['exception_function'] = 'Mage::printException';
                break;

            case self::FRAMEWORK_MAGENTO2:
                self::$defaultOptions['transaction_function'] = 'Magento\Framework\App\ActionFactory::create';
                self::$defaultOptions['exception_function'] = 'Magento\Framework\App\Http::catchException';
                break;

            case self::FRAMEWORK_PRESTA16:
                self::$defaultOptions['transaction_function'] = 'ControllerCore::getController';
                self::$defaultOptions['exception_function'] = 'PrestaShopExceptionCore::displayMessage';
                break;

            case self::FRAMEWORK_DRUPAL8:
                self::$defaultOptions['transaction_function'] = 'Drupal\Core\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_FLOW:
                self::$defaultOptions['transaction_function'] = 'TYPO3\Flow\Mvc\Controller\ActionController_Original::callActionMethod';
                self::$defaultOptions['exception_function'] = 'TYPO3\Flow\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_FLOW4:
                self::$defaultOptions['transaction_function'] = 'Neos\Flow\Mvc\Controller\ActionController_Original::callActionMethod';
                self::$defaultOptions['exception_function'] = 'Neos\Flow\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_TYPO3:
                self::$defaultOptions['transaction_function'] = 'TYPO3\CMS\Extbase\Mvc\Controller\ActionController::callActionMethod';
                self::$defaultOptions['exception_function'] = 'TYPO3\CMS\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_CAKE2:
                self::$defaultOptions['transaction_function'] = 'Controller::invokeAction';
                self::$defaultOptions['exception_function'] = 'ExceptionRenderer::__construct';
                break;

            case self::FRAMEWORK_CAKE3:
                self::$defaultOptions['transaction_function'] = 'Cake\\Controller\\Controller::invokeAction';
                self::$defaultOptions['exception_function'] = 'Cake\\Error\\ExceptionRenderer::__construct';
                break;

            case self::FRAMEWORK_YII:
                self::$defaultOptions['transaction_function'] = 'CController::run';
                break;

            case self::FRAMEWORK_YII2:
                self::$defaultOptions['transaction_function'] = 'yii\\base\\Module::runAction';
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
     * WARNING: This method can cause huge performance impact on production
     * setups. Make sure to wrap this in your own sampling code and don't
     * execute it in every request.
     */
    public static function startDevelopment($apiKey = null, array $options = array())
    {
        if ($apiKey) {
            $options['api_key'] = $apiKey;
        } else if (!isset($options['api_key'])) {
            $options['api_key'] = isset($_SERVER['TIDEWAYS_APIKEY']) ? $_SERVER['TIDEWAYS_APIKEY'] : ini_get("tideways.api_key");
        }

        $time = time() + 60;
        $_SERVER['TIDEWAYS_SESSION'] =
            "time=" . $time . "&user=&method=&hash=" .
            hash_hmac('sha256', 'method=&time=' . $time . '&user=', md5($options['api_key']))
        ;

        self::start($options);
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
     * 2. _tideways Query Parameter (string key is deprecated or array)
     * 3. Cookie TIDEWAYS_SESSION
     * 4. TIDEWAYS_SAMPLERATE environment variable.
     * 5. X-TIDEWAYS-PROFILER HTTP header
     *
     * start() automatically invokes a register shutdown handler that stops and
     * transmits the profiling data to the local daemon for further processing.
     *
     * @param array|string      $options Either options array or api key (when string)
     * @param int               $sampleRate Deprecated, use "sample_rate" key in options instead.
     *
     * @return void
     */
    public static function start($options = array(), $sampleRate = null)
    {
        self::ignoreTransaction(); // this discards any data that was collected up to now and restarts.

        if (!is_array($options)) {
            $options = array('api_key' => $options);
        }
        if ($sampleRate !== null) {
            $options['sample_rate'] = $sampleRate;
        }

        $defaults = array(
            'api_key' => isset($_SERVER['TIDEWAYS_APIKEY']) ? $_SERVER['TIDEWAYS_APIKEY'] : ini_get("tideways.api_key"),
            'sample_rate' => isset($_SERVER['TIDEWAYS_SAMPLERATE']) ? intval($_SERVER['TIDEWAYS_SAMPLERATE']) : (ini_get("tideways.sample_rate") ?: 10),
            'collect' => isset($_SERVER['TIDEWAYS_COLLECT']) ? $_SERVER['TIDEWAYS_COLLECT'] : (ini_get("tideways.collect") ?: self::MODE_PROFILING),
            'monitor' => isset($_SERVER['TIDEWAYS_MONITOR']) ? $_SERVER['TIDEWAYS_MONITOR'] : (ini_get("tideways.monitor") ?: self::MODE_BASIC),
            'triggered' => self::MODE_FULL,
            'log_level' => ini_get("tideways.log_level") ?: 0,
            'service' => isset($_SERVER['TIDEWAYS_SERVICE']) ? $_SERVER['TIDEWAYS_SERVICE'] : ini_get("tideways.service"),
            'framework' => isset($_SERVER['TIDEWAYS_FRAMEWORK']) ? $_SERVER['TIDEWAYS_FRAMEWORK'] : ini_get("tideways.framework"),
        );
        $options = array_merge($defaults, $options);

        if (strlen((string)$options['api_key']) === 0) {
            return;
        }

        self::$logLevel = $options['log_level'];
        self::init($options['api_key'], $options);
        self::decideProfiling($options['sample_rate'], $options);
    }

    /**
     * Enable the profiler in the given $mode.
     *
     * @param string $mode
     * @return void
     */
    private static function enableProfiler($mode)
    {
        self::$mode = $mode;

        if (self::$extension === self::EXTENSION_TIDEWAYS && (self::$mode !== self::MODE_DISABLED)) {
            switch (self::$mode) {
                case self::MODE_FULL:
                    $flags = 0;
                    break;

                case self::MODE_PROFILING:
                    $flags = TIDEWAYS_FLAGS_NO_SPANS;
                    break;

                case self::MODE_TRACING:
                    $flags = TIDEWAYS_FLAGS_NO_HIERACHICAL;
                    break;

                default:
                    $flags = TIDEWAYS_FLAGS_NO_COMPILE | TIDEWAYS_FLAGS_NO_USERLAND | TIDEWAYS_FLAGS_NO_BUILTINS;
                    break;
            }

            self::$currentRootSpan = new \Tideways\Traces\TwExtensionSpan(0);
            tideways_enable($flags, self::$defaultOptions);

            if (($flags & TIDEWAYS_FLAGS_NO_SPANS) === 0) {
                foreach (self::$defaultOptions['watches'] as $watch => $category) {
                    tideways_span_watch($watch, $category);
                }
                foreach (self::$defaultOptions['callbacks'] as $function => $callback) {
                    tideways_span_callback($function, $callback);
                }
            }

            self::log(2, "Starting tideways extension for " . self::$trace['apiKey'] . " with mode: " . $mode);
        } elseif (self::$extension === self::EXTENSION_XHPROF && (self::$mode & self::MODE_PROFILING) > 0) {
            \Tideways\Traces\PhpSpan::clear();
            self::$currentRootSpan = new \Tideways\Traces\PhpSpan(0, 'app');
            self::$currentRootSpan->startTimer();

            xhprof_enable(0, self::$defaultOptions);
            self::log(2, "Starting xhprof extension for " . self::$trace['apiKey'] . " with mode: " . $mode);
        } else {
            \Tideways\Traces\PhpSpan::clear();
            self::$currentRootSpan = new \Tideways\Traces\PhpSpan(0, 'app');
            self::$currentRootSpan->startTimer();

            self::log(2, "Starting non-extension based tracing for " . self::$trace['apiKey'] . " with mode: " . $mode);
        }
    }

    /**
     * Check if headers, cookie or environment variables for a developer trace
     * are present. This method does not validate if the passed information is
     * actually valid for the current API Key.
     *
     * @return bool
     */
    public static function containsDeveloperTraceRequest()
    {
        if (isset($_SERVER['HTTP_X_TIDEWAYS_PROFILER']) && is_string($_SERVER['HTTP_X_TIDEWAYS_PROFILER'])) {
            return true;
        } else if (isset($_SERVER['TIDEWAYS_SESSION']) && is_string($_SERVER['TIDEWAYS_SESSION'])) {
            return true;
        } else if (isset($_COOKIE['TIDEWAYS_SESSION']) && is_string($_COOKIE['TIDEWAYS_SESSION'])) {
            return true;
        } else if (isset($_GET['_tideways']) && is_array($_GET['_tideways'])) {
            return true;
        }

        return false;
    }

    /**
     * Decide in which mode to start collecting data.
     *
     * @param int $treshold (0-100)
     * @param array $options
     * @return int
     */
    private static function decideProfiling($treshold, array $options = array())
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
            self::log(3, "Found explicit trigger trace parameters in request.");

            if ($vars['time'] > time() && hash_hmac('sha256', $message, md5(self::$trace['apiKey'])) === $vars['hash']) {
                self::log(2, "Successful trigger trace request with valid hash.");
                self::$trace['keep'] = true; // always keep

                self::enableProfiler($options['triggered']);
                self::setCustomVariable('user', $vars['user']);
                return;
            } else {
                self::log(1, "Invalid trigger trace request cannot be authenticated.");
            }
        }

        self::log(3, sprintf("Profiling decision with sample-rate: %d", $treshold));

        $collectMode = self::convertMode($options['collect']);
        $monitorMode = self::convertMode($options['monitor']) & self::MODE_BASIC;

        $rand = rand(1, 100);
        $mode = ($rand <= $treshold) ? $collectMode : $monitorMode;

        self::enableProfiler($mode);
    }

    /**
     * Make sure provided mode is converted to a valid integer value.
     *
     * @return int
     */
    private static function convertMode($mode)
    {
        if (is_string($mode)) {
            $mode = defined('\Tideways\Profiler::MODE_' . strtoupper($mode))
                ? constant('\Tideways\Profiler::MODE_' . strtoupper($mode))
                : self::MODE_DISABLED;
        } else if (!is_int($mode)) {
            $mode = self::MODE_DISABLED;
        } else if (($mode & (self::MODE_FULL|self::MODE_BASIC)) === 0) {
            $mode = self::MODE_DISABLED;
        }

        return $mode;
    }

    /**
     * Ignore this transaction and don't collect profiling or performance measurements.
     *
     * @return void
     */
    public static function ignoreTransaction()
    {
        if (self::$mode !== self::MODE_DISABLED) {
            self::$mode = self::MODE_DISABLED;

            if (self::$extension === self::EXTENSION_XHPROF) {
                xhprof_disable();
            } else if (self::$extension === self::EXTENSION_TIDEWAYS) {
                tideways_disable();
            }
        }
    }

    private static function init($apiKey, $options)
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

        if ($options['framework']) {
            self::detectFramework($options['framework']);
        }

        if (function_exists('tideways_enable')) {
            self::$extension = self::EXTENSION_TIDEWAYS;
        } else if (function_exists('xhprof_enable')) {
            self::$extension = self::EXTENSION_XHPROF;
        }

        self::$mode = self::MODE_BASIC;
        self::$error = false;
        self::$trace = array(
            'apiKey' => $apiKey,
            'id' => self::generateRandomId(),
            'tx' => 'default',
        );

        if ($options['service']) {
            self::$trace['service'] = $options['service'];
        }
    }

    /**
     * Generates a random integer used for internal identification and correlation of traces.
     *
     * @return int
     */
    public static function generateRandomId()
    {
        return mt_rand(1, PHP_INT_MAX);
    }

    public static function setTransactionName($name)
    {
        self::$trace['tx'] = !empty($name) ? $name : 'default';
    }

    public static function setServiceName($name)
    {
        self::$trace['service'] = $name;
    }

    /**
     * @return int
     */
    public static function currentTraceId()
    {
        return isset(self::$trace['id']) ? self::$trace['id'] : 0;
    }

    public static function rootTraceId()
    {
        return isset(self::$trace['rid'])
            ? self::$trace['rid']
            : self::currentTraceId();
    }

    /**
     * @deprecated
     */
    public static function setOperationName($name)
    {
        self::setTransactionName($name);
    }

    public static function isStarted()
    {
        return self::$mode !== self::MODE_DISABLED;
    }

    public static function isProfiling()
    {
        return (self::$mode & self::MODE_PROFILING) > 0;
    }

    /**
     * Returns true if profiler is currently tracing spans.
     *
     * This can be used to check if adding X-TW-* HTTP headers makes sense.
     *
     * @return bool
     */
    public static function isTracing()
    {
        return (self::$mode & self::MODE_TRACING) > 0;
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
        if ((self::$mode & self::MODE_FULL) === 0 || !is_scalar($value)) {
            return;
        }

        if (!self::$currentRootSpan) {
            return;
        }

        self::$currentRootSpan->annotate(array($name => $value));
    }

    /**
     * Watch a function for calls and create timeline spans around it.
     *
     * @param string $function
     * @param string $category
     */
    public static function watch($function, $category = null)
    {
        if (self::$extension === self::EXTENSION_TIDEWAYS) {
            self::$defaultOptions['watches'][$function] = $category;

            if ((self::$mode & self::MODE_TRACING) > 0) {
                tideways_span_watch($function, $category);
            }
        }
    }

    /**
     * Watch a function and invoke a callback when its called.
     *
     * To start a span, call {@link \Tideways\Profiler::createSpan($category)}
     * inside the callback and return {$span->getId()}:
     *
     * @example
     *
     * \Tideways\Profiler::watchCallback('mysql_query', function ($context) {
     *     $span = \Tideways\Profiler::createSpan('sql');
     *     $span->annotate(array('title' => $context['args'][0]));
     *     return $span->getId();
     * });
     */
    public static function watchCallback($function, $callback)
    {
        if (self::$extension === self::EXTENSION_TIDEWAYS) {
            self::$defaultOptions['callbacks'][$function] = $callback;

            if ((self::$mode & self::MODE_TRACING) > 0) {
                tideways_span_callback($function, $callback);
            }
        }
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
        return (self::$mode & self::MODE_TRACING) > 0
            ? self::$currentRootSpan->createSpan($name)
            : new \Tideways\Traces\NullSpan();
    }

    /**
     * Stop all profiling actions and submit collected data.
     */
    public static function stop()
    {
        if (self::$mode === self::MODE_DISABLED) {
            return;
        }

        $mode = self::$mode;

        if (self::$trace['tx'] === 'default' && self::$extension === self::EXTENSION_TIDEWAYS) {
            self::$trace['tx'] = tideways_transaction_name() ?: 'default';
        }

        if (function_exists('tideways_last_detected_exception') && $exception = tideways_last_detected_exception()) {
            self::logException($exception);
        } elseif (function_exists("http_response_code") && http_response_code() >= 500) {
            self::logFatal("PHP request set error HTTP response code to '" . http_response_code() . "'.", "", 0, E_USER_ERROR);
        }

        $profilingData = array();

        if (($mode & self::MODE_FULL) > 0 || self::$error) {
            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $profilingData = tideways_disable();
            } elseif (self::$extension === self::EXTENSION_XHPROF) {
                $profilingData = xhprof_disable();
                self::$currentRootSpan->stopTimer();
            }

            $annotations = array('mem' => ceil(memory_get_peak_usage() / 1024));

            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $annotations['xhpv'] = phpversion('tideways');

                if (self::$defaultOptions['framework']) {
                    $annotations['framework'] = self::$defaultOptions['framework'];
                }
            } elseif (self::$extension === self::EXTENSION_XHPROF) {
                $annotations['xhpv'] = phpversion('xhprof');
            }

            if (extension_loaded('xdebug')) {
                $annotations['xdebug'] = '1';
            }
            $annotations['php'] = PHP_VERSION;
            $annotations['sapi'] = php_sapi_name();

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

                if (isset($_SERVER['QUERY_STRING'])) {
                    $annotations['query'] = $_SERVER['QUERY_STRING'];
                }

            } elseif ($annotations['sapi'] === "cli") {
                $annotations['title'] = basename($_SERVER['argv'][0]);
            }
        } else {
            self::$currentRootSpan->stopTimer();
            $annotations = array('mem' => ceil(memory_get_peak_usage() / 1024));
        }

        self::$currentRootSpan->annotate($annotations);

        if (($mode & self::MODE_PROFILING) > 0) {
            self::$trace['profdata'] = $profilingData ?: array();
        }

        self::$mode = self::MODE_DISABLED;

        $spans = self::$currentRootSpan->getSpans();

        if (self::$error === true || ($mode & self::MODE_FULL) > 0) {
            self::$trace['spans'] = $spans;
            self::$backend->socketStore(self::$trace);
        } else {
            self::$trace['spans'] = isset($spans[0]) ? array($spans[0]) : array(); // prevent flooding udp by accident
            self::$backend->udpStore(self::$trace);
        }
        self::$trace = null; // free memory
        self::$logLevel = 0;
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
        if (self::$error === true || !self::$currentRootSpan) {
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
            "err_msg" => $message,
            "err_source" => $file . ':' . $line,
            "err_exception" => 'EngineException', // Forward compatibility with PHP7
            "err_trace" => $trace,
        ));
    }

    public static function logException($exception)
    {
        if (is_string($exception)) {
            $exception = new \RuntimeException($exception);
        }

        if (self::$error === true || !self::$currentRootSpan || !($exception instanceof \Exception)) {
            return;
        }

        // We are only interested in the original exception
        while ($previous = $exception->getPrevious()) {
            $exception = $previous;
        }

        self::$error = true;
        self::$currentRootSpan->annotate(array(
            "err_msg" => $exception->getMessage(),
            "err_source" => $exception->getFile() . ':' . $exception->getLine(),
            "err_exception" => get_class($exception),
            "err_trace" => \Tideways\Profiler\BacktraceConverter::convertToString($exception->getTrace()),
        ));
    }

    public static function shutdown()
    {
        if (self::$mode === self::MODE_DISABLED) {
            return;
        }

        $lastError = error_get_last();

        if ($lastError && ($lastError["type"] === E_ERROR || $lastError["type"] === E_PARSE || $lastError["type"] === E_COMPILE_ERROR)) {
            $lastError['trace'] = function_exists('tideways_fatal_backtrace') ? tideways_fatal_backtrace() : null;

            self::logFatal($lastError['message'], $lastError['file'], $lastError['line'], $lastError['type'], $lastError['trace']);
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
                    self::start();
                } else if (php_sapi_name() === "cli" && ini_get("tideways.monitor_cli") && isset($_SERVER['argv'])) {
                    self::start(array('sample_rate' => 0, 'service' => 'cli'));
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
               ini_get("auto_prepend_file") &&
               file_exists(stream_resolve_include_path(ini_get("auto_prepend_file")));
    }

    /**
     * Log a message to the PHP error log when the defined log-level is higher
     * or equal to the messages log-level.
     *
     * @param int $level Logs message level. 1 = warning, 2 = notice, 3 = debug
     * @param string $message
     * @return void
     */
    public static function log($level, $message)
    {
        if ($level <= self::$logLevel) {
            $level = ($level === 3) ? "debug" : (($level === 2) ? "info" : "warn");
            error_log(sprintf('[%s] tideways - %s', $level, $message), 0);
        }
    }
}
// auto-starts the profiler if that is configured
\Tideways\Profiler::autoStart();
