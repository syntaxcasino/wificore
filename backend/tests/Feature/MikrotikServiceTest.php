<?php

namespace Tests\Feature;

use App\Services\MikrotikSessionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use RouterOS\Client;
use RouterOS\Query;
use RouterOS\Exceptions\ClientException;
use Mockery;
use ReflectionClass;
use RuntimeException;

beforeEach(function () {
    Config::set('mikrotik', [
        'host' => '192.168.43.2',
        'user' => 'admin',
        'pass' => 'admin',
        'port' => 8728,
        'timeout' => 10,
        'attempts' => 3,
        'delay' => 1,
    ]);

    Config::set('cache.default', 'array');
    Config::set('cache.stores.array', [
        'driver' => 'array',
        'serialize' => false,
    ]);

    Log::partialMock();
    Log::shouldReceive('channel')->andReturnSelf();
    Log::shouldReceive('info')->byDefault();
    Log::shouldReceive('error')->byDefault();
    Log::shouldReceive('warning')->byDefault();

    // Full mock (not partial) — Cache::partialMock() calls CacheManager::setEventDispatcher()
    // which calls $this->app->bound() on a null container inside the mock, causing a fatal Error.
    Cache::shouldReceive('has')->byDefault()->andReturn(false);
    Cache::shouldReceive('get')->byDefault()->andReturn(null);
    Cache::shouldReceive('put')->byDefault();
    Cache::shouldReceive('flush')->byDefault();
    Cache::shouldReceive('forget')->byDefault();
});

afterEach(function () {
    Mockery::close();
});

it('can be instantiated', function () {
    $service = new MikrotikSessionService();
    expect($service)->toBeInstanceOf(MikrotikSessionService::class);
});

it('throws exception when connection fails', function () {
    $service = new class extends MikrotikSessionService {
        protected function createClient(array $config): Client
        {
            throw new ClientException('Connection failed');
        }
    };

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('connect');
    $method->setAccessible(true);

    $method->invoke($service);
})->throws(RuntimeException::class, 'Mikrotik connection failed: Connection failed');

it('logs connection failure', function () {
    Log::shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Mikrotik connection failed') &&
                   $context['error'] === 'Connection failed' &&
                   $context['config']['pass'] === '*****';
        });

    $service = new class extends MikrotikSessionService {
        protected function createClient(array $config): Client
        {
            throw new ClientException('Connection failed');
        }
    };

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
    $mockClient->shouldReceive('query')->once()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([['name' => 'test-router']]);

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isConnected');
    $method->setAccessible(true);

    expect($method->invoke($service))->toBeTrue();
});

it('returns false when not connected', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->once()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andThrow(new ClientException('Not connected'));

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('isConnected');
    $method->setAccessible(true);

    expect($method->invoke($service))->toBeFalse();
});

it('creates a session successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]);                     // isConnected (createSession->connect)
    $mockClient->shouldReceive('read')->once()->andReturn([["ret" => '*1']]);      // createHotspotUser
    $mockClient->shouldReceive('read')->once()->andReturn([]);                     // isConnected (authenticateUser->connect)
    $mockClient->shouldReceive('read')->once()->andReturn([["success" => true]]); // authenticateUser query

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
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
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->once()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]); // isConnected

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    Cache::shouldReceive('has')->once()->andReturn(true);
    Cache::shouldReceive('put')->never();

    $response = $service->createSession('test123', '00:11:22:33:44:55', 'default', 1);

    expect($response)->toBeArray()
        ->and($response['success'])->toBeTrue()
        ->and($response['cached'])->toBeTrue();
});
it('fails when user creation fails', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]); // isConnected
    $mockClient->shouldReceive('read')->once()->andReturn([]); // createHotspotUser → empty → exception

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    $response = $service->createSession('test123', '00:11:22:33:44:55', 'default', 1);

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toContain('Failed to create user');
});

it('authenticates user successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]);                  // isConnected
    $mockClient->shouldReceive('read')->once()->andReturn([['success' => true]]); // authenticate query

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    $response = $service->authenticateUser('test123');

    expect($response)->toBeArray()
        ->and($response['success'])->toBeTrue()
        ->and($response['message'])->toBe('User authenticated successfully');
});

it('fails to authenticate user', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]); // isConnected
    $mockClient->shouldReceive('read')->once()->andThrow(new ClientException('Authentication failed'));

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    $response = $service->authenticateUser('test123');

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toContain('Authentication failed');
});

it('gets active users successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]);                                         // isConnected
    $mockClient->shouldReceive('read')->once()->andReturn([['user' => 'test1'], ['user' => 'test2']]); // active/print

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
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
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]); // isConnected
    $mockClient->shouldReceive('read')->once()->andThrow(new ClientException('Failed to get users'));

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    $response = $service->getActiveUsers();

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toBe('Failed to get active users');
});

it('sanitizes sensitive data in logs', function () {
    $service = new MikrotikSessionService();
    
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

    $service = new MikrotikSessionService();
    
    $reflection = new ReflectionClass($service);
    $method = $reflection->getMethod('logToSystemAndFile');
    $method->setAccessible(true);
    
    $method->invokeArgs($service, [$action, $details, $logLevel]);
});

it('gets all hotspot users successfully', function () {
    $mockClient = Mockery::mock(Client::class);
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]); // isConnected
    $mockClient->shouldReceive('read')->once()->andReturn([   // user/print
        ['name' => 'user1', 'profile' => 'default'],
        ['name' => 'user2', 'profile' => 'vip']
    ]);

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
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
    $mockClient->shouldReceive('query')->zeroOrMoreTimes()->andReturnSelf();
    $mockClient->shouldReceive('read')->once()->andReturn([]); // isConnected
    $mockClient->shouldReceive('read')->once()->andThrow(new ClientException('Failed to get users'));

    $service = new class extends MikrotikSessionService {
        public function setClient($client) { $this->client = $client; }
    };
    $service->setClient($mockClient);

    $response = $service->getAllHotspotUsers();

    expect($response)->toBeArray()
        ->and($response['success'])->toBeFalse()
        ->and($response['message'])->toBe('Failed to get all hotspot users');
});