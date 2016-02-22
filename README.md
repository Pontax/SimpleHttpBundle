SimpleHttpBundle
======

A symfony2 2.7+ http client bundle built on the httpfoundation component (instead of guzzle), using cURL as engine.


[![testing](https://travis-ci.org/evaisse/SimpleHttpBundle.svg?branch=master)](https://travis-ci.org/evaisse/SimpleHttpBundle)
[![Coverage Status](https://coveralls.io/repos/evaisse/SimpleHttpBundle/badge.svg?branch=master)](https://coveralls.io/r/evaisse/SimpleHttpBundle?branch=master)

Quickstart
======


Get the simple API

```php
$http = $this->container->get('http');
$http = $this->get('http');

// same as  

try {
    $http->GET('http://httpbin.org/ip'); // yeah, that's all.
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    // handle errors
}

try {
    $data = $http->POST('http://httpbin.org/post', $myArgs);
} catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    // handle errors
}
```


Easy routing with replacement syntax (similar to php frameworks like symfony2/laravel)

```php
$data = $http->GET('http://httpbin.org/status/{code}', array(
    "code" => 200
));
// will call http://httpbin.org/status/200

$data = $http->GET('http://httpbin.org/status/{code}', array(
    "code" => 200,
    "foo" => "bar" 
));
// will call http://httpbin.org/status/200?foo=bar
```

Development features
====

The simple http bundle provide you a nice profiler toolbar addition
 
  - Request/Response body & headers details
  - Cookies handy panel 
  - External debug links (to remote profiler using X-Debug-Link header)
  - Request timing displayed in the timeline panel  



Complete API
=====

Complete api using transaction factory 
 
```php
$transac = $http->prepare('GET', 'http://httpbin.org/ip');

$transac->execute();
```

    
Parrallel execution
    
```php
$a = $http->prepare('GET', 'http://httpbin.org/ip');
$b = $http->prepare('PUT', 'http://httpbin.org/put');
$c = $http->prepare('POST', 'http://httpbin.org/post');

$http->execute([
    $a, 
    $b,
    $c
]);

$a->hasError() || $a->getResult();
$b->hasError() || $b->getResult();
$c->hasError() || $c->getResult();
```
    
    
JSON services


```php
print $http->prepare('POST', 'http://httpbin.org/ip', $_SERVER)
           ->json()
           ->execute()
           ->getResult()['ip'];
```

File upload

```php
$http->prepare('PUT', 'http://httpbin.org/put')
     ->addFile('f1', './myfile.txt')
     ->addFile('f2', './myfile.txt')
     ->execute();


$http->prepare('POST', 'http://httpbin.org/post', [
        'infos' => 'foo',
        'bar'   => 'so so',
    ])
     ->addFile('f1', './myfile.txt')
     ->addFile('f2', './myfile.txt')
     ->execute();
```


Cookies persistance 


```php
$a = $http->prepare('GET',  'http://httpbin.org/ip');
$b = $http->prepare('PUT',  'http://httpbin.org/put');
$c = $http->prepare('POST', 'http://httpbin.org/post');


$cookies = $http->getDefaultCookieJar();
 // $cookies = $http->getCookieJar($session); if you want to directly store in user session

$http->execute([
    $a, 
    $b,
    $c
], $cookies);

dump($cookies);
```
    

Promise usage

```php
$stmt = $http->prepare('PUT', 'http://httpbin.org/put')

$stmt->onSuccess(function ($data) {
    // handle data
})->onError(function (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
    // handle errors
})->onFinish(function () {
    // like "finally"
});

$http->execute([
    $stmt
]);
```



