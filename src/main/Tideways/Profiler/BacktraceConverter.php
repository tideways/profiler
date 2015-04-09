<?php
/**
 * Tideways Profiler PHP Library
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Tideways\Profiler;

/**
 * Convert a Backtrace to a String like {@see Exception::getTraceAsString()} would do.
 */
class BacktraceConverter
{
    static public function convertToString(array $backtrace)
    {
        $trace = '';

        foreach ($backtrace as $k => $v) {
            if (!isset($v['function']) || !isset($v['args'])) {
                continue;
            }

            $args = implode(', ', array_map(function ($arg) {
                return (is_object($arg)) ? get_class($arg) : gettype($arg);
            }, $v['args']));

            $trace .= '#' . ($k) . ' ' . $v['file'] . '(' . $v['line'] . '): ' . (isset($v['class']) ? $v['class'] . '->' : '') . $v['function'] . '(' . $args .')' . "\n";
        }

        return $trace;
    }
}
