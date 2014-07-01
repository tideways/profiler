# QafooLabs Profiler Client

PHP client Library for the QafooLabs Profiler to use in your projects
if you dont want or cannot use the pecl extension.

## Installation

There are three ways to install the Profiler Client:

1. Use the QafooLabs Profiler PECL Extension
   Download the `qafooprofiler.so` for your architecture from the Downloads page
   put it into `/usr/lib/php5/<yourapiversion>/qafooprofiler.so` and integrate in php.ini
2. Use Composer to install this library with:

   ```json
   {
       "require": {"qafoolabs/profiler":"@stable"}
   }
   ```

3. Copy the `src/main/QafooLabs/Profiler.php` file into your project and call it via `require`.

The profiler class is intentially monolothic and static to allow easy
integration in your projects.

## Requirements

You need to have the `xhprof` Extension installed, otherwise just wall time
measurements will be made.

The Qafoo Profiler Daemon (qprofd) has to be running on the production/staging
machine that you are testing.

On development machines you can transfer the profiles directly to Qafoo Profiler UI
without the daemon.

## Integration

```php
<?php

\QafooLabs\Profiler::start("api key here");

// now all your application code here
\QafooLabs\Profiler::setTransactionName("controller+action name");
```

Notes:

- There is a method `QafooLabs\Profiler::stop()` but calling it is optional, a
  shutdown handler will take care of it.
- If you are using the PECL extension should guard calls to the profiler with `if (class_exists('QafooLabs\Profiler'))`
  to avoid fatal errors when the extension is not installed.
- Xhprof profiling is sampled at random intervals (defaults to 10% request at the moment)
  and in the other cases just a wall-time of the full request and memory information
  is collected. You can overwrite the sampling rate by passing a value between 0 (0%) and 10000 (100%) as a second
  argument to `QafooLabs\Profiler::start()`.

## Custom Timers

You can append custom timing data for example to profile SQL statements:

```php
<?php

$sql = 'SELECT 1';
$timerId = \QafooLabs\Profiler::startSqlCustomTimer($sql);
mysql_query($sql);
\QafooLabs\Profiler::stopCustomTimer($timerId);
```

Using `startSqlCustomTimer` triggers anonymization that replaces all literals
and numbers with question marks.

You can also time any other type of code in your application:

```php
<?php

$timerId = \QafooLabs\Profiler::startCustomTimer("solr", "q=foo");
\QafooLabs\Profiler::stopCustomTimer($timerId);
```

## Development Mode

If you want to force collecting profiles during development and send them to
the Qafoo Profiler you can do so by using the `startDevelopment` method:

```php
<?php

\QafooLabs\Profiler::startDevelopment($apiKey);
```

Please note that this is very slow in production as the overhead of HTTP is present
in every request. We are also rate-limiting our API endpoint and sending too many
profiles will get you blocked.

## Correlation Ids

You can correlate several requests by adding a correlation id that the profiler
will combine in the UI:

```php
<?php

\QafooLabs\Profiler::setCorrelationId($uuid);
```

## Symfony Integration Example

```php
<?php

use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . "/../vendor/autoload.php";
require_once __DIR__ . "/../app/AppKernel.php";

\QafooLabs\Profiler::start("api key here");

$request = Request::createFromGlobals();
$kernel = AppKernel::createFromBuildProperties();

$response = $kernel->handle($request);
$response->send();

\QafooLabs\Profiler::setTransactionName($request->attributes->get('_controller', 'notfound'));

$kernel->terminate($request, $response);
```
