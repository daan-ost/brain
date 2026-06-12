<?php

use App\Services\SenderConfigService;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

// --- extractPostmarkError ---

it('extracts postmark error message from ClientException', function () {
    $response = new Response(422, [], json_encode(['ErrorCode' => 512, 'Message' => 'Domain already exists.']));
    $exception = new ClientException('Client error', new Request('POST', '/domains'), $response);

    $message = SenderConfigService::extractPostmarkError($exception);

    expect($message)->toBe('Domain already exists.');
});

it('returns null for non-ClientException', function () {
    $exception = new \RuntimeException('Something went wrong');

    $message = SenderConfigService::extractPostmarkError($exception);

    expect($message)->toBeNull();
});

it('returns null when response has no Message field', function () {
    $response = new Response(500, [], json_encode(['error' => 'internal']));
    $exception = new ClientException('Server error', new Request('POST', '/senders'), $response);

    $message = SenderConfigService::extractPostmarkError($exception);

    expect($message)->toBeNull();
});

// --- extractDnsRecords (via reflection) ---

it('extracts pending DKIM records for new domains', function () {
    $service = new SenderConfigService();
    $method = new ReflectionMethod($service, 'extractDnsRecords');

    $response = [
        'DKIMHost' => '',
        'DKIMTextValue' => '',
        'DKIMPendingHost' => '20260309pm._domainkey.example.com',
        'DKIMPendingTextValue' => 'k=rsa; p=ABC123',
        'DKIMVerified' => false,
        'ReturnPathDomain' => 'pm-bounces.example.com',
        'ReturnPathDomainCNAMEValue' => 'pm.mtasv.net',
        'ReturnPathDomainVerified' => false,
    ];

    $records = $method->invoke($service, $response);

    expect($records)->toHaveCount(2);
    expect($records[0]['type'])->toBe('TXT');
    expect($records[0]['name'])->toBe('20260309pm._domainkey.example.com');
    expect($records[0]['value'])->toBe('k=rsa; p=ABC123');
    expect($records[0]['verified'])->toBeFalse();
    expect($records[1]['type'])->toBe('CNAME');
});

it('uses active DKIM fields when pending are empty', function () {
    $service = new SenderConfigService();
    $method = new ReflectionMethod($service, 'extractDnsRecords');

    $response = [
        'DKIMHost' => 'pm._domainkey.example.com',
        'DKIMTextValue' => 'k=rsa; p=XYZ789',
        'DKIMVerified' => true,
        'ReturnPathDomain' => 'pm-bounces.example.com',
        'ReturnPathDomainCNAMEValue' => 'pm.mtasv.net',
        'ReturnPathDomainVerified' => true,
    ];

    $records = $method->invoke($service, $response);

    expect($records[0]['name'])->toBe('pm._domainkey.example.com');
    expect($records[0]['value'])->toBe('k=rsa; p=XYZ789');
    expect($records[0]['verified'])->toBeTrue();
    expect($records[1]['verified'])->toBeTrue();
});

it('returns empty array when no DNS fields present', function () {
    $service = new SenderConfigService();
    $method = new ReflectionMethod($service, 'extractDnsRecords');

    $records = $method->invoke($service, []);

    expect($records)->toBeEmpty();
});
