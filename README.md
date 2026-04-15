# fiscozen-wrapper

An unofficial PHP wrapper for the [Fiscozen](https://www.fiscozen.it) web application API.

> **Disclaimer:** This is an unofficial, reverse-engineered client. It is not affiliated with, endorsed by, or supported by Fiscozen. Use at your own risk. The underlying API may change at any time without notice.

---

## Requirements

- PHP >= 8.1
- Composer

## Installation

```bash
composer require gabrielecorio/fiscozen-wrapper
```

## Authentication flow

Fiscozen uses a two-factor authentication flow:

1. Submit credentials → the API sends an OTP to your registered phone number.
2. Submit the OTP → session cookies are persisted to disk for reuse.

The wrapper exposes two approaches depending on your use case.

### Two-step (web application)

Credentials and OTP arrive in separate HTTP requests, so each step is called independently. Session cookies are written to disk between the two calls.

```php
use GabrieleCorio\FiscozenWrapper\FiscozenClient;
use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;

$client = new FiscozenClient(
    email: 'you@example.com',
    password: 'your-password',
    sessionDir: '/path/to/sessions', // optional; defaults to sys_get_temp_dir()/fiscozen_sessions
);

// --- Request 1: submit credentials ---
try {
    $phoneNumber = $client->startLogin(); // returns the masked phone number
    // Store $phoneNumber in your session and show the OTP input to the user
} catch (AuthenticationException $e) {
    // handle error
}

// --- Request 2: submit OTP ---
try {
    $client->submitOtp($otpFromUser);
} catch (AuthenticationException $e) {
    // handle error
}
```

### Single-step (CLI / scripts)

Provide an OTP callback that blocks until the user types the code. The library handles the full flow synchronously. If a valid session already exists on disk it is reused and the callback is never called.

```php
$client->login(function (string $phoneNumber): string {
    echo "OTP sent to {$phoneNumber}: ";
    return trim(fgets(STDIN));
});
```

### Checking and destroying a session

```php
$client->isAuthenticated(); // bool — makes a lightweight API call to verify the session
$client->logout();          // deletes the cookie file and invalidates the local session
$client->relogin($callback); // destroys the existing session and authenticates again
```

## Available API methods

| Method | Returns | Description |
|--------|---------|-------------|
| `getUserMe()` | `array` | Returns the authenticated user's profile data |

```php
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;

try {
    $response = $client->getUserMe();
    $user = $response['data'] ?? [];

    echo $user['first_name'] . ' ' . $user['last_name'];
    echo $user['email'];
    echo $user['fiscal_code'];
} catch (ApiException $e) {
    echo "HTTP {$e->getHttpStatusCode()}: {$e->getMessage()}";
}
```

## Session persistence

Each user's session is stored in a single JSON file named after the SHA-256 hash of their email address. The directory is created automatically if it does not exist.

```
/tmp/fiscozen_sessions/
└── a3f1...d9.json   ← cookies for you@example.com
```

You can override the directory:

```php
$client = new FiscozenClient(
    email: 'you@example.com',
    password: 'your-password',
    sessionDir: __DIR__ . '/sessions',
);
```

## Exceptions

| Class | Extends | When thrown |
|-------|---------|-------------|
| `AuthenticationException` | `RuntimeException` | Login or OTP verification fails |
| `ApiException` | `RuntimeException` | Any non-200 API response; exposes `getHttpStatusCode()` |
| `OtpRequiredException` | `RuntimeException` | OTP step is required; exposes `getPhoneNumber()` |

## Full example

See [examples/login_and_show_user.php](examples/login_and_show_user.php) for a complete script that:

- checks whether a valid session already exists,
- runs the two-step login flow with terminal OTP input as a fallback,
- calls `getUserMe()` and prints the result.

## Running tests

```bash
composer install
./vendor/bin/phpunit
```

## Project structure

```
src/
├── FiscozenClient.php          # Main entry point
├── Api/
│   └── ApiClient.php           # HTTP client for API endpoints
├── Auth/
│   └── AuthManager.php         # Authentication & session management
└── Exceptions/
    ├── ApiException.php
    ├── AuthenticationException.php
    └── OtpRequiredException.php
```

## License

This project is released under the MIT License.
