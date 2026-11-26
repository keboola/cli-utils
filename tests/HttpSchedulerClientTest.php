<?php

namespace Keboola\Console\Tests;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Keboola\Console\Command\HttpSchedulerClient;
use Keboola\Console\Command\SchedulerClientException;
use PHPUnit\Framework\TestCase;

class HttpSchedulerClientTest extends TestCase
{
    /**
     * @param array<int, Response> $responses
     * @param array<int, array<string, mixed>> $history
     */
    private function client(array $responses, array &$history = []): HttpSchedulerClient
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($history));

        return new HttpSchedulerClient('https://scheduler.keboola.com', 'token', $stack);
    }

    public function testPostsOwnConfigurationIdAsSource(): void
    {
        $history = [];
        $this->client([new Response(200, [], '{}')], $history)->refreshSchedule('s1');

        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertSame('https://scheduler.keboola.com/schedules/migrate', (string) $request->getUri());
        $this->assertSame('token', $request->getHeaderLine('X-StorageApi-Token'));
        $this->assertSame(['configurationId' => 's1', 'sourceConfigurationId' => 's1'], json_decode((string) $request->getBody(), true));
    }

    public function testScheduleWithoutSchedulerRowIsNotAnError(): void
    {
        $body = '{"status":"error","error":"Cannot reuse token: no schedule found for configuration \"s1\"."}';

        $this->client([new Response(400, [], $body)])->refreshSchedule('s1');

        $this->addToAssertionCount(1);
    }

    public function testOtherBadRequestIsAnError(): void
    {
        $this->expectException(SchedulerClientException::class);

        $this->client([new Response(400, [], '{"status":"error","error":"Invalid configuration"}')])->refreshSchedule('s1');
    }

    public function testServerErrorIsAnError(): void
    {
        $this->expectException(SchedulerClientException::class);

        $this->client([new Response(500, [], '{}')])->refreshSchedule('s1');
    }
}
