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

namespace Xhprof;

class ProfileCollector
{
    private $backend;
    private $starter;
    private $started;

    public function __construct(Backend $backend, StartDecision $starter)
    {
        $this->backend = $backend;
        $this->starter = $starter;
    }

    public function start()
    {
        if ( ! $this->starter->shouldProfile() || $this->started) {
            return;
        }

        xhprof_enable();
        $this->started = true;
    }

    public function stop($operationName = null)
    {
        if (!$this->started) {
            return;
        }

        $data = xhprof_disable();
        $hostname = gethostname();
        $ipAddress = isset($_SERVER['SERVER_ADDR']) ? $_SERVER['SERVER_ADDR'] : gethostbyname(gethostname());

        $this->backend->store($data, $operationName, $hostname, $ipAddress);
    }
}
