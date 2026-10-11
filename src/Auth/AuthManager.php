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
        $this->upgradeLegacyCookieFile();
        $this->cookieJar  = new FileCookieJar($this->cookieFile, storeSessionCookies: true);
        $this->secureCookieFilePermissions();
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
     * Step 1/2 del flusso di autenticazione: inizializza la sessione e invia le credenziali.
     * I cookie intermedi (CSRF token, session ID) vengono persistiti su disco così che
     * submitOtp() possa essere chiamato in una esecuzione successiva.
     *
     * @return string Numero di telefono mascherato su cui è stato inviato l'OTP.
     * @throws AuthenticationException
     */
    public function startLogin(string $email, string $password): string
    {
        $this->initSession();
        $phoneNumber = $this->login($email, $password);
        $this->persistCookies();

        return $phoneNumber;
    }

    /**
     * Step 3 del flusso di autenticazione: verifica il codice OTP e marca il dispositivo
     * come trusted. Deve essere chiamato dopo startLogin(), anche in una esecuzione separata,
     * purché il cookie file sia lo stesso.
     *
     * @throws AuthenticationException
     */
    public function submitOtp(string $otp): void
    {
        $this->verifyOtp($otp);
        $this->trustDevice();
        $this->persistCookies();
    }

    /**
     * Esegue il login completo in un'unica chiamata sincrona tramite callback.
     * Utile per contesti CLI o test; per applicazioni web preferire startLogin() + submitOtp().
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

        $phoneNumber = $this->startLogin($email, $password);
        $this->submitOtp($otpCallback($phoneNumber));
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
     * Salva esplicitamente la sessione e limita il file al solo utente corrente.
     */
    private function persistCookies(): void
    {
        // umask ristrittivo per evitare che, alla creazione, il file sia
        // brevemente leggibile da altri utenti prima del chmod successivo.
        $previousUmask = umask(0077);
        try {
            $this->cookieJar->save($this->cookieFile);
        } finally {
            umask($previousUmask);
        }

        $this->secureCookieFilePermissions();
    }

    /**
     * Imposta permessi restrittivi sui cookie già presenti senza crearne di nuovi.
     */
    private function secureCookieFilePermissions(): void
    {
        if (file_exists($this->cookieFile) && !chmod($this->cookieFile, 0600)) {
            throw new \RuntimeException('Impossibile proteggere il file di sessione');
        }
    }

    /**
     * Le versioni recenti di Guzzle rifiutano i file cookie senza la chiave
     * "HostOnly" (scritti da versioni precedenti): la aggiunge dedotta dal dominio.
     */
    private function upgradeLegacyCookieFile(): void
    {
        if (!is_file($this->cookieFile)) {
            return;
        }

        $data = json_decode((string) file_get_contents($this->cookieFile), true);
        if (!is_array($data)) {
            return;
        }

        $changed = false;
        foreach ($data as &$cookie) {
            if (is_array($cookie) && !array_key_exists('HostOnly', $cookie)) {
                $domain = (string) ($cookie['Domain'] ?? '');
                $cookie['HostOnly'] = $domain !== '' && $domain[0] !== '.';
                $changed = true;
            }
        }
        unset($cookie);

        if ($changed) {
            file_put_contents($this->cookieFile, json_encode($data), LOCK_EX);
        }
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(\Psr\Http\Message\ResponseInterface $response): array
    {
        return json_decode((string) $response->getBody(), true) ?? [];
    }
}
