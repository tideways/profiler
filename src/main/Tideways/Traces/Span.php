<?php
/**
 * Tideways
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Tideways\Traces;

/**
 * Abstraction for trace spans.
 *
 * Different implementations based on support
 */
abstract class Span
{
    /**
     * @var int
     */
    protected $id;

    public function getId()
    {
        return $this->id;
    }

    /**
     * Record start of timer in microseconds.
     *
     * If timer is already running, don't record another start.
     */
    public abstract function startTimer();

    /**
     * Record stop of timer in microseconds.
     *
     * If timer is not running, don't record.
     */
    public abstract function stopTimer();

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public abstract function annotate(array $annotations);

    /**
     * If no timer is started, record a single start/stop timer event.
     *
     * @param int $start
     * @param int $stop
     */
    public abstract function record($start, $end);

    /**
     * @return array
     */
    public abstract function toArray();
}
