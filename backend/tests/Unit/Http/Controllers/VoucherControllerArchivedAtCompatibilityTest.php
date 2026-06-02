<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Controllers;

use App\Http\Controllers\Api\VoucherController;
use Illuminate\Support\Facades\Schema;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

class VoucherControllerArchivedAtCompatibilityTest extends TestCase
{
    public function test_voucher_supports_archiving_returns_false_when_the_column_is_missing(): void
    {
        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('vouchers', 'archived_at')
            ->andReturnFalse();

        $controller = app(VoucherController::class);
        $result = $this->invokePrivate($controller, 'voucherSupportsArchiving');

        $this->assertFalse($result);
    }

    public function test_voucher_list_columns_excludes_archived_at_when_the_column_is_missing(): void
    {
        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('vouchers', 'archived_at')
            ->andReturnFalse();

        $controller = app(VoucherController::class);
        $columns = $this->invokePrivate($controller, 'voucherListColumns');

        $this->assertNotContains('archived_at', $columns);
        $this->assertContains('code', $columns);
    }

    public function test_apply_archived_filter_is_a_noop_when_the_column_is_missing(): void
    {
        Schema::shouldReceive('hasColumn')
            ->once()
            ->with('vouchers', 'archived_at')
            ->andReturnFalse();

        $query = Mockery::mock();
        $query->shouldNotReceive('whereNull');
        $query->shouldNotReceive('whereNotNull');

        $controller = app(VoucherController::class);
        $result = $this->invokePrivate($controller, 'applyArchivedFilter', [$query, false]);

        $this->assertSame($query, $result);
    }

    private function invokePrivate(object $object, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionClass($object);
        $reflectedMethod = $reflection->getMethod($method);
        $reflectedMethod->setAccessible(true);

        return $reflectedMethod->invokeArgs($object, $arguments);
    }
}
