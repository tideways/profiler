# Tideways PHP Library

PHP Client Library for [Tideways PHP Profiler](https://tideways.io) platform.

## Installation

There are two ways to install the Profiler Client:

1. Use Composer to install this library with:

        $ composer require tideways/profiler

2. Download the single combined file from [releases page](https://github.com/tideways/profiler/releases/latest).

The profiler class is monolithic intentionally to allow simple integration in all kinds of your projects.

## Requirements

You need either the `xhprof` or `tideways` PHP extensions installed to allow
profiling on PHP. For HHVM no additional requirements are necessary.

The Tideways Daemon (`tidewaysd`) has to be running on the production/staging
machine that you are profiling, otherwise the collected data will be lost.

## Integration

Tideways can be integrated in your application in two ways, either by
configuration (Environment/INI variables) or programatically in PHP code.

### Environment/INI Variables

The recommended method when using Tideways to monitor and profile web-requests
is integration by configuration. It allows you to start collecting data without
changing your application.

You need to configure the following INI settings (only when using Tideways PHP extension, does not work with XHProf):

    tideways.api_key=api key here
    tideways.sample_rate=10 ; 10% of requests get profiled

    ; if you use composer don't autoload the library
    tideways.load_library=0

    ; if you want to start profiling without adding composer package
    tideways.load_library=1

Or through Environment variables, for example in PHP-FPM Pool:

    env[TIDEWAYS_APIKEY]=api key here
    env[TIDEWAYS_SAMPLERATE]=10
    env[TIDEWAYS_AUTO_START]=1

### Programatical Integration

```php
<?php

require_once 'vendor/autoload.php';

\Tideways\Profiler::start("api key here", 10); // 10% Sample-Rate
```

## Configuration

Tideways follows [The Twelve-Factor App](http://12factor.net/) rules and is configured
via environment variables.

This allows you to configure the Profiler differently on each server without having to change
the code.

- `TIDEWAYS_DISABLED` controls if the profiler should be disabled on the server.
- `TIDEWAYS_SAMPLERATE` controls the sample rate how often the profiler should sample full XHProf traces.
- `TIDEWAYS_DISABLE_SESSIONS` controls if explicit developer sessions are allowed. They are enabled by default.

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
