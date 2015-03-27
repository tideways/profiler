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

namespace Tideways\Profiler;

/**
 * Anonymize SQL statements.
 *
 * To avoid passing private data to the profiler services you have to anonymize
 * SQL statements that are collected as custom timers.
 *
 * The class detects all strings and numbers in sql statements and replaces
 * them with question marks (?).
 *
 * If your library such as Doctrine DBAL only uses prepared statements anyways
 * and you don't put user input into SQL yourself, then you can skip this step.
 *
 * @TODO: Rename to something more meaningful, since it also anonymizes messages.
 */
class SqlAnonymizer
{
    /**
     * @var string
     */
    const SPLIT_NUMBERS_AND_QUOTED_STRINGS = '(("[^"]+"|\'[^\']+\'|([0-9]*\.)?[0-9]+))';

    /**
     * Anonymize SQL string.
     *
     * Splits a SQL at quoted literals and numbers, replacing them with
     * question marks for anonymization.
     *
     * @param string $sql
     * @return string
     */
    static public function anonymize($sql)
    {
        return preg_replace(self::SPLIT_NUMBERS_AND_QUOTED_STRINGS, '?', $sql);
    }
}
