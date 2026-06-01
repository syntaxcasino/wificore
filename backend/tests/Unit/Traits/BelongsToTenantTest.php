<?php

namespace Tests\Unit\Traits;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class BelongsToTenantTest extends TestCase
{
    public function test_it_fails_fast_when_tenant_context_is_missing(): void
    {
        $model = new class extends Model {
            use BelongsToTenant;

            protected $table = 'non_persistent_tenant_test_models';

            protected $guarded = [];

            public $timestamps = false;
        };

        Log::spy();

        try {
            $model->fill(['name' => 'test']);
            $model->save();
            $this->fail('Expected tenant context exception was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Tenant context is required', $e->getMessage());
        }

        Log::shouldHaveReceived('critical')->once();
    }
}
