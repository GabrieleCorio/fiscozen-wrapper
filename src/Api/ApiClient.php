<?php

declare(strict_types=1);

namespace GabrieleCorio\FiscozenWrapper\Api;

use GabrieleCorio\FiscozenWrapper\Auth\AuthManager;
use GabrieleCorio\FiscozenWrapper\Exceptions\ApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Cookie\FileCookieJar;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Http\Message\ResponseInterface;

class ApiClient
{
    private const BASE_URL = 'https://app.fiscozen.it';

    /**
     * @param  \Closure(FileCookieJar): Client|null $httpClientFactory  Iniettabile per i test.
     */
    public function __construct(
        private readonly AuthManager $auth,
        private readonly ?\Closure $httpClientFactory = null,
    ) {}

    // -------------------------------------------------------------------------
    // Endpoints
    // -------------------------------------------------------------------------

    /**
     * Recupera i dati dell'utente autenticato.
     *
     * @return array<mixed>
     * @throws ApiException
     */
    public function getUserMe(): array
    {
        return $this->get('/api/v1/users/self/');
    }

    // -------------------------------------------------------------------------
    // HTTP internals
    // -------------------------------------------------------------------------

    /**
     * @return array<mixed>
     * @throws ApiException
     */
    private function get(string $path): array
    {
        try {
            $response = $this->buildClient()->get($path, [
                'headers'     => $this->buildHeaders(),
                'http_errors' => false,
            ]);
        } catch (GuzzleException $e) {
            throw new ApiException("Errore di rete: {$e->getMessage()}");
        }

        return $this->parseResponse($response);
    }

    /**
     * @return array<mixed>
     * @throws ApiException
     */
    private function parseResponse(ResponseInterface $response): array
    {
        $body = json_decode((string) $response->getBody(), true) ?? [];

        if ($response->getStatusCode() !== 200) {
            $detail = $body['detail'] ?? $body['message'] ?? 'Errore sconosciuto';
            throw new ApiException(
                "HTTP {$response->getStatusCode()}: {$detail}",
                $response->getStatusCode(),
            );
        }

        return $body;
    }

    /**
     * Costruisce un client Guzzle con il cookie jar corrente dell'AuthManager.
     * Viene ricreato ad ogni chiamata così da riflettere sempre il jar aggiornato
     * (es. dopo un relogin).
     */
    private function buildClient(): Client
    {
        $cookieJar = $this->auth->getCookieJar();

        if ($this->httpClientFactory !== null) {
            return ($this->httpClientFactory)($cookieJar);
        }

        return new Client([
            'base_uri' => self::BASE_URL,
            'cookies'  => $cookieJar,
            'headers'  => [
                'Accept'     => 'application/json',
                'x-realm'    => 'frontoffice',
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'Referer'    => self::BASE_URL . '/app/dashboard',
            ],
        ]);
    }

    /**
     * Aggiunge il CSRF token se presente nel cookie jar.
     *
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [];

        foreach ($this->auth->getCookieJar() as $cookie) {
            if ($cookie->getName() === 'csrftoken') {
                $headers['x-csrftoken'] = $cookie->getValue();
                break;
            }
        }

        return $headers;
    }
}
