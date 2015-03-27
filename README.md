# Tideways PHP Library

PHP Client Library for [Tideways PHP Profiler](https://tideways.io) platform.

## Installation

There are two ways to install the Profiler Client:

1. Use Composer to install this library with:

   ```json
   {
       "require": {"tideways/profiler":"@stable"}
   }
   ```

2. Download the single file from Github downloads

The profiler class is intentially monolothic and static to allow easy
integration in your projects.

## Requirements

You need to have the `xhprof` or `tideways` PHP extensions installed to allow profiling.

The Tideways Daemon (`tidewaysd`) has to be running on the production/staging
machine that you are testing.

## Integration

```php
<?php

\Tideways\Profiler::start("api key here", 20); // 20% Sample-Rate

// now all your application code here
\Tideways\Profiler::setTransactionName("controller+action name");
```
Notes:

- There is a method `Tideways\Profiler::stop()` but calling it is optional, a
  shutdown handler will take care of it in a web-request.
- Xhprof profiling is sampled at random intervals (defaults to 20% of all
  requests) and in the other cases just a wall-time of the full request and
  memory information is collected. You can overwrite the sampling rate by
  passing a value between 0 (0%) and 100 (100%) as a second argument to
  `Tideways\Profiler::start()`.

## Configuration

Tideways follows [The Twelve-Factor App](http://12factor.net/) rules and is configured
via environment variables.

This allows you to configure the Profiler differently on each server without having to change
the code.

- `TIDEWAYS_DISABLED` controls if the profiler should be disabled on the server.
- `TIDEWAYS_SAMPLERATE` controls the sample rate how often the profiler should sample full XHProf traces.
- `TIDEWAYS_DISABLE_SESSIONS` controls if explicit developer sessions are allowed.

For example you can configure this in your PHP FPM Pool configuration:

    env[TIDEWAYS_DISABLED] = 0
    env[TIDEWAYS_SAMPLERATE] = 10
    env[TIDEWAYS_DISABLE_SESSIONS] = 0

### Framework Detection

Every request should be grouped by a transaction name that captures the name of the executed controller/action
in your application. This is usually done with `\Tideways\Profiler::setTransactionName()`.
For various popular frameworks we have added support to detect the transaction names automatically while profiling.
To enable a framework just call `\Tideways\Profiler::detectFrameworkTransaction` with one of the following constants:

- `Tideways\Profiler::FRAMEWORK_ZEND_FRAMEWORK1` for Zend Framework1 apps
- `Tideways\Profiler::FRAMEWORK_ZEND_FRAMEWORK2` for Zend Framework2 apps
- `Tideways\Profiler::FRAMEWORK_SYMFONY2_FRAMEWORK` for Symfony2 apps
- `Tideways\Profiler::FRAMEWORK_SHOPWARE` for Shopware apps
- `Tideways\Profiler::FRAMEWORK_OXID` for Oxid apps
- `Tideways\Profiler::FRAMEWORK_WORDPRESS` for Wordpress apps

We are planning to add more frameworks as we improve the library.

## Custom Variables

You can add custom variables to every full profiling trace:

```php
<?php

\Tideways\Profiler::setCustomVariable('url', $_SERVER['HTTP_HOST'] . '/' . $_SERVER['REQUEST_URI']);
\Tideways\Profiler::setCustomVariable('mysql_thread_id', mysql_thread_id());
```
