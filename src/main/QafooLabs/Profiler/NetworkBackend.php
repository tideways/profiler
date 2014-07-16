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
    public function storeProfile(array $data)
    {
        $old = error_reporting(0);
        $fp = stream_socket_client("unix:///tmp/qprofd.sock");
        error_reporting($old);

        if ($fp == false) {
            return;
        }

        $old = error_reporting(0);
        stream_set_timeout($fp, 0, 10000); // 10 milliseconds max
        fwrite($fp, json_encode($data));
        fclose($fp);
        error_reporting($old);
    }

    public function storeMeasurement(array $data)
    {
        $old = error_reporting(0);
        $fp = stream_socket_client("udp://127.0.0.1:8135");
        error_reporting($old);

        if ($fp == false) {
            return;
        }

        $old = error_reporting(0);
        stream_set_timeout($fp, 0, 200);
        fwrite($fp, json_encode($data, JSON_FORCE_OBJECT));
        fclose($fp);
        error_reporting($old);
    }

    public function storeError(array $data)
    {
    }
}
