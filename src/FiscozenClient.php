<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper;

use GabrieleCorio\FiscozenWrapper\Api\ApiClient;
use GabrieleCorio\FiscozenWrapper\Auth\AuthManager;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GabrieleCorio\FiscozenWrapper\Exceptions\AuthenticationException;

class FiscozenClient
{
    private AuthManager $auth;
    private ApiClient $api;

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
    }

    // -------------------------------------------------------------------------
    // Autenticazione
    // -------------------------------------------------------------------------

    /**
     * Autentica il client. Se la sessione è già valida non fa nulla.
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

        if (!is_dir($dir)) {
            mkdir($dir, 0700, recursive: true);
        }

        return $dir . '/' . hash('sha256', $this->email) . '.json';
    }
}
