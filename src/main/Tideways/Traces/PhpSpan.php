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

use Tideways\Profiler;

/**
 * When Tideways PHP extension is not installed the span API
 * is handled in memory.
 */
class PhpSpan extends Span
{
    const NAME = 'n';
    const STARTS = 'b';
    const STOPS = 'e';
    const ANNOTATIONS = 'a';

    /**
     * @var array
     */
    private static $spans = array();

    /**
     * @var bool
     */
    private $timerRunning = false;

    static public function createSpan($name = null)
    {
        $idx = count(self::$spans);
        return new self($idx, $name);
    }

    static public function clear()
    {
        self::$spans = array();
    }

    static public function getSpans()
    {
        return self::$spans;
    }

    public function __construct($idx, $name = null)
    {
        $this->id = $idx;
        self::$spans[$idx] = array(
            self::STARTS => array(),
            self::STOPS => array(),
            self::ANNOTATIONS => array(),
        );
        if ($name) {
            self::$spans[$idx][self::NAME] = $name;
        }
    }

    public function startTimer()
    {
        if ($this->timerRunning) {
            return;
        }

        self::$spans[$this->id][self::STARTS][] = Profiler::currentDuration();
        $this->timerRunning = true;
    }

    public function stopTimer()
    {
        if (!$this->timerRunning) {
            return;
        }

        self::$spans[$this->id][self::STOPS][] = Profiler::currentDuration();
        $this->timerRunning = false;
    }

    public function annotate(array $annotations)
    {
        foreach ($annotations as $name => $value) {
            if (!is_scalar($value)) {
                continue;
            }

            self::$spans[$this->id][self::ANNOTATIONS][$name] = (string)$value;
        }
    }

    public function recordDuration($duration, $start = 0)
    {
        if ($this->timerRunning) {
            return;
        }

        self::$spans[$this->id][self::STARTS][] = (int)$start;
        self::$spans[$this->id][self::STOPS][] = (int)($start + $duration);
    }

    public function toArray()
    {
        return self::$spans[$this->id];
    }
}
