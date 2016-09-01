<?php
namespace itsoneiota\circuitbreaker;
include __DIR__.'/../vendor/autoload.php';

$monitor = CircuitBreakerBuilder::create('myService')
    ->withMemcachedServer($_SERVER['MEMCACHED_HOST'], $_SERVER['MEMCACHED_PORT'])
    ->withSamplePeriod(1)
    ->withProbabilisticDynamics()
    ->buildMonitor();
$display = new ui\CircuitBreakerDisplay($monitor);

$display->show();
