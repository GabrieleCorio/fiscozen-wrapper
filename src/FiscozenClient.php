<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Api\Domain\CounterpartsApi;
use GabrieleCorio\FiscozenWrapper\Api\Domain\DashboardApi;
use GabrieleCorio\FiscozenWrapper\Api\Domain\InvoicesApi;
use GabrieleCorio\FiscozenWrapper\Api\Domain\PaymentsApi;
use GabrieleCorio\FiscozenWrapper\Auth\AuthManager;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;

class FiscozenClient
{
    private AuthManager $auth;
    private ApiClient $api;
    private InvoicesApi $invoicesApi;
    private CounterpartsApi $counterpartsApi;
    private PaymentsApi $paymentsApi;
    private DashboardApi $dashboardApi;

    /**
     * @param  string|null       $sessionDir  Directory dove salvare i file di sessione.
     *                                        Default: sys_get_temp_dir()/fiscozen_sessions
     * @param  AuthManager|null  $auth        Iniettabile per i test.
     * @param  ApiClient|null    $api         Iniettabile per i test.
     */
    public function __construct(
        private readonly string $email,
        private readonly string $password,
        ?string $sessionDir = null,
        ?AuthManager $auth = null,
        ?ApiClient $api = null,
    ) {
        $cookieFile    = $this->resolveCookieFile($sessionDir);
        $this->auth    = $auth ?? new AuthManager($cookieFile);
        $this->api     = $api  ?? new ApiClient($this->auth);
        $this->invoicesApi = new InvoicesApi($this->api);
        $this->counterpartsApi = new CounterpartsApi($this->api);
        $this->paymentsApi = new PaymentsApi($this->api);
        $this->dashboardApi = new DashboardApi($this->api);
    }

    // -------------------------------------------------------------------------
    // Autenticazione
    // -------------------------------------------------------------------------

    /**
     * Step 1/2: invia le credenziali e avvia il flusso OTP.
     * I cookie di sessione vengono persistiti su disco; chiamare submitOtp() per completare
     * l'autenticazione, anche in una esecuzione HTTP successiva.
     *
     * @return string Numero di telefono mascherato su cui è stato inviato l'OTP.
     * @throws AuthenticationException
     */
    public function startLogin(): string
    {
        return $this->auth->startLogin($this->email, $this->password);
    }

    /**
     * Step 3: verifica il codice OTP e completa l'autenticazione.
     * Deve essere chiamato dopo startLogin(), anche in una esecuzione separata.
     *
     * @throws AuthenticationException
     */
    public function submitOtp(string $otp): void
    {
        $this->auth->submitOtp($otp);
    }

    /**
     * Login completo in un'unica chiamata sincrona (utile per CLI/test).
     * Se la sessione è già valida non fa nulla.
     *
     * @param  callable(string $phoneNumber): string $otpCallback
     * @throws AuthenticationException
     */
    public function login(callable $otpCallback): void
    {
        $this->auth->authenticate($this->email, $this->password, $otpCallback);
    }

    /**
     * Forza una nuova autenticazione, eliminando la sessione esistente.
     *
     * @param  callable(string $phoneNumber): string $otpCallback
     * @throws AuthenticationException
     */
    public function relogin(callable $otpCallback): void
    {
        $this->auth->reauthenticate($this->email, $this->password, $otpCallback);
    }

    public function isAuthenticated(): bool
    {
        return $this->auth->isAuthenticated();
    }

    public function logout(): void
    {
        $this->auth->destroySession();
    }

    // -------------------------------------------------------------------------
    // API
    // -------------------------------------------------------------------------

    /**
     * Recupera i dati dell'utente autenticato.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function getUserMe(): array
    {
        return $this->api->getUserMe();
    }

    /** Restituisce gli endpoint GET relativi alle fatture. */
    public function invoices(): InvoicesApi
    {
        return $this->invoicesApi;
    }

    /** Restituisce gli endpoint GET relativi alle controparti. */
    public function counterparts(): CounterpartsApi
    {
        return $this->counterpartsApi;
    }

    /** Restituisce gli endpoint GET relativi ai pagamenti. */
    public function payments(): PaymentsApi
    {
        return $this->paymentsApi;
    }

    /** Restituisce gli endpoint GET relativi alla dashboard. */
    public function dashboard(): DashboardApi
    {
        return $this->dashboardApi;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    public function getAuthManager(): AuthManager
    {
        return $this->auth;
    }

    /**
     * Ricava il percorso del file di sessione.
     * Il nome del file è l'hash SHA-256 dell'email, con estensione .json.
     * La directory viene creata automaticamente se non esiste.
     */
    private function resolveCookieFile(?string $sessionDir): string
    {
        $dir = $sessionDir ?? sys_get_temp_dir() . '/fiscozen_sessions';

        if (!is_dir($dir) && !mkdir($dir, 0700, recursive: true) && !is_dir($dir)) {
            throw new \RuntimeException('Impossibile creare la directory delle sessioni');
        }

        if (!chmod($dir, 0700)) {
            throw new \RuntimeException('Impossibile proteggere la directory delle sessioni');
        }

        return $dir . '/' . hash('sha256', $this->email) . '.json';
    }
}
