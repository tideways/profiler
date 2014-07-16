<?php

namespace QafooLabs\Profiler;

class CurlBackend implements Backend
{
    private $certificationFile;
    private $connectionTimeout;
    private $timeout;

    public function __construct($certificationFile = null, $connectionTimeout = 1, $timeout = 1)
    {
        $this->certificationFile = $certificationFile;
        $this->connectionTimeout = $connectionTimeout;
        $this->timeout = $timeout;
    }

    public function storeProfile(array $data)
    {
        if (function_exists("curl_init") === false) {
            return;
        }

        $this->request("https://profiler.qafoolabs.com/api/profile/create", $data);
    }

    public function storeMeasurement(array $data)
    {
    }

    public function storeError(array $data)
    {
    }

    private function request($url, $data)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectionTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($this->certificationFile) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_CAINFO, $this->certificationFile);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Content-Type: application/json",
            "UserAgent: QafooLabs Profiler Collector DevMode"
        ));

        curl_exec($ch);
    }
}
