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

class OrStart
{
    private $left;
    private $right;

    public function __construct(StartDecision $left, StartDecision $right)
    {
        $this->left = $left;
        $this->right = $right;
    }

    public function shouldProfile()
    {
        return $this->left->shouldProfile() || $this->right->shouldProfile();
    }
}
