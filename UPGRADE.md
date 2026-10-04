# Upgrading from 2.x to 3.0

## Requirements

Version 3 supports Laravel 13.x and PHP 8.3 or newer. Support for Laravel 9 and older PHP versions has been removed. Upgrade the application to Laravel 13 first, following Laravel's upgrade guides, then update the package constraint:

```shell
composer require overtrue/laravel-stateless-session:^3.0 --with-all-dependencies
```

This is a breaking major release. Applications remaining on Laravel 9 must remain on package 2.x; that framework version is no longer supported by Laravel.

## Middleware registration

For the current Laravel application structure, register `SetSessionFromHeaders` followed by `Illuminate\Session\Middleware\StartSession` in the API group through `bootstrap/app.php`. See the example in [README.md](README.md#usage). Applications retaining an HTTP kernel may continue registering the same middleware classes there in the same order.

The middleware class name and the optional `session.header` configuration key have not changed. Keep a persistent session driver configured and provision its backing storage as required by Laravel.

## Response headers

The middleware now returns the session ID for the first request even when the incoming session header is missing, empty, `undefined`, or `0`. Previously, these requests ran Laravel's session middleware but omitted the response session header, preventing a header-only client from discovering its newly created session.

The response header comes from the request's actual Laravel session after downstream middleware and application code finish. Regenerated or invalidated session IDs are therefore returned, while requests without an attached session no longer receive an unrelated ID from the session manager.

Clients should always retain the most recently returned session ID. A valid supplied header still takes precedence over the session cookie, invalid IDs are validated by Laravel, and `session.header` still defaults to `x-session`.

## Development tooling

Tests now use Testbench 11 and PHPUnit 12.5, with integration coverage against Laravel 13. CI covers PHP 8.3, 8.4, and 8.5, plus the lowest dependency set accepted by Composer's security policy on PHP 8.3. Composer's advisory blocking remains enabled.

The broken Composer install/update hooks referencing the undeclared `cghooks` executable have been removed. Run `composer test` and `composer check-style` before contributing changes.
