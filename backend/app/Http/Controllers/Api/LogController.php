<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

use App\Models\SystemLog;

class LogController extends Controller
{
  

     public function index(Request $request)
    {
        try {
         $query = SystemLog::query();
        if ($request->has('search')) {
            $query->where('action', 'like', '%' . $request->search . '%')
                  ->orWhere('details', 'like', '%' . $request->search . '%');
        }
        return $query->where('created_at', '>=', now()->subDays(30))->orderByDesc('id')->paginate(100);

        } catch (\Exception $e) {
            \Log::error('Failed to fetch logs', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch logs',
            ], 500);
        }
    }
  }