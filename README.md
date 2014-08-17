# QafooLabs Profiler Client

PHP client Library for the QafooLabs Profiler.

Sign up for beta: https://profiler.qafoolabs.com

## Installation

There are two ways to install the Profiler Client:

1. Use Composer to install this library with:

   ```json
   {
       "require": {"qafoolabs/profiler":"@stable"}
   }
   ```

2. Download the single file from Github downloads

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
- Xhprof profiling is sampled at random intervals (defaults to 20% of all requests)
  and in the other cases just a wall-time of the full request and memory information
  is collected. You can overwrite the sampling rate by passing a value between 0 (0%) and 100 (100%) as a second
  argument to `QafooLabs\Profiler::start()`.

## Configuration

Qafoo Profiler follows [The Twelve-Factor App](http://12factor.net/) rules and is configured
via environment variables.

This allows you to configure the Profiler differently on each server:

- `QAFOO_PROFILER_DISABLED` controls if the profiler should be disabled on the server.
- `QAFOO_PROFILER_SAMPLERATE` controls the sample rate how often the profiler should sample full XHProf traces.
- `QAFOO_PROFILER_ENABLE_LAYERS` controls if XHProf should sample wall times of layers (DB, I/O, ...) in every request.
- `QAFOO_PROFILER_ENABLE_ARGUMENTS` controls if argument summaries of important functions such as DB, HTTP and filesystem calls should be traced.

For example you can configure this in your PHP FPM Pool configuration:

    env[QAFOO_PROFILER_DISABLED] = 0
    env[QAFOO_PROFILER_SAMPLERATE] = 10
    env[QAFOO_PROFILER_ENABLE_LAYERS] = 1
    env[QAFOO_PROFILER_ENABLE_ARGUMENTS] = 1

If you enable layers then a set of default functions is profiled in every request, this list contains:

* db
   * `PDO::__construct`
   * `PDO::exec`
   * `PDO::query`
   * `PDO::commit`
   * `PDOStatement::execute`
   * `mysql_query`
   * `mysqli_query`
   * `mysqli::query`
* http
   * `curl_exec`
   * `curl_multi_exec`
   * `curl_multi_select`
* io
   * `file_get_contents`
   * `file_put_contents`
   * `fopen`
   * `fsockopen`
   * `fgets`
   * `fputs`
   * `fwrite`
   * `file_exists`
* cache
   * `MemcachePool::get`
   * `MemcachePool::set`
   * `Memcache::connect`
   * `apc_fetch`
   * `apc_store`

You can change this list by explicitly passing your own definition of layers as
an option key `layers` in `QafooLabs\Profiler::start()`.

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

## Custom Variables

You can add custom variables to every full profiling trace:

```php
<?php

\QafooLabs\Profiler::setCustomVariable('url', $_SERVER['HTTP_HOST'] . '/' . $_SERVER['REQUEST_URI']);
\QafooLabs\Profiler::setCustomVariable('mysql_thread_id', mysql_thread_id());
```

## Development Mode

If you want to force collecting profiles during development and send them to
the Qafoo Profiler you can do so by using the `startDevelopment` method:

```php
<?php

\QafooLabs\Profiler::startDevelopment($apiKey);
```

Please note that this is very slow as the overhead of HTTP is present in every
request. It is not recommended to use this setting in production! We are also
rate-limiting our API endpoint and sending too many profiles will get you
blocked, the daemon throttles request automatically.

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
