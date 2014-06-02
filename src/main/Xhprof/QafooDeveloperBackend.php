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
 * Directly transfer profiles to the UI.
 *
 * Beware, the API Server will aggressivly rate-limit if you send too many profiles.
 */
class QafooDeveloperBackend implements Backend
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
        // does nothing
    }

    public function storeProfile($operationName, array $data, array $customMeasurements)
    {
        if (!isset($data['main()']['wt']) || !$data['main()']['wt']) {
            return;
        }

        $profile = array(
            'apiKey' => $this->apiKey,
            'op' => $operationName,
            'data' => $data,
            'custom' => $customMeasurements,
            'hostname' => gethostname(),
            'ipAddress' => isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : gethostname()
        );

        $ch = curl_init('https://mabilis.qafoolabs.com/api/profile/create');
        curl_setopt_array($ch, array(
            CURLOPT_TIMEOUT => 30,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_SSL_VERIFYPEER => TRUE,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CAINFO => __DIR__ . '/../../resources/cabundle.crt',
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($profile),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'User-Agent' => 'QafooLabs Xhprof Collector DevMode'),
        ));
        curl_exec($ch);
    }
}
