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
     * @return list<array{id: string, userId: string, branchId: string, componentId: string, configurationId: string, workspaceSchema: string, shared: bool, status: string}>
     */
    public function listSessions(): array
    {
        $response = $this->httpClient->request('GET', '/sql/sessions', [
            'query' => ['listAll' => '1'],
        ]);

        /** @var list<array{id: string, userId: string, branchId: string, componentId: string, configurationId: string, workspaceSchema: string, shared: bool, status: string}> $sessions */
        $sessions = $response->toArray();
        return $sessions;
    }
}
