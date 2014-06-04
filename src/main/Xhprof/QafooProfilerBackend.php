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

    public function storeMeasurement($operationName, $duration, $operationType)
    {
        $measurement = array('op' => $operationName, 'ot' => $operationType, 'wt' => round($duration / 1000), 'apiKey' => $this->apiKey);

        $fp = @stream_socket_client("udp://127.0.0.1:8135", $errno, $errstr, 1);
        stream_set_timeout($fp, 0, 20);

        if (!$fp) {
            return;
        }

        fwrite($fp, json_encode($measurement));
        fclose($fp);
    }

    public function storeProfile($operationName, array $data, array $customMeasurements)
    {
        if (!isset($data['main()']['wt']) || !$data['main()']['wt']) {
            return;
        }

        $data = json_encode(array(
            'apiKey' => $this->apiKey,
            'op' => $operationName,
            'data' => $data,
            'custom' => $customMeasurements,
        ));

        $fp = @stream_socket_client("unix:///tmp/qprofd.sock", $errno, $errstr, 0.005);

        if (!$fp) {
            return;
        }

        stream_set_timeout($fp, 0, 10000); // 10 milliseconds max
        @fwrite($fp, $data);
        @fclose($fp);
    }
}
