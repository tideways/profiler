<?php

namespace Xhprof\StartDecisions;

use Xhprof\StartDecision;

class ApiKeyHashStart implements StartDecision
{
    private $apiKey;

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    public function shouldProfile()
    {
        return isset($_GET['_qprofile']) && $_GET['_qprofile'] === md5($this->apiKey);
    }
}
