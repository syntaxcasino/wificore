<?php

// Script to create all remaining event files for HR and Finance modules

$events = [
    // HR Events
    ['name' => 'DepartmentDeleted', 'model' => 'Department', 'channel' => 'departments'],
    ['name' => 'PositionCreated', 'model' => 'Position', 'channel' => 'positions'],
    ['name' => 'PositionUpdated', 'model' => 'Position', 'channel' => 'positions'],
    ['name' => 'PositionDeleted', 'model' => 'Position', 'channel' => 'positions'],
    ['name' => 'EmployeeCreated', 'model' => 'Employee', 'channel' => 'employees'],
    ['name' => 'EmployeeUpdated', 'model' => 'Employee', 'channel' => 'employees'],
    ['name' => 'EmployeeDeleted', 'model' => 'Employee', 'channel' => 'employees'],
    // Finance Events
    ['name' => 'ExpenseCreated', 'model' => 'Expense', 'channel' => 'expenses'],
    ['name' => 'ExpenseUpdated', 'model' => 'Expense', 'channel' => 'expenses'],
    ['name' => 'ExpenseDeleted', 'model' => 'Expense', 'channel' => 'expenses'],
    ['name' => 'RevenueCreated', 'model' => 'Revenue', 'channel' => 'revenues'],
    ['name' => 'RevenueUpdated', 'model' => 'Revenue', 'channel' => 'revenues'],
    ['name' => 'RevenueDeleted', 'model' => 'Revenue', 'channel' => 'revenues'],
];

$baseDir = __DIR__ . '/backend/app/Events/';

foreach ($events as $event) {
    $name = $event['name'];
    $model = $event['model'];
    $channel = $event['channel'];
    $action = strtolower(preg_replace('/([A-Z])/', '.$1', lcfirst(str_replace($model, '', $name))));
    
    $isDeleted = str_contains($name, 'Deleted');
    $isUpdated = str_contains($name, 'Updated');
    
    $content = "<?php

namespace App\Events;

use App\Models\\{$model};
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Queue\ShouldQueue;

class {$name} implements ShouldBroadcast, ShouldQueue
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public \$connection = 'database';
    public \$queue = 'broadcasts';

    public \$data;
    " . ($isUpdated ? "public \$changes;\n    " : "") . "public \$tenantId;

    public function __construct(" . ($isDeleted ? "string \$id, array \$data" : "{$model} \${strtolower($model)}") . ($isUpdated ? ", array \$changes = []" : "") . ", ?string \$tenantId = null)
    {
        \$this->tenantId = \$tenantId;
        " . ($isUpdated ? "\$this->changes = \$changes;\n        " : "") . "
        " . ($isDeleted ? "\$this->data = array_merge(['id' => \$id], \$data);" : "\$this->data = [
            'id' => \${strtolower($model)}->id,
            'created_at' => \${strtolower($model)}->created_at?->toIso8601String(),
            'updated_at' => \${strtolower($model)}->updated_at?->toIso8601String(),
        ];") . "
    }

    public function broadcastOn(): array
    {
        \$channels = [];
        if (\$this->tenantId) {
            \$channels[] = new PrivateChannel(\"tenant.{\$this->tenantId}.{$channel}\");
        }
        return \$channels;
    }

    public function broadcastAs(): string
    {
        return '" . strtolower($model) . $action . "';
    }

    public function broadcastWith(): array
    {
        return [
            '" . strtolower($model) . "' => \$this->data,
            " . ($isUpdated ? "'changes' => \$this->changes,\n            " : "") . "'timestamp' => now()->toIso8601String(),
        ];
    }
}
";
    
    $filename = $baseDir . $name . '.php';
    file_put_contents($filename, $content);
    echo "✅ Created: {$name}.php\n";
}

echo "\n🎉 All events created successfully!\n";
