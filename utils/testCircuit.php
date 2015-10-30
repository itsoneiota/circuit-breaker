<?php
namespace itsoneiota\circuitbreaker;
include __DIR__.'/../vendor/autoload.php';

$cb = CircuitBreakerBuilder::create('myService')
    ->withMemcachedServer($_SERVER['MEMCACHED_HOST'], $_SERVER['MEMCACHED_PORT'])
    ->withSamplePeriod(1)
    ->withProbabilisticDynamics()
    ->enabled()
    ->build();

for ($i=0; $i < 10000; $i++) {
    if($cb->isClosed()){
        if(rand(0,100)>50){ // Simulate flaky function.
            echo 'S';
            $cb->registerSuccess();
        }else{
            echo 'F';
            $cb->registerFailure();
        }
    }else{
        echo 'R';
        $cb->registerRejection();
    }
    usleep(10000);
}
