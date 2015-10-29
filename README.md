One iota Circuit Breaker
========================
[![Build Status](https://travis-ci.org/itsoneiota/circuit-breaker.svg?branch=master)](https://travis-ci.org/itsoneiota/circuit-breaker)

Overview
--------
A circuit breaker can be used to protect a system against failures in external dependencies. It can track successes and failures in service calls across a given time period, and will 'trip' if the failure rate in the previous period exceeded a given threshold.

The circuit is said to be 'closed' if it should continue to make requests, and 'open' if no more requests should be made. The circuit will stay closed if a given minimum number of requests is not met. An additional feature allows requests to a service to be made with a probability that equals the success rate in the previous period.

Installation
------------
The best way to autoload this package and its dependencies is to include the standard Composer autoloader, `vendor/autoload.php`.

Testing
-------
The library's suite of unit tests can be run by running `phpunit` from the root of the repository.

Basic Usage
-----------

### Builder
The easiest way to build a circuit breaker is with the builder class.

	$cb = CircuitBreakerBuilder::create('myService')->withCache($cache)->build();

Where `myService` is the name of my service, and `$cache` is an `\itsoneiota\cache\Cache` instance. All circuit breakers using the same cache and service name will share their statistics and will be open and closed together.

### Isolating Remote Service Calls
So, let's say we're calling a remote service, and we want to protect ourselves from it having a bad day.

	public function getRemoteStuff(){
		$cb = new CircuitBreaker($this->cache, 'remoteService');

		// If the circuit is open, don't make the request.
		if(!$cb->isClosed()){
			$cb->registerRejection();
			return NULL;
		}

		// Now make the request...
		$response = file_get_contents('http://www.example.com/api/stuff');

		if($response === FALSE){
			// Register the failure and return a default value.
			$cb->registerFailure();
			return NULL;
		}

		// Success!
		$cb->registerSuccess();
		return $response;
	}

## Configuration
Circuit breakers can be configured either directly, or via the builder. The builder usually makes this a little nicer and easier to read, so that's what's documented here. The methods below can be chained together in a fluent style, e.g.:

	CircuitBreakerBuilder::create('foo')
		->withCache($mc)
		->withTimeProvider($tp)
		->withMinimumRequestsBeforeTrigger(100)
		->withPercentageFailureThreshold(60)
		->withProbabilisticDynamics()
		->withRecoveryFactor(2.5)
		->enabled()
		->build();

#### `enabled()` / `disabled()` (default: enabled)
Turns the circuit breaker on or off, respectively. When enabled, the breaker will trip in response to excessive failed requests. When disabled, it will continue to record success/failure statistics, but it will not trip.

#### `withSamplePeriod($period)` (default 60)
The period of time, in seconds, over which successes and failures are aggregated before a decision is made.

#### `withMinimumRequestsBeforeTrigger($min)` (default 3)
The minimum number of requests that must be made before the circuit will commit to a decision.

#### `withPercentageFailureThreshold($threshold)` (default 50)
The percentage failure rate that will trigger the circuit to trip.

#### `withProbabilisticDynamics()` / `withDeterministicDynamics()` (default: deterministic)
Dynamics of the breaker when tripped. If deterministic, the circuit will open completely, allowing no traffic through for a full period. If probabilistic, the circuit will allow a proportionate number of requests through, and steadily increase the number of requests made over subsequent periods.

#### `withRecoveryFactor($factor)` (default 2)
The rate at which throttling relaxes in subsequent periods. See _Recovery Dynamics_, below.

Open or Closed?
---------------
The circuit is closed by default.

As the circuit breaker is notified of successful and failed requests, it collects the statistics in a bucket for a given _sample period_. By default, the sample period is 60 seconds, but this can be set to anything you like. The circuit breaker uses the statistics from the previous sample period to decide whether to stay open or closed.

If the minimum request threshold has been met, and the percentage failure rate in the previous period exceeds the percentage failure threshold, then the circuit breaker will 'trip', and `isClosed()` will return `FALSE`.

Probably Closed?
----------------

If a dependency is liable to fall over completely, then a binary open/closed state will suit quite well. If a dependency sometimes becomes unreliable under load without completely failing, we can throttle our requests to it until it gets back on its feet. To help with these circumstances, we can allow the circuit to be open probabilistically, based on the success rate in the previous sample period.

To set probabilistic, set the `withProbabilisticDynamics()` method on the builder, or call `setProbabilisticDynamics(TRUE)` on the circuit breaker itself. Now, `isClosed()` will return `TRUE` in all the usual cases. If the trip conditions have been met, `isClosed()` will now return TRUE with a probability equal to the success rate from the previous period. For example, let's say we make 100 requests to a dependency in one period. but only 20 succeed. With probabilistic dynamics, `isClosed()` will return `TRUE` approximately 20% of the time.

### Recovery Dynamics
So that the circuit doesn't 'snap' closed straight away, the dynamics will increase the throttle steadily from one period to the next. The speed of recovery can be set using the `recoveryFactor` property. For example, after a period with an 80% failure rate, only 20% of requests will be allowed through. If all of the requests allowed through the circuit are successful, there will be 20% * `recoveryFactor` requests allowed through in the next period. Recovery is capped to the lesser of the success rate in the previous period, and the throttle * `recoveryFactor`.

The Time Provider
-----------------
The circuit breaker records statistics in cache buckets based on the current time. The time is given by a `TimeProvider` instance. There are a couple of options here:

- `SystemTimeProvider` is a simple wrapper for `time()`.
- `FixedTimeProvider` will always give the same time. Might be faster, who knows? Also quite handy for testing.
- _Roll your own_: You can implement your own `TimeProvider` if you have esoteric requirements.
