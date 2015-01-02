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

namespace QafooLabs\Profiler;

class NetworkBackend implements Backend
{
    const TYPE_PROFILE = 'profile';
    const TYPE_ERROR = 'error';

    private $socketFile;
    private $udp;

    public function __construct($socketFile = "unix:///tmp/qprofd.sock", $udp = "127.0.0.1:8135")
    {
        $this->socketFile = $socketFile;
        $this->udp = $udp;
    }

    public static function ignoreErrorsHandler($errno, $errstr, $errfile, $errline)
    {
        // ignore all errors!
    }

    public function storeProfile(array $data)
    {
        $this->storeThroughFileSocket(self::TYPE_PROFILE, $data);
    }

    private function storeThroughFileSocket($dataType, array $data)
    {
        set_error_handler(array(__CLASS__, "ignoreErrorsHandler"));
        $fp = stream_socket_client($this->socketFile);

        if ($fp == false) {
            restore_error_handler();
            return;
        }

        stream_set_timeout($fp, 0, 10000); // 10 milliseconds max
        fwrite($fp, json_encode(array('type' => $dataType, 'payload' => $data)));
        fclose($fp);
        restore_error_handler();
    }

    public function storeMeasurement(array $data)
    {
        set_error_handler(array(__CLASS__, "ignoreErrorsHandler"));
        $fp = stream_socket_client("udp://" . $this->udp);

        if ($fp == false) {
            restore_error_handler();
            return;
        }

        stream_set_timeout($fp, 0, 200);
        fwrite($fp, json_encode($data, JSON_FORCE_OBJECT));
        fclose($fp);
        restore_error_handler();
    }

    public function storeError(array $data)
    {
        $this->storeThroughFileSocket(self::TYPE_ERROR, $data);
    }
}
