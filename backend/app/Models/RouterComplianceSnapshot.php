<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RouterComplianceSnapshot extends Model
{
    use HasFactory, HasUuid;

    protected $table = 'router_compliance_snapshots';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'score',
        'grade',
        'status',
        'checks',
        'missing_controls',
        'passed_controls',
        'summary',
        'source_snapshot_id',
        'evaluated_at',
    ];

    protected $casts = [
        'checks' => 'array',
        'missing_controls' => 'array',
        'passed_controls' => 'array',
        'evaluated_at' => 'datetime',
        'score' => 'integer',
    ];

    public function router()
    {
        return $this->belongsTo(Router::class);
    }
}
