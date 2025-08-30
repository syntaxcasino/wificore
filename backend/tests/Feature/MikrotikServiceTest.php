<?php

namespace Tests\Feature;

use App\Services\MikrotikService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use RouterOS\Client;
use RouterOS\Exceptions\ClientException;
use Mockery;
use ReflectionClass;
use RuntimeException;

beforeEach(function () {
    // Mock the config
    Config::set('mikrotik', [
        //'host' => '192.168.100.30',
        'host' => '192.168.43.2',
        'user' => 'admin',
        'pass' => 'admin',
        'port' => 8728,
        'timeout' => 10,
        'attempts' => 3,
        'delay' => 1,
    ]);

    // Set up default cache config
    Config::set('cache.default', 'array');
    Config::set('cache.stores.array', [
        'driver' => 'array',
        'serialize' => false,
    ]);
    
    // Properly mock the Log facade
    Log::partialMock();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->byDefault();
    Log::shouldReceive('error')->byDefault();
    Log::shouldReceive('warning')->byDefault();

    // Mock Cache facade
    Cache::partialMock();
    Cache::shouldReceive('has')->byDefault()->andReturn(false);
    Cache::shouldReceive('put')->byDefault();
});

afterEach(function () {
    Mockery::close();
    Cache::flush();
});

it('can be instantiated', function () {
    $service = new MikrotikService();
    expect($service)->toBeInstanceOf(MikrotikService::class);
});

it('throws exception when connection fails', function () {
    $this->instance(Client::class, Mockery::mock(Client::class, function ($mock) {
        $mock->shouldReceive('__construct')
            ->andThrow(new ClientException('Connection failed'));
    }));

    $service = new MikrotikService();
    
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('connect');
    $method->setAccessible(true);
    
    $method->invoke($service);
})->throws(RuntimeException::class, 'Mikrotik connection failed: Connection failed');

it('logs connection failure', function () {
    $this->instance(Client::class, Mockery::mock(Client::class, function ($mock) {
        $mock->shouldReceive('__construct')
            ->andThrow(new ClientException('Connection failed'));
    }));

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Mikrotik connection failed') &&
                   $context['error'] === 'Connection failed' &&
                   $context['config']['pass'] === '*****';
        });

    $service = new MikrotikService();
    
    try {
        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('connect');
        $method->setAccessible(true);
        $method->invoke($service);
    } catch (RuntimeException $e) {
        // Expected exception
    }
});

it('returns true when connected', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->andReturn([['name' => 'test-router']]);

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isConnected');
    $method->setAccessible(true);
    
    expect($method->invoke($service))->toBeTrue();
});

it('returns false when not connected', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->andThrow(new ClientException('Not connected'));

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isConnected');
    $method->setAccessible(true);
    
    expect($method->invoke($service))->toBeFalse();
});

it('creates a session successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/user/add')
        ->andReturn([['ret' => '*1']]);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/active/login')
        ->andReturn([['success' => true]]);

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    Cache::shouldReceive('has')->once()->andReturn(false);
    Cache::shouldReceive('put')->once();

    $response = $service->createSession('test123', '00:11:22:33:44:55', 'default', 1);

    expect($response)->toBeArray()
        ->and($response['success'])->toBeTrue()
        ->and($response['message'])->toBe('User created and authenticated successfully');
});

it('returns cached response when session exists', function () {
    Cache::shouldReceive('has')->once()->andReturn(true);
    Cache::shouldReceive('put')->never();

    $service = new MikrotikService();
    $response = $service->createSession('test123', '00:11:22:33:44:55', 'default', 1);

    expect($response)->toBeArray()
        ->and($response['success'])->toBeTrue()
        ->and($response['cached'])->toBeTrue();
});

it('fails when user creation fails', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/user/add')
        ->andReturn([]);

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $response = $service->createSession('test123', '00:11:22:33:44:55', 'default', 1);

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toContain('Failed to create user');
});

it('authenticates user successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/active/login')
        ->andReturn([['success' => true]]);

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $response = $service->authenticateUser('test123');

    expect($response)->toBeArray()
        ->and($response['success'])->toBeTrue()
        ->and($response['message'])->toBe('User authenticated successfully');
});

it('fails to authenticate user', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/active/login')
        ->andThrow(new ClientException('Authentication failed'));

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $response = $service->authenticateUser('test123');

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toContain('Authentication failed');
});

it('gets active users successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/active/print')
        ->andReturn([['user' => 'test1'], ['user' => 'test2']]);

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $response = $service->getActiveUsers();

    expect($response)->toBeArray()
        ->and($response['success'])->toBeTrue()
        ->and($response['count'])->toBe(2)
        ->and($response['data'])->toHaveCount(2);
});

it('fails to get active users', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/active/print')
        ->andThrow(new ClientException('Failed to get users'));

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $response = $service->getActiveUsers();

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toBe('Failed to get active users');
});

it('sanitizes sensitive data in logs', function () {
    $service = new MikrotikService();
    
    $data = [
        'user' => 'admin',
        'pass' => 'admin',
        'details' => [
            'password' => '12345',
            'other' => 'data'
        ]
    ];
    
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('sanitizeLogData');
    $method->setAccessible(true);
    
    $sanitized = $method->invokeArgs($service, [$data]);
    
    expect($sanitized['pass'])->toBe('*****')
        ->and($sanitized['details']['password'])->toBe('*****')
        ->and($sanitized['details']['other'])->toBe('data');
});

it('logs to system and file', function () {
    $action = 'Test action';
    $details = ['key' => 'value'];
    $logLevel = 'info';

    Log::shouldReceive($logLevel)
        ->once()
        ->with($action, $details);

    $service = new MikrotikService();
    
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('logToSystemAndFile');
    $method->setAccessible(true);
    
    $method->invokeArgs($service, [$action, $details, $logLevel]);
});

it('gets all hotspot users successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/user/print')
        ->andReturn([
            ['name' => 'user1', 'profile' => 'default'],
            ['name' => 'user2', 'profile' => 'vip']
        ]);

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $response = $service->getAllHotspotUsers();

    expect($response)->toBeArray()
        ->and($response['success'])->toBeTrue()
        ->and($response['count'])->toBe(2)
        ->and($response['data'])->toHaveCount(2);
});

it('fails to get all hotspot users', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query->read')
        ->with('/ip/hotspot/user/print')
        ->andThrow(new ClientException('Failed to get users'));

    $service = new class extends MikrotikService {
        public function setClient($client) {
            $this->client = $client;
        }
    };

    $service->setClient($mockClient);

    $response = $service->getAllHotspotUsers();

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toBe('Failed to get all hotspot users');
});