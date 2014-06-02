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

class FacebookBackend implements Backend
{
    private $directory;
    private $appName;

    public function __construct($directory, $appName)
    {
        $this->directory = $directory;
        $this->appName = $appName;
    }

    public function storeMeasurement($operationName, $duration, $operationType)
    {
        // ignore
    }

    public function storeProfile($operationName, array $data, array $customMeasurements)
    {
        file_put_contents(
            $this->directory . "/" . time() . "." . $this->appName . ".xhprof",
            serialize($data)
        );
    }
}
