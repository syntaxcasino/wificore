<?php

namespace Tests\Unit\Traits;

use App\Traits\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
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

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Tenant context is required');

        $model->fill(['name' => 'test']);
        $model->save();
    }
}
