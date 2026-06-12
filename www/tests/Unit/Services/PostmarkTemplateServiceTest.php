<?php

declare(strict_types=1);

use App\Services\PostmarkTemplateService;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    // Set up test config
    config([
        'postmark.staging_server_token' => 'test-staging-token',
        'postmark.production_server_token' => 'test-production-token',
        'postmark.staging_server_id' => 12345,
        'postmark.production_server_id' => 67890,
    ]);
});

describe('PostmarkTemplateService::getMessageDetails', function () {
    it('fetches message details from Postmark API', function () {
        $mockResponse = [
            'MessageID' => 'test-message-id-123',
            'To' => 'test@example.com',
            'From' => 'noreply@example.com',
            'Subject' => 'Welcome to App',
            'Status' => 'Sent',
            'ReceivedAt' => '2024-01-15T10:30:00Z',
            'HtmlBody' => '<html><body>Welcome!</body></html>',
            'TextBody' => 'Welcome!',
            'Metadata' => ['user_id' => '123'],
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        // Use reflection to inject mock client
        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        $result = $service->getMessageDetails('test-message-id-123');

        expect($result)->toBeArray();
        expect($result['MessageID'])->toBe('test-message-id-123');
        expect($result['To'])->toBe('test@example.com');
        expect($result['Subject'])->toBe('Welcome to App');
        expect($result['HtmlBody'])->toBe('<html><body>Welcome!</body></html>');
    });

    it('throws exception on API error', function () {
        $mock = new MockHandler([
            new Response(404, [], json_encode(['Message' => 'Not found'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        $service->getMessageDetails('non-existent-id');
    })->throws(Exception::class);

    it('uses production token when useProduction is true', function () {
        $mockResponse = ['MessageID' => 'test-id'];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        // This should not throw an exception
        $result = $service->getMessageDetails('test-id', true);
        expect($result)->toBeArray();
    });
});

describe('PostmarkTemplateService::searchOutboundMessages', function () {
    it('searches messages by recipient email', function () {
        $mockResponse = [
            'TotalCount' => 2,
            'Messages' => [
                [
                    'MessageID' => 'msg-1',
                    'To' => [['Email' => 'test@example.com']],
                    'Subject' => 'First Email',
                    'Status' => 'Sent',
                ],
                [
                    'MessageID' => 'msg-2',
                    'To' => [['Email' => 'test@example.com']],
                    'Subject' => 'Second Email',
                    'Status' => 'Sent',
                ],
            ],
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        $result = $service->searchOutboundMessages('test@example.com');

        expect($result)->toBeArray();
        expect(count($result))->toBe(2);
        expect($result[0]['MessageID'])->toBe('msg-1');
        expect($result[1]['MessageID'])->toBe('msg-2');
    });

    it('returns empty array on API error', function () {
        $mock = new MockHandler([
            new Response(500, [], json_encode(['Message' => 'Server error'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        $result = $service->searchOutboundMessages('test@example.com');

        expect($result)->toBeArray();
        expect($result)->toBeEmpty();
    });

    it('respects count parameter', function () {
        $mockResponse = [
            'TotalCount' => 10,
            'Messages' => array_fill(0, 10, ['MessageID' => 'msg']),
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        $result = $service->searchOutboundMessages('test@example.com', 10);

        expect($result)->toBeArray();
        expect(count($result))->toBe(10);
    });
});

describe('PostmarkTemplateService::getMessageOpens', function () {
    it('fetches message opens from Postmark API', function () {
        $mockResponse = [
            'TotalCount' => 1,
            'Opens' => [
                [
                    'FirstOpen' => true,
                    'Client' => ['Name' => 'Gmail', 'Company' => 'Google'],
                    'OS' => ['Name' => 'Windows', 'Company' => 'Microsoft'],
                    'Platform' => 'Desktop',
                    'UserAgent' => 'Mozilla/5.0...',
                    'ReadSeconds' => 5,
                    'ReceivedAt' => '2024-01-15T10:35:00Z',
                ],
            ],
        ];

        $mock = new MockHandler([
            new Response(200, [], json_encode($mockResponse)),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        $result = $service->getMessageOpens('test-message-id');

        expect($result)->toBeArray();
        expect($result['TotalCount'])->toBe(1);
        expect($result['Opens'])->toHaveCount(1);
        expect($result['Opens'][0]['FirstOpen'])->toBeTrue();
    });

    it('returns empty array on API error', function () {
        $mock = new MockHandler([
            new Response(404, [], json_encode(['Message' => 'Not found'])),
        ]);

        $handlerStack = HandlerStack::create($mock);
        $mockClient = new Client(['handler' => $handlerStack]);

        $service = new PostmarkTemplateService();

        $reflection = new ReflectionClass($service);
        $clientProperty = $reflection->getProperty('client');
        $clientProperty->setAccessible(true);
        $clientProperty->setValue($service, $mockClient);

        $result = $service->getMessageOpens('non-existent-id');

        expect($result)->toBeArray();
        expect($result)->toBeEmpty();
    });
});

describe('PostmarkTemplateService::isConfigured', function () {
    it('returns true when staging token and server ID are set', function () {
        config([
            'postmark.staging_server_token' => 'valid-token',
            'postmark.staging_server_id' => 12345,
        ]);

        $service = new PostmarkTemplateService();

        expect($service->isConfigured())->toBeTrue();
    });

    it('returns false when staging token is empty', function () {
        config([
            'postmark.staging_server_token' => '',
            'postmark.staging_server_id' => 12345,
        ]);

        $service = new PostmarkTemplateService();

        expect($service->isConfigured())->toBeFalse();
    });

    it('returns false when staging server ID is empty', function () {
        config([
            'postmark.staging_server_token' => 'valid-token',
            'postmark.staging_server_id' => 0,
        ]);

        $service = new PostmarkTemplateService();

        expect($service->isConfigured())->toBeFalse();
    });
});
