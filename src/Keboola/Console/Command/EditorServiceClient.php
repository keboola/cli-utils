<?php

namespace Keboola\Console\Command;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class EditorServiceClient
{
    private HttpClientInterface $httpClient;

    public function __construct(string $url, string $token)
    {
        $this->httpClient = HttpClient::createForBaseUri($url, [
            'headers' => [
                'X-StorageApi-Token' => $token,
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(): array
    {
        $response = $this->httpClient->request('GET', '/sql/sessions', [
            'query' => ['listAll' => '1'],
        ]);

        return $response->toArray();
    }
}
