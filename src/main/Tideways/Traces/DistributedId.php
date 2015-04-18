<?php

namespace Tideways\Traces;

class DistributedId
{
    public $rootTraceId;
    public $parentTraceId;
    public $parentSpanId;

    public function __construct($parentSpanId, $parentTraceId, $rootTraceId = null)
    {
        if (!is_int($parentSpanId) || !is_int($parentTraceId) || ($rootTraceId !== null && !is_int($rootTraceId))) {
            throw new \InvalidArgumentException("DistributedId must consist of integer values.");
        }

        $this->parentTraceId = $parentTraceId;
        $this->parentSpanId = $parentSpanId;
        $this->rootTraceId = $rootTraceId ?: $parentSpanId;
    }
}
