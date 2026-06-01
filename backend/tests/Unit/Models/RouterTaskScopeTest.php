<?php

namespace Tests\Unit\Models;

use App\Models\RouterTask;
use Illuminate\Database\Eloquent\Builder;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RouterTaskScopeTest extends TestCase
{
    #[Test]
    public function for_router_scope_applies_tenant_and_router_filters(): void
    {
        $model = new RouterTask();

        /** @var Builder&\PHPUnit\Framework\MockObject\MockObject $builder */
        $builder = $this->createMock(Builder::class);

        $builder->expects($this->exactly(2))
            ->method('where')
            ->with(
                $this->logicalOr(
                    $this->equalTo('tenant_id'),
                    $this->equalTo('router_id')
                ),
                $this->logicalOr(
                    $this->equalTo('tenant-uuid-1'),
                    $this->equalTo('router-uuid-1')
                )
            )
            ->willReturnSelf();

        $result = $model->scopeForRouter($builder, 'tenant-uuid-1', 'router-uuid-1');

        $this->assertSame($builder, $result);
    }
}
