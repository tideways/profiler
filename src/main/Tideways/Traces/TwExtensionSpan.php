<?php

namespace Tideways\Traces;

class TwExtensionSpan extends Span
{
    /**
     * @var int
     */
    private $idx;

    public function createSpan($name = null)
    {
        return new self(tideways_span_create($name));
    }

    public function getSpans()
    {
        return tideways_get_spans();
    }

    public function __construct($idx)
    {
        $this->idx = $idx;
    }

    /**
     * 32/64 bit random integer.
     *
     * @return int
     */
    public function getId()
    {
    }

    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public function startTimer()
    {
        tideways_span_timer_start($this->idx);
    }

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public function stopTimer()
    {
        tideways_span_timer_stop($this->idx);
    }

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public function annotate(array $annotations)
    {
        tideways_span_annotate($this->idx, $annotations);
    }
}
