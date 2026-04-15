<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Auth;

use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\GuzzleException;

class AuthManager
{
    private const BASE_URL = 'https://app.fiscozen.it';
    private const AUTH_URL = self::BASE_URL . '/api-auth/browser/v1/auth';
    private const SESSION_CHECK_URL = self::BASE_URL . '/api/v1/users/self/';

    private Client $httpClient;
    private FileCookieJar $cookieJar;

    /**
     * @param  \Closure(FileCookieJar): Client|null $httpClientFactory  Iniettabile per i test.
     */
    public function __construct(
        private readonly string $cookieFile,
        private readonly ?\Closure $httpClientFactory = null,
    ) {
        $this->cookieJar  = new FileCookieJar($this->cookieFile, storeSessionCookies: true);
        $this->httpClient = $this->buildHttpClient();
    }

    /**
     * Verifica se la sessione corrente è ancora autenticata.
     * Effettua una chiamata leggera all'API per controllare lo stato.
     */
    public function isAuthenticated(): bool
    {
        if (!file_exists($this->cookieFile)) {
            return false;
        }

        try {
            $response = $this->httpClient->get(self::SESSION_CHECK_URL, [
                'headers' => $this->buildHeaders(),
                'http_errors' => false,
            ]);

            return $response->getStatusCode() === 200;
        } catch (GuzzleException) {
            return false;
        }
    }

    /**
     * Esegue il login completo con il flusso a 3 step:
     *   1. Inizializzazione sessione (ottiene CSRF)
     *   2. Invio credenziali (riceve richiesta OTP)
     *   3. Verifica OTP + trust dispositivo
     *
     * Se la sessione è già valida non fa nulla.
     *
     * @param  callable(string $phoneNumber): string $otpCallback  Riceve il numero di telefono,
     *                                                              deve restituire il codice OTP.
     * @throws AuthenticationException
     */
    public function authenticate(string $email, string $password, callable $otpCallback): void
    {
        if ($this->isAuthenticated()) {
            return;
        }

        $this->initSession();
        $phoneNumber = $this->login($email, $password);
        $otp = $otpCallback($phoneNumber);
        $this->verifyOtp($otp);
        $this->trustDevice();
    }

    /**
     * Forza una nuova autenticazione ignorando la sessione esistente.
     *
     * @param  callable(string $phoneNumber): string $otpCallback
     * @throws AuthenticationException
     */
    public function reauthenticate(string $email, string $password, callable $otpCallback): void
    {
        $this->destroySession();
        $this->authenticate($email, $password, $otpCallback);
    }

    /**
     * Elimina il file dei cookie e invalida la sessione locale.
     */
    public function destroySession(): void
    {
        // Sostituiamo prima il jar: il __destruct del vecchio FileCookieJar
        // salva su disco, quindi facciamo unlink subito dopo.
        $this->cookieJar  = new FileCookieJar($this->cookieFile, storeSessionCookies: true);
        $this->httpClient = $this->buildHttpClient();

        if (file_exists($this->cookieFile)) {
            unlink($this->cookieFile);
        }
    }

    /**
     * Restituisce il cookie jar attivo (utile per riutilizzarlo in altri client).
     */
    public function getCookieJar(): FileCookieJar
    {
        return $this->cookieJar;
    }

    // -------------------------------------------------------------------------
    // Passi interni del flusso di autenticazione
    // -------------------------------------------------------------------------

    /**
     * Step 1 — carica la pagina di login per inizializzare il CSRF token.
     */
    private function initSession(): void
    {
        $this->httpClient->get('/app/login', [
            'headers'     => ['Accept' => 'text/html'],
            'http_errors' => false,
        ]);
    }

    /**
     * Step 2 — invia le credenziali. Restituisce il numero di telefono
     * su cui verrà inviato l'OTP.
     *
     * @throws AuthenticationException
     */
    private function login(string $email, string $password): string
    {
        $response = $this->httpClient->post(self::AUTH_URL . '/login', [
            'headers'     => $this->buildHeaders(),
            'json'        => compact('email', 'password'),
            'http_errors' => false,
        ]);

        $body = $this->decodeJson($response);

        if ($response->getStatusCode() !== 401 || !isset($body['data']['user_data']['phone_number'])) {
            throw new AuthenticationException(
                "Login fallito (HTTP {$response->getStatusCode()}): " . json_encode($body)
            );
        }

        return $body['data']['user_data']['phone_number'];
    }

    /**
     * Step 3a — verifica il codice OTP.
     *
     * @throws AuthenticationException
     */
    private function verifyOtp(string $otp): void
    {
        $response = $this->httpClient->post(self::AUTH_URL . '/2fa/authenticate', [
            'headers'     => $this->buildHeaders(),
            'json'        => ['code' => $otp],
            'http_errors' => false,
        ]);

        $body = $this->decodeJson($response);

        if ($response->getStatusCode() !== 401 || strpos(json_encode($body), 'mfa_trust') === false) {
            throw new AuthenticationException(
                "Verifica OTP fallita (HTTP {$response->getStatusCode()}): " . json_encode($body)
            );
        }
    }

    /**
     * Step 3b — marca il dispositivo come trusted per completare l'autenticazione.
     *
     * @throws AuthenticationException
     */
    private function trustDevice(): void
    {
        $response = $this->httpClient->post(self::AUTH_URL . '/2fa/trust', [
            'headers'     => $this->buildHeaders(),
            'json'        => ['trust' => true],
            'http_errors' => false,
        ]);

        $body = $this->decodeJson($response);

        if ($response->getStatusCode() !== 200 || ($body['meta']['is_authenticated'] ?? false) !== true) {
            throw new AuthenticationException(
                "Trust dispositivo fallito (HTTP {$response->getStatusCode()}): " . json_encode($body)
            );
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function buildHttpClient(): Client
    {
        if ($this->httpClientFactory !== null) {
            return ($this->httpClientFactory)($this->cookieJar);
        }

        return new Client([
            'base_uri' => self::BASE_URL,
            'cookies'  => $this->cookieJar,
            'headers'  => [
                'Accept'     => 'application/json',
                'Origin'     => self::BASE_URL,
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'x-realm'    => 'frontoffice',
            ],
        ]);
    }

    /**
     * Costruisce gli header aggiungendo il CSRF token se disponibile nel cookie jar.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Referer'      => self::BASE_URL . '/app/login',
        ];

        $csrfToken = $this->extractCsrfToken();
        if ($csrfToken !== null) {
            $headers['x-csrftoken'] = $csrfToken;
        }

        return $headers;
    }

    /**
     * Estrae il CSRF token dal cookie jar corrente.
     */
    private function extractCsrfToken(): ?string
    {
        foreach ($this->cookieJar as $cookie) {
            if ($cookie->getName() === 'csrftoken') {
                return $cookie->getValue();
            }
        }

        // Fallback: legge direttamente dal file (compatibilità formato Netscape)
        if (file_exists($this->cookieFile)) {
            $content = file_get_contents($this->cookieFile);
            if (preg_match('/csrftoken\s+([a-zA-Z0-9]+)/', $content, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
