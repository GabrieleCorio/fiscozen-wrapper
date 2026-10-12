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

The read-only endpoints are grouped by domain. They reuse the authenticated session and
CSRF handling provided by `AuthManager`.

| Domain | Method | Returns | Description |
|--------|--------|---------|-------------|
| User | `getUserMe()` | `array` | Authenticated user's profile data |
| Invoices | `invoices()->list(...)` | `PaginatedResponse` | Paginated invoice list, filterable by customer and date range |
| Invoices | `invoices()->get(...)` | `array` | Invoice details by internal Fiscozen ID |
| Invoices | `invoices()->findByNumber(...)` | `array` | Invoice details by number and year |
| Invoices | `invoices()->getFormatInfo(...)` | `array` | Invoice format information for a date |
| Counterparts | `counterparts()->list(...)` | `array` | Counterpart list |
| Counterparts | `counterparts()->search(...)` | `PaginatedResponse` | Customers matching a text search |
| Counterparts | `counterparts()->findIds(...)` | `list<int>` | Counterpart IDs matching a text search |
| Payments | `payments()->listMethods(...)` | `PaginatedResponse` | Paginated payment method list |
| Dashboard | `dashboard()->getRealtimeYearStats(...)` | `array` | Real-time fiscal-year statistics |
| Dashboard | `dashboard()->getFulfillmentForecast(...)` | `array` | Fiscal-year fulfillment forecast |
| Dashboard | `dashboard()->getTasks()` | `array` | Dashboard tasks |

Only GET endpoints observed in captured application traffic are included. No write endpoint
is exposed.

### Invoices

```php
$invoices = $client->invoices()->list(
    page: 1,
    pageSize: 20,
    selfInvoice: false,
);

foreach ($invoices->results as $invoice) {
    // Read the fields needed by your application.
}

// Invoices issued to a customer in a period (most recent first by default).
$issued = $client->invoices()->list(
    counterpart: 1239628,
    dateFrom: new DateTimeImmutable('2026-06-01'),
    dateTo: new DateTimeImmutable('2026-10-31'),
    pageSize: 50,
);
// No match returns count 0 and an empty `results`, not an error.

// Or find the customer by name, surname or business name.
$issued = $client->invoices()->list(counterpartSearch: 'Rossi');

// Invoice details by number and year (e.g. 24/2026), no internal ID needed.
try {
    $invoice = $client->invoices()->findByNumber(number: 24, year: 2026);
    echo $invoice['full_id'], ' ', $invoice['amount'], ' ', $invoice['status'];
    $rows = $invoice['data']['rows']; // description, quantity, amount, total, vat_code
} catch (InvoiceNotFoundException $e) {
    // "Invoice not found: 24/2026"
}

// Or by internal Fiscozen ID.
$invoice = $client->invoices()->get(6512587);

$formatInfo = $client->invoices()->getFormatInfo(
    new DateTimeImmutable('2026-01-15'),
);
```

Filters can be combined. `counterpart` takes a counterpart ID (or a list of IDs, sent as
`counterpart__in`) and `dateFrom`/`dateTo` bound the invoice date inclusively. Results default to
`-invoice_date,-invoice_number,-id`; pass `ordering: null` to leave ordering to the server. Use
the `counterpart` filter, not `search`, to list the invoices of a customer.

`counterpartSearch` looks customers up by first name, last name or business name and filters by
every customer found (it cannot be combined with `counterpart`). Tax code and VAT number are
**not** searchable: the counterparts endpoint does not match them. If no customer matches, the
result is empty and the invoice list is not requested.

`findByNumber()` searches the invoice list for `number/year` and keeps only an exact match
before reading its details; it throws `InvoiceNotFoundException` (a subclass of `ApiException`
with status `404`) when none exists. It looks at issued invoices by default; pass
`selfInvoice: true` for self-invoices. The detail array is returned as-is and includes your own
fiscal profile data; the wrapper never sends it to any third party.

### Counterparts

```php
$counterparts = $client->counterparts()->list();
```

The optional `choices` and `counterpartType` filters are strings because the captured traffic
does not establish a stable set of allowed enum values.

### Payment methods

```php
$paymentMethods = $client->payments()->listMethods(
    page: 1,
    pageSize: 20,
);
```

### Dashboard

```php
$year = 2026;

$stats = $client->dashboard()->getRealtimeYearStats($year);
$forecast = $client->dashboard()->getFulfillmentForecast($year);
$tasks = $client->dashboard()->getTasks();
```

### Pagination

Paginated methods return a `PaginatedResponse` with the fields observed in the API response:

```php
$page = $client->invoices()->list(page: 1, pageSize: 20);

echo $page->count;
echo $page->page;
echo $page->pages;

if ($page->next !== null) {
    // Request the next page explicitly using page: $page->page + 1.
}
```

Pagination parameters must be greater than or equal to `1`. Unknown filters are deliberately
not accepted.

### Local read-only scripts

Each script performs a single action and prints JSON to standard output; none of them save API
responses automatically. Log in once with `login.php`, then run any read script against the
session it created:

```bash
php examples/login.php
php examples/read_dashboard.php --year=2026
php examples/read_invoices.php --page=1 --page-size=20
php examples/read_invoices.php --counterpart=1239628 --date-from=2026-06-01 --date-to=2026-10-31
php examples/read_invoices.php --counterpart-search=Rossi
php examples/read_invoice.php --number=24 --year=2026
php examples/read_counterparts.php
php examples/read_payment_methods.php --page=1 --page-size=20
php examples/read_user_info.php
```

The read scripts never trigger a login themselves — if no valid session is found they print an
error asking you to run `login.php` first, keeping each script to one action.

API responses can contain personal or fiscal data. Review terminal and process access before
running these commands, and do not redirect output to a file unless that file is protected and
excluded from version control.

## Environment variables

All scripts in `examples/` read their configuration from the environment instead of hardcoded
values:

| Variable | Required | Description |
|----------|----------|--------------|
| `FISCOZEN_EMAIL` | Yes | Account email used to log in |
| `FISCOZEN_PASSWORD` | Yes | Account password used to log in |
| `FISCOZEN_SESSION_DIR` | No | Directory for the session cookie file. Defaults to `examples/../sessions` |

To avoid placing credentials in shell history, enter them interactively:

```bash
read -r FISCOZEN_EMAIL
read -rs FISCOZEN_PASSWORD
export FISCOZEN_EMAIL FISCOZEN_PASSWORD
```

Session cookies are stored in the git-ignored `sessions/` directory by default.

### User profile

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

The `examples/` directory contains one script per action, each reading its configuration from
the [environment variables](#environment-variables) described above:

- [examples/login.php](examples/login.php) — checks whether a valid session already exists, and
  otherwise runs the two-step login flow with terminal OTP input.
- [examples/read_user_info.php](examples/read_user_info.php) — calls `getUserMe()` and prints the
  result.
- [examples/read_dashboard.php](examples/read_dashboard.php),
  [examples/read_invoices.php](examples/read_invoices.php),
  [examples/read_invoice.php](examples/read_invoice.php),
  [examples/read_counterparts.php](examples/read_counterparts.php),
  [examples/read_payment_methods.php](examples/read_payment_methods.php) — one script per
  read-only domain endpoint.

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
