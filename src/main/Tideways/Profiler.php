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

use Tideways\Traces\DistributedId;

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
    );

    private static $trace;
    private static $currentRootSpan;
    private static $shutdownRegistered = false;
    private static $error = false;
    private static $mode = self::MODE_NONE;
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
        switch ($framework) {
            case self::FRAMEWORK_ZEND_FRAMEWORK1:
                self::$defaultOptions['transaction_function'] = 'Zend_Controller_Action::dispatch';
                self::$defaultOptions['exception_function'] = 'Zend_Controller_Response_Abstract::setException';
                break;

            case self::FRAMEWORK_ZEND_FRAMEWORK2:
                self::$defaultOptions['transaction_function'] = 'Zend\\MVC\\Controller\\ControllerManager::get';
                break;

            case self::FRAMEWORK_SYMFONY2_COMPONENT:
                self::$defaultOptions['transaction_function'] = 'Symfony\Component\HttpKernel\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_SYMFONY2_FRAMEWORK:
                self::$defaultOptions['transaction_function'] = 'Symfony\Bundle\FrameworkBundle\Controller\ControllerResolver::createController';
                self::$defaultOptions['exception_function'] = 'Symfony\Component\HttpKernel\HttpKernel::handleException';
                break;

            case self::FRAMEWORK_OXID:
                self::$defaultOptions['transaction_function'] = 'oxView::setClassName';
                self::$defaultOptions['exception_function'] = 'oxShopControl::_handleBaseException';
                break;

            case self::FRAMEWORK_SHOPWARE:
                self::$defaultOptions['transaction_function'] = 'Enlight_Controller_Action::dispatch';
                self::$defaultOptions['exception_function'] = 'Zend_Controller_Response_Abstract::setException';
                break;

            case self::FRAMEWORK_WORDPRESS:
                self::$defaultOptions['transaction_function'] = 'get_query_template';
                break;

            case self::FRAMEWORK_LARAVEL:
                self::$defaultOptions['transaction_function'] = 'Illuminate\Routing\Controller::callAction';
                self::$defaultOptions['exception_function'] = 'Illuminate\Foundation\Http\Kernel::reportException';
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
                self::$defaultOptions['transaction_function'] = 'TYPO3\Flow\Mvc\Controller\ActionController::callActionMethod';
                self::$defaultOptions['exception_function'] = 'TYPO3\Flow\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_TYPO3:
                self::$defaultOptions['transaction_function'] = 'TYPO3\CMS\Extbase\Mvc\Controller\ActionController::callActionMethod';
                self::$defaultOptions['exception_function'] = 'TYPO3\CMS\Error\AbstractExceptionHandler::handleException';
                break;

            case self::FRAMEWORK_CAKE2:
                self::$defaultOptions['transaction_function'] = 'Controller::invokeAction';
                break;

            case self::FRAMEWORK_CAKE3:
                self::$defaultOptions['transaction_function'] = 'Cake\\Controller\\Controller::invokeAction';
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
     * @param array|string      $options Either options array or api key (when string)
     * @param int               $sampleRate Deprecated, use "sample_rate" key in options instead.
     *
     * @return void
     */
    public static function start($options = array(), $sampleRate = null)
    {
        if (self::$mode !== self::MODE_NONE) {
            return;
        }

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
            'distributed_tracing_hosts' => isset($_SERVER['TIDEWAYS_ALLOWED_HOSTS']) ? $_SERVER['TIDEWAYS_ALLOWED_HOSTS'] : (ini_get("tideways.distributed_tracing_hosts") ?: '127.0.0.1'),
            'distributed_trace' => null,
            'log_level' => ini_get("tideways.log_level") ?: 0,
        );
        $options = array_merge($defaults, $options);

        if (strlen((string)$options['api_key']) === 0) {
            return;
        }

        self::$logLevel = $options['log_level'];
        self::init($options['api_key'], $options['distributed_trace'], $options['distributed_tracing_hosts']);
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

        if (self::$extension === self::EXTENSION_TIDEWAYS && (self::$mode !== self::MODE_NONE)) {
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
     * Decide in which mode to start collecting data.
     *
     * @param int $treshold (0-100)
     * @param array $options
     * @return int
     */
    private static function decideProfiling($treshold, array $options = array())
    {
        if (isset(self::$trace['pid']) && isset(self::$trace['sid']) && self::$trace['sid'] > 0) {
            self::log(3, "Found parent trace, starting child.");
            self::$trace['keep'] = true; // always keep
            self::enableProfiler(self::MODE_TRACING);
            return;
        }

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

                self::enableProfiler(self::MODE_FULL);
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
                : self::MODE_NONE;
        } else if (!is_int($mode)) {
            $mode = self::MODE_NONE;
        } else if (($mode & (self::MODE_FULL|self::MODE_BASIC)) === 0) {
            $mode = self::MODE_NONE;
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
        if (self::$mode !== self::MODE_NONE) {
            self::$mode = self::MODE_NONE;

            if (self::$extension === self::EXTENSION_XHPROF) {
                xhprof_disable();
            } else if (self::$extension === self::EXTENSION_TIDEWAYS) {
                tideways_disable();
            }
        }
    }

    private static function init($apiKey, $distributedId = null, $distributedTracingHosts)
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
        self::$error = false;
        self::$trace = array(
            'apiKey' => $apiKey,
            'id' => self::generateRandomId(),
            'tx' => 'default',
        );

        if ($distributedId === null &&
            isset($_SERVER['HTTP_X_TW_TRACEID']) &&
            isset($_SERVER['HTTP_X_TW_SPANID']) &&
            self::allowAutoStartDistributedTracing($distributedTracingHosts)) {

            $distributedId = new DistributedId(
                (int)$_SERVER['HTTP_X_TW_SPANID'],
                (int)$_SERVER['HTTP_X_TW_TRACEID'],
                isset($_SERVER['HTTP_X_TW_ROOTID']) ? (int)$_SERVER['HTTP_X_TW_ROOTID'] : null
            );
        }

        if ($distributedId instanceof DistributedId) {
            self::$trace['sid'] = $distributedId->parentSpanId;
            self::$trace['pid'] = $distributedId->parentTraceId;
            self::$trace['rid'] = $distributedId->rootTraceId;
        }
    }

    /**
     * Check if the current requests ip-address indicates it comes
     * from an allowed host to enable distributed tracing.
     *
     * @param string $allowedHosts
     * @return bool
     */
    private static function allowAutoStartDistributedTracing($allowedHosts)
    {
        $trustedProxies = array();
        $headerName = false;

        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return false;
        }

        $checkIp = $_SERVER['REMOTE_ADDR'];

        if (strpos($allowedHosts, ':') !== false) {
            $parts = explode(':', $allowedHosts);

            if (count($parts) !== 3) {
                return false;
            }

            $headerName = "HTTP_" . strtoupper(str_replace("-", "_", $parts[0]));
            $trustedProxies = explode(',', $parts[1]);
            $allowedHosts = explode(',', $parts[2]);

            if (isset($_SERVER[$headerName])) {
                $proxies = explode(',', $_SERVER[$headerName]);
                $proxies[] = $_SERVER['REMOTE_ADDR'];

                foreach (array_reverse($proxies) as $proxyIp) {
                    if (!in_array($proxyIp, $trustedProxies)) {
                        $checkIp = $proxyIp;
                        break;
                    }
                }
            }
        } else {
            $allowedHosts = explode(',', $allowedHosts);
        }

        return in_array($checkIp, $allowedHosts);
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
        return self::$mode !== self::MODE_NONE;
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
        if (self::$mode === self::MODE_NONE) {
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

        if (($mode & self::MODE_FULL) > 0) {
            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $profilingData = tideways_disable();
            } elseif (self::$extension === self::EXTENSION_XHPROF) {
                $profilingData = xhprof_disable();
                self::$currentRootSpan->stopTimer();
            }

            $annotations = array('mem' => ceil(memory_get_peak_usage() / 1024));

            if (self::$extension === self::EXTENSION_TIDEWAYS) {
                $annotations['xhpv'] = phpversion('tideways');
            } elseif (self::$extension === self::EXTENSION_XHPROF) {
                $annotations['xhpv'] = phpversion('xhprof');
            }

            if (extension_loaded('xdebug')) {
                $annotations['xdebug'] = '1';
            }
            $annotations['php'] = PHP_VERSION;

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

            } elseif (php_sapi_name() === "cli") {
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

        self::$mode = self::MODE_NONE;

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

    private static function createRootSpan()
    {

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
        if (self::$mode === self::MODE_NONE) {
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
