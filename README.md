# Xhprof Collector

Wrapper around Xhprof API.

```php
<?php
$profiler = new \Xhprof\ProfileCollector(
    new \Xhprof\FacebookBackend('/tmp', 'myapp'),
    new \Xhprof\StartDecisions\AlwaysStart()
);

$profiler->start();

// now all your application code here

$profiler->stop("name of operation that was performed");
```

## Symfony Integration Example

```php
<?php

use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../app/AppKernel.php";

$profiler = new \Xhprof\ProfileCollector(
    new \Xhprof\FacebookBackend('/tmp', 'myapp'),
    new \Xhprof\StartDecisions\AlwaysStart()
);
$profiler->start();

$request = Request::createFromGlobals();
$kernel = AppKernel::createFromBuildProperties();

$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);

$profiler->stop($request->attributes->get('_controller', 'notfound'));
```
