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
    public function storeDevProfile(array $data)
    {
        if (function_exists("curl_init") === false) {
            return;
        }

        $ch = curl_init("https://profiler.qafoolabs.com/api/profile/create");
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
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
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "UserAgent: QafooLabs Profiler Collector DevMode"
        ]);

        if (curl_exec($ch) === false) {
            $msg = curl_error($ch);

            if (strpos($msg, 'Operation timed out') === false) {
                throw new \RuntimeException("Failure while pushing profiling data to Qafoo Profiler: " . $msg);
            }
        }
    }

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
        fwrite($fp, json_encode($data));
        fclose($fp);
        error_reporting($old);
    }

    public function storeError(array $data)
    {
    }
}
