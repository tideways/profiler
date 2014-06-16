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
 *      QafooLabs\Profiler::setOperationName("my operation");
 *
 * Calling the {@link stop()} method is not necessary as it is
 * called automatically from a shutdown handler.
 *
 * The method {@link setOperationName} is required, failing to call
 * it will result in discarding of the data. You can automatically
 * guess a name using the following snippet:
 *
 *      QafooLabs\Profiler::setOperationName(QafooLabs\Profiler::guessOperationName());
 *
 * If you want to collect custom timing data you can use:
 *
 *      $id = QafooLabs\Profiler::startCustomTimer("sql", "SELECT 1");
 *      mysql_query("SELECT 1");
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
    private static $correlationId;

    public static function startDevelopment($apiKey)
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

        xhprof_enable();
    }

    public static function start($apiKey, $force = false)
    {
        if (self::$started) {
            return;
        }

        if (strlen($apiKey) === 0) {
            return;
        }

        self::init(php_sapi_name() == "cli" ? self::TYPE_WORKER : self::TYPE_WEB, $apiKey);

        if ($force) {
            self::$profiling = true;
        } else {
            self::$profiling = self::decideProfiling();
        }

        if (self::$profiling == false) {
            return;
        }

        xhprof_enable();
    }

    private static function decideProfiling()
    {
        if (function_exists("xhprof_enable") == false) {
            return false;
        }

        if (isset($_GET["_qprofiler"]) && $_GET["_qprofiler"] === md5(self::$apiKey)) {
            return true;
        }

        $treshold = 100;
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

    private static function init($type, $apiKey)
    {
        if (self::$shutdownRegistered == false) {
            register_shutdown_function(array("QafooLabs\\Profiler", "shutdown"));
            self::$shutdownRegistered = true;
        }

        self::$apiKey = $apiKey;
        self::$customTimers = array();
        self::$customTimerCount = 0;
        self::$operationName = null;
        self::$error = false;
        self::$operationType = $type;
        self::$started = microtime(true);
    }

    public static function setCorrelationId($id)
    {
        self::$correlationId = $id;
    }

    public static function setOperationName($name)
    {
        self::$operationName = $name;
    }

    public static function startCustomTimer($group, $identifier)
    {
        if (self::$started == false || self::$profiling == false) {
            return;
        }

        self::$customTimers[self::$customTimerCount] = array("s" => microtime(true), "id" => $identifier, "group" => $group);
        self::$customTimerCount++;

        return self::$customTimerCount - 1;
    }

    public static function stopCustomTimer($id)
    {
        if (!isset(self::$customTimers[$id]) || isset(self::$customTimers[$id]["wt"])) {
            return false;
        }

        self::$customTimers[$id]["wt"] = intval(round((microtime(true) - self::$customTimers[$id]["s"]) * 1000000));
        unset(self::$customTimers[$id]["s"]);
        return true;
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
        if (self::$profiling) {
            $data = xhprof_disable();
        }
        $duration = microtime(true) - self::$started;
        self::$started = false;

        if (!self::$operationName) {
            return;
        }

        if (self::$error) {
            // nothing yet, send errors later
            return;
        }

        if (self::$operationType === self::TYPE_DEV) {
            self::storeDevProfile(self::$operationName, $data, self::$customTimers);
            return;
        }

        if (self::$profiling) {
            self::storeProfile(self::$operationName, $data, self::$customTimers, self::$operationType);
        } else {
            self::storeMeasurement(self::$operationName, intval(round($duration * 1000)), self::$operationType);
        }
    }

    private static function storeDevProfile($operationName, $data, $customTimers)
    {
        if (!isset($data["main()"]["wt"]) || !$data["main()"]["wt"]) {
            return;
        }

        if (function_exists("curl_init") === false) {
            return;
        }

        $ch = curl_init("https://profiler.qafoolabs.com/api/profile/create");
        curl_setopt($ch, CURLOPT_TIMEOUT, 2);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (file_exists('/etc/ssl/certs/ca-certificates.crt')) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, "/etc/ssl/certs/ca-certificates.crt");
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array(
            "apiKey" => self::$apiKey,
            "op" => $operationName,
            "ot" => self::TYPE_DEV,
            "cid" => (string)self::$correlationId,
            "mem" => round(memory_get_peak_usage() / 1024),
            "data" => $data,
            "custom" => $customTimers,
            "server" => gethostname(),
        )));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "UserAgent: QafooLabs Profiler Collector DevMode"
        ]);
        curl_exec($ch);
    }

    private static function storeProfile($operationName, $data, $customTimers, $operationType)
    {
        if (!isset($data["main()"]["wt"]) || !$data["main()"]["wt"]) {
            return;
        }

        $old = error_reporting(0);
        $fp = stream_socket_client("unix:///tmp/qprofd.sock");
        error_reporting($old);

        if ($fp == false) {
            return;
        }

        $old = error_reporting(0);
        stream_set_timeout($fp, 0, 10000); // 10 milliseconds max
        fwrite($fp, json_encode(array(
            "op" => $operationName,
            "data" => $data,
            "custom" => $customTimers,
            "apiKey" => self::$apiKey,
            "ot" => $operationType,
            "mem" => round(memory_get_peak_usage() / 1024),
            "cid" => (string)self::$correlationId
        )));
        fclose($fp);
        error_reporting($old);
    }

    private static function storeMeasurement($operationName, $duration, $operationType)
    {
        $old = error_reporting(0);
        $fp = stream_socket_client("udp://127.0.0.1:8135");
        error_reporting($old);

        if ($fp == false) {
            return;
        }

        $old = error_reporting(0);
        stream_set_timeout($fp, 0, 200);
        fwrite($fp, json_encode(array(
            "op" => $operationName,
            "ot" => $operationType,
            "wt" => $duration,
            "mem" => round(memory_get_peak_usage() / 1024),
            "apiKey" => self::$apiKey
        )));
        fclose($fp);
        error_reporting($old);
    }

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
