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

/**
 * Transmit profiling data to the local Qafoo Profiler daemon via UDP+TCP.
 */
class QafooProfilerBackend implements Backend
{
    /**
     * @var string
     */
    private $apiKey;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function store(array $data, $operationName, $hostname, $ipAddress)
    {
        if (!isset($data['main()']['wt']) || !$data['main()']['wt']) {
            return;
        }

        $measurement = array('op' => $operationName, 'wt' => round($data['main()']['wt'] / 1000), 'apiKey' => $this->apiKey);

        $fp = @stream_socket_client("udp://127.0.0.1:8135", $errno, $errstr, 1);

        if (!$fp) {
            return;
        }

        fwrite($fp, json_encode($measurement));
        $shouldProfile = fread($fp, 1);
        fclose($fp);

        if (!$shouldProfile) {
            return;
        }

        $fp = @stream_socket_client("tcp://127.0.0.1:8136", $errno, $errstr, 1);

        if (!$fp) {
            return;
        }

        $profile = array(
            'apiKey' => $this->apiKey,
            'op' => $operationName,
            'data' => $data,
        );

        fwrite($fp, json_encode($profile));
        fclose($fp);
    }
}
