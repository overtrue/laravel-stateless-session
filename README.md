Laravel stateless session
---

A lightweight middleware to make api routing session capable.

## Installing

```shell
$ composer require overtrue/laravel-stateless-session -vvv
```

## Usage

Add the middleware (`\Overtrue\LaravelStatelessSession\Http\Middleware\SetSessionFromHeaders`) to api route group.

```php
protected $middlewareGroups = [
        //...
        'api' => [
            \Overtrue\LaravelStatelessSession\Http\Middleware\SetSessionFromHeaders::class,
            \Illuminate\Session\Middleware\StartSession::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ],
    ];
```

## Response header

This middleware will set session id to response headers with name 'X-SESSION', if you want to update the header name, you can add `header` to `config/session.php`:

*config/session.php*
```php
    'header' => 'X-SESSION',
```

## Request header

The api request must set the last session id to headers. Using axios as an example, we need to listen to the api response, retrieve the session ID from response headers and store it in local storage, then retrieve it and add it to the request header:

```php
axios.interceptors.request.use(function (config) {
    config.headers['X-SESSION'] = 'session id from your localstorage';
    return config;
  }, function (error) {
    return Promise.reject(error);
  });

// Store the response session id
axios.interceptors.response.use(function (response) {
    if (response.headers['X-SESSION']) {
        // store session id to localstorage
    }
    return response;
  }, function (error) {
    return Promise.reject(error);
  });
```

## Contributing

You can contribute in one of three ways:

1. File bug reports using the [issue tracker](https://github.com/overtrue/laravel-package/issues).
2. Answer questions or fix bugs on the [issue tracker](https://github.com/overtrue/laravel-package/issues).
3. Contribute new features or update the wiki.

_The code contribution process is not very formal. You just need to make sure that you follow the PSR-0, PSR-1, and PSR-2 coding guidelines. Any new code contributions must be accompanied by unit tests where applicable._

## PHP 扩展包开发

> 想知道如何从零开始构建 PHP 扩展包？
>
> 请关注我的实战课程，我会在此课程中分享一些扩展开发经验 —— [《PHP 扩展包实战教程 - 从入门到发布》](https://learnku.com/courses/creating-package)

## License

MIT
