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
     * 32/64 bit random integer.
     *
     * @return int
     */
    public abstract function getId();

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
     * @param int $duration
     * @param int $start
     */
    public abstract function recordDuration($duration, $start = null);

    /**
     * Annotate span with metadata.
     *
     * @param array<string,scalar>
     */
    public abstract function annotate(array $annotations);

    /**
     * @return array
     */
    public abstract function toArray();
}
