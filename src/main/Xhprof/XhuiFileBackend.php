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

class XhuiFileBackend implements Backend
{
    private $directory;

    public function __construct($directory)
    {
        $this->directory = $directory;
    }

    public function store(array $data, $operationName, $hostname, $ipAddress)
    {
        $profile = array(
            'operation' => $operationName,
            'server' => array(
                'ip' => $ipAddress,
                'hostname' => $hostname
            ),
            'profile' => $data,
            'date' => time(),
        );

        file_put_contents($this->directory . "/xhui_" . time() . ".json.gz", gzcompress(json_encode($profile)));
    }
}
