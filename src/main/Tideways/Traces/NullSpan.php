<?php

namespace Tideways\Traces;

class NullSpan extends Span
{
    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public function startTimer()
    {
    }

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public function stopTimer()
    {
    }

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public function annotate(array $annotations)
    {
    }

    public function recordDuration($duration, $start = 0)
    {
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array();
    }
}
