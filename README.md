Laravel stateless session
---

A lightweight middleware to make api routing session capable.

[![Sponsor me](https://github.com/overtrue/overtrue/blob/master/sponsor-me-button-s.svg?raw=true)](https://github.com/sponsors/overtrue)

## Requirements

- PHP 8.3 or newer
- Laravel 13.x

Version 3 is a new major release. See [UPGRADE.md](UPGRADE.md) when upgrading from version 2.

## Installing

```shell
composer require overtrue/laravel-stateless-session:^3.0
```

## Usage

Add the middleware to your API group in `bootstrap/app.php`, immediately before Laravel's `StartSession` middleware:

```php
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;
use Overtrue\LaravelStatelessSession\Http\Middleware\SetSessionFromHeaders;

// In your existing Application::configure(...) chain:
->withMiddleware(function (Middleware $middleware): void {
    $middleware->api(prepend: [
        SetSessionFromHeaders::class,
        StartSession::class,
    ]);
})
```

Keep a persistent Laravel session driver configured (for example, `database`, `redis`, or `file`). This package transports the session ID in headers; session data is still stored by Laravel on the server. Laravel's `StartSession` middleware also retains its normal session cookie behavior.

## Response header

Every request with a Laravel session receives its final session ID in the `x-session` response header, including the first request without a session header. If your application regenerates or invalidates the session, the response contains the new ID. Store the latest returned ID for the next request.

To customize the header name, add `header` to `config/session.php`:

```php
'header' => 'x-session',
```

The middleware does not add a session header when no session was attached to the request, such as a route without `StartSession`.

## Request header

Send the most recently returned session ID in the same header. No header is needed for the first request. A supplied session header takes precedence over Laravel's session cookie. Empty values and the strings `undefined` and `0` are ignored; Laravel validates other session IDs and replaces invalid IDs.

For example, with Axios:

```js
let sessionId;

axios.interceptors.request.use((config) => {
    if (sessionId) {
        config.headers['x-session'] = sessionId;
    }

    return config;
});

const rememberSession = (response) => {
    const id = response?.headers?.['x-session'];

    if (id) {
        sessionId = id;
    }
};

axios.interceptors.response.use((response) => {
    rememberSession(response);

    return response;
}, (error) => {
    rememberSession(error.response);

    return Promise.reject(error);
});
```

The response interceptor also retains session IDs from failed HTTP responses while keeping the original error rejected. Network failures without a response leave the stored ID unchanged.

For a cross-origin browser client, allow your session header in the application's CORS configuration and expose the response header through `exposed_headers` so JavaScript can read it. Treat the session ID as a credential: use HTTPS, avoid logging it, and choose client-side storage appropriate to your application's security model. Normal authentication, authorization, session regeneration, and CSRF protections remain the application's responsibility.

## Testing

```shell
composer install
composer test
composer check-style
```

The integration tests exercise real Laravel middleware and file-backed sessions, including persistence across fresh session stores, session rotation, custom headers, cookie precedence, invalid IDs, and first-request initialization.

## Contributing

You can contribute in one of three ways:

1. File bug reports using the [issue tracker](https://github.com/overtrue/laravel-package/issues).
2. Answer questions or fix bugs on the [issue tracker](https://github.com/overtrue/laravel-package/issues).
3. Contribute new features or update the wiki.

_The code contribution process is not very formal. You just need to make sure that you follow the PSR-0, PSR-1, and PSR-2 coding guidelines. Any new code contributions must be accompanied by unit tests where applicable._

## Project supported by JetBrains

Many thanks to Jetbrains for kindly providing a license for me to work on this and other open-source projects.

[![](https://resources.jetbrains.com/storage/products/company/brand/logos/jb_beam.svg)](https://www.jetbrains.com/?from=https://github.com/overtrue)


## License

MIT
