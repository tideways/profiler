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
        set_error_handler(array(__CLASS__, "ignoreErrorsHandler"));
        $fp = stream_socket_client($this->socketFile);

        if ($fp == false) {
            \Tideways\Profiler::log(1, "Cannot connect to socket for storing trace.");
            restore_error_handler();
            return;
        }

        $payload = json_encode(array('type' => self::TYPE_TRACE, 'payload' => $trace));

        stream_set_timeout($fp, 0, 10000); // 10 milliseconds max
        if (fwrite($fp, $payload) < strlen($payload)) {
            \Tideways\Profiler::log(1, "Could not write payload to socket.");
        }
        fclose($fp);
        restore_error_handler();
        \Tideways\Profiler::log(3, "Sent trace to socket.");
    }

    public function udpStore(array $trace)
    {
        set_error_handler(array(__CLASS__, "ignoreErrorsHandler"));
        $fp = stream_socket_client("udp://" . $this->udp);

        if ($fp == false) {
            \Tideways\Profiler::log(1, "Cannot connect to UDP port for storing trace.");
            restore_error_handler();
            return;
        }

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
