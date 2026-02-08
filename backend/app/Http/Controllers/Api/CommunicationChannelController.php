<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CommunicationChannel;
use App\Events\CommunicationChannelCreated;
use App\Events\CommunicationChannelUpdated;
use App\Events\CommunicationChannelDeleted;
use App\Jobs\SendTestMessageJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class CommunicationChannelController extends Controller
{
    /**
     * List all communication channels for the tenant.
     */
    public function index(Request $request)
    {
        $channels = CommunicationChannel::orderBy('type')
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn($ch) => $ch->toSafeArray());

        return response()->json([
            'success' => true,
            'data' => $channels,
        ]);
    }

    /**
     * Get a single communication channel.
     */
    public function show(Request $request, $id)
    {
        $channel = CommunicationChannel::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $channel->toSafeArray(),
        ]);
    }

    /**
     * Create a new communication channel.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100',
            'type' => 'required|string|in:sms,whatsapp,email',
            'provider' => 'required|string|max:50',
            'credentials' => 'required|array',
            'sender_id' => 'nullable|string|max:50',
            'phone_number' => 'nullable|string|max:20',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Validate provider is supported for the type
        $supportedProviders = CommunicationChannel::supportedProviders($request->type);
        if (!in_array($request->provider, $supportedProviders)) {
            return response()->json([
                'success' => false,
                'message' => "Provider '{$request->provider}' is not supported for type '{$request->type}'. Supported: " . implode(', ', $supportedProviders),
            ], 422);
        }

        $channel = new CommunicationChannel();
        $channel->name = $request->name;
        $channel->type = $request->type;
        $channel->provider = $request->provider;
        $channel->setCredentialsFromArray($request->credentials);
        $channel->sender_id = $request->sender_id;
        $channel->phone_number = $request->phone_number;
        $channel->is_active = $request->boolean('is_active', true);
        $channel->is_default = $request->boolean('is_default', false);
        $channel->settings = $request->settings;

        // If setting as default, unset other defaults of same type
        if ($channel->is_default) {
            CommunicationChannel::where('type', $channel->type)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $channel->save();

        $tenantId = $request->user()->tenant_id;

        event(new CommunicationChannelCreated($channel->toSafeArray(), $tenantId));

        Log::info('Communication channel created', [
            'channel_id' => $channel->id,
            'type' => $channel->type,
            'provider' => $channel->provider,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Communication channel created successfully',
            'data' => $channel->toSafeArray(),
            'status' => 'completed',
        ], 201);
    }

    /**
     * Update an existing communication channel.
     */
    public function update(Request $request, $id)
    {
        $channel = CommunicationChannel::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100',
            'type' => 'sometimes|required|string|in:sms,whatsapp,email',
            'provider' => 'sometimes|required|string|max:50',
            'credentials' => 'sometimes|required|array',
            'sender_id' => 'nullable|string|max:50',
            'phone_number' => 'nullable|string|max:20',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'settings' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $type = $request->input('type', $channel->type);
        $provider = $request->input('provider', $channel->provider);

        // Validate provider is supported for the type
        $supportedProviders = CommunicationChannel::supportedProviders($type);
        if (!in_array($provider, $supportedProviders)) {
            return response()->json([
                'success' => false,
                'message' => "Provider '{$provider}' is not supported for type '{$type}'.",
            ], 422);
        }

        if ($request->has('name')) $channel->name = $request->name;
        if ($request->has('type')) $channel->type = $request->type;
        if ($request->has('provider')) $channel->provider = $request->provider;
        if ($request->has('credentials')) $channel->setCredentialsFromArray($request->credentials);
        if ($request->has('sender_id')) $channel->sender_id = $request->sender_id;
        if ($request->has('phone_number')) $channel->phone_number = $request->phone_number;
        if ($request->has('is_active')) $channel->is_active = $request->boolean('is_active');
        if ($request->has('settings')) $channel->settings = $request->settings;

        if ($request->has('is_default')) {
            $channel->is_default = $request->boolean('is_default');
            if ($channel->is_default) {
                CommunicationChannel::where('type', $channel->type)
                    ->where('id', '!=', $channel->id)
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        }

        $channel->save();

        $tenantId = $request->user()->tenant_id;

        event(new CommunicationChannelUpdated($channel->toSafeArray(), $tenantId));

        Log::info('Communication channel updated', [
            'channel_id' => $channel->id,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Communication channel updated successfully',
            'data' => $channel->toSafeArray(),
            'status' => 'completed',
        ]);
    }

    /**
     * Delete a communication channel.
     */
    public function destroy(Request $request, $id)
    {
        $channel = CommunicationChannel::findOrFail($id);
        $channelId = $channel->id;
        $tenantId = $request->user()->tenant_id;

        $channel->delete();

        event(new CommunicationChannelDeleted($channelId, $tenantId));

        Log::info('Communication channel deleted', [
            'channel_id' => $channelId,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Communication channel deleted successfully',
            'status' => 'completed',
        ]);
    }

    /**
     * Send a test message through a channel (async via job).
     */
    public function sendTest(Request $request, $id)
    {
        $channel = CommunicationChannel::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'recipient' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tenantId = $request->user()->tenant_id;

        SendTestMessageJob::dispatch(
            $channel->id,
            $request->recipient,
            $tenantId
        );

        Log::info('Test message job dispatched', [
            'channel_id' => $channel->id,
            'recipient' => $request->recipient,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Test message is being sent. You will be notified of the result.',
            'data' => [
                'channel_id' => $channel->id,
                'status' => 'processing',
            ],
        ], 202);
    }

    /**
     * Get supported types and providers.
     */
    public function providers()
    {
        $types = CommunicationChannel::supportedTypes();
        $providers = [];
        foreach ($types as $type) {
            $providers[$type] = CommunicationChannel::supportedProviders($type);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'types' => $types,
                'providers' => $providers,
            ],
        ]);
    }
}
