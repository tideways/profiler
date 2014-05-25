<?php
/**
 * Xhprof Client
 *
 * LICENSE
 *
 * This source file is subject to the MIT license that is bundled
 * with this package in the file LICENSE.txt.
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to kontakt@beberlei.de so I can send you a copy immediately.
 */

namespace Xhprof\StartDecisions;

use Xhprof\StartDecision;

class SampleStart implements StartDecision
{
    /**
     * @var int
     */
    private $percentage;

    public function __construct($percentage)
    {
        $this->percentage = $percentage;
    }

    public function shouldProfile()
    {
        return $this->percentage >= rand(1, 100);
    }
}

