<?php
/**
 * Xhprof Client
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Xhprof;

class ProfileCollector
{
    const TYPE_WEB = 1;
    const TYPE_WORKER = 2;

    private $backend;
    private $starter;
    private $started = false;
    private $shutdownRegistered = false;
    private $operationName;
    private $customMeasurements = array();
    private $operationType = self::TYPE_WEB;

    public function __construct(Backend $backend, StartDecision $starter)
    {
        $this->backend = $backend;
        $this->starter = $starter;
    }

    public function start()
    {
        if ($this->started) {
            return;
        }

        $this->operationName = null;
        $this->customMeasurements = array();
        $this->started = microtime(true);
        $this->profiling = $this->starter->shouldProfile();
        $this->operationType = php_sapi_name() === 'cli' ? self::TYPE_WORKER : TYPE_WEB;

        if ( ! $this->shutdownRegistered) {
            $this->shutdownRegistered = true;
            register_shutdown_function(array($this, 'shutdown'));
        }

        if ( ! $this->profiling) {
            return;
        }

        xhprof_enable();
    }

    public function shutdown()
    {
        $lastError = error_get_last();

        if ($lastError['type'] === E_ERROR || $lastError['type'] === E_PARSE || $lastError['type'] === E_COMPILE_ERROR) {
            return $this->logFatal($lastError['message'], $lastError['file'], $lastError['line'], $lastError['type']);
        }

        $this->stop();
    }

    public function logFatal($message, $file, $line, $type = E_USER_ERROR)
    {
        // not implemented yet
    }

    public function setOperationType($operationType)
    {
        $this->operationType = $operationType;
    }

    public function setOperationName($operationName)
    {
        $this->operationName = $operationName;
    }

    public function addCustomMeasurement($name, $wallTime, $parent = 'main()')
    {
        if ( ! $this->started || ! $this->profiling) {
            return;
        }

        if ( ! isset($this->customMeasurements[$parent][$name])) {
            $this->customMeasurements[$parent][$name] = array('ct' => 0, 'wt' => 0);
        }

        $this->customMeasurements[$parent][$name]['wt'] += $wallTime;
        $this->customMeasurements[$parent][$name]['ct']++;
    }

    public function stop($operationName = null)
    {
        if ( ! $this->started) {
            return;
        }

        $data = xhprof_disable();
        $duration = microtime(true) - $this->started;
        $this->started = false;

        if ($operationName) {
            $this->operationName = $operationName;
        }

        if ( ! $this->operationName) {
            $this->operationName = $this->guessOperationName();
        }

        if ($this->profiling) {
            $this->backend->storeProfile($this->operationName, $data, $this->customMeasurements);
        } else {
            $this->backend->storeMeasurement($this->operationName, $duration, $this->operationType);
        }
    }

    private function guessOperationName()
    {
        if (php_sapi_name() === 'cli') {
            return basename($_SERVER['argv'][0]);
        }

        $uri = strpos($_SERVER['REQUEST_URI'], '?')
            ? substr($_SERVER['REQUEST_URI'], 0, strpos($_SERVER['REQUEST_URI'], '?'))
            : $_SERVER['REQUEST_URI'];

        return $_SERVER['REQUEST_METHOD'] . ' ' . $uri;
    }

    public function isStarted()
    {
        return $this->started;
    }
}
