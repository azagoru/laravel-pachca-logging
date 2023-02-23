# Laravel [Pachca](https://pachca.com) Logger
###### A Pachca based Monolog driver for Laravel

[![Latest Stable Version][packagist-image]][packagist-url]
[![Total Downloads][downloads-image]][packagist-url]
[![License][license-image]][packagist-url]

## Install
```bash
composer require asavenkov/laravel-pachca-logger
```

## Usage

Add the new driver type in your `config/logging.php` configuration

```php
'channels' => [
    'pachca' => [
        'driver' => 'custom',
        'via' => SavenkovDev\PachcaLogger\PachcaLogger::class,
        'webhook' => env('LOG_PACHCA_WEBHOOK_URL'),
        'level' => env('LOG_LEVEL', 'debug'),
    ],
],
```

And add `LOG_PACHCA_WEBHOOK_URL` to your `.env` file.

## Note
You may need to clear cache after installation if you get `laravel.EMERGENCY: Unable to create configured logger. ... Log [pachca] is not defined.` with
```bash
php artisan config:clear
```

## Contributing

Pull requests are welcome. For major changes, please open an issue first to discuss what you would like to change.

Make sure to add or update tests as appropriate.

Use [Conventional Commits](https://www.conventionalcommits.org/en/v1.0.0-beta.4/) for commit messages.

## License

[MIT](https://choosealicense.com/licenses/mit/)

<!-- Markdown link & img dfn's -->

[packagist-url]: https://packagist.org/packages/asavenkov/laravel-pachca-logger
[packagist-image]: https://poser.pugx.org/asavenkov/laravel-pachca-logger/v/stable.svg
[downloads-image]: https://poser.pugx.org/asavenkov/laravel-pachca-logger/downloads.svg
[license-image]: https://poser.pugx.org/asavenkov/laravel-pachca-logger/license.svg
