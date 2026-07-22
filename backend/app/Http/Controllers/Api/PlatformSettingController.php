<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PlatformAuditService;
use App\Services\PlatformSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PlatformSettingController extends Controller
{
    public function index(Request $request, PlatformSettingService $settings): JsonResponse
    {
        $this->authorizeAccess($request);

        return response()->json($this->responsePayload($settings));
    }

    public function update(Request $request, PlatformSettingService $settings, PlatformAuditService $audit): JsonResponse
    {
        $this->authorizeAccess($request);
        $input = $request->validate(['settings' => ['required', 'array', 'min:1']]);
        $definitions = $settings->definitions();
        $errors = [];
        $validated = [];

        foreach ($input['settings'] as $key => $value) {
            if (! is_string($key) || ! isset($definitions[$key])) {
                $errors["settings.{$key}"] = ['This platform setting is not supported.'];

                continue;
            }
            $validator = Validator::make(['value' => $value], ['value' => $definitions[$key]['rules']]);
            if ($validator->fails()) {
                $errors["settings.{$key}"] = $validator->errors()->get('value');

                continue;
            }
            $validated[$key] = $validator->validated()['value'];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($request, $settings, $audit, $validated): void {
            foreach ($validated as $key => $value) {
                $before = $settings->value($key);
                if ($before === $value) {
                    continue;
                }
                $setting = $settings->set($key, $value, $request->user());
                $audit->record(
                    $request->user(),
                    'setting.updated',
                    $setting,
                    "Updated platform setting {$key}.",
                    ['changed_fields' => [$key], 'before' => $before, 'after' => $value],
                );
            }
        });

        return response()->json($this->responsePayload($settings));
    }

    /** @return array<string, mixed> */
    private function responsePayload(PlatformSettingService $settings): array
    {
        $items = collect($settings->payload());

        return [
            'data' => [
                'groups' => [
                    ['id' => 'access', 'label' => 'Public access', 'description' => 'Control public account and organization onboarding entry points.'],
                    ['id' => 'invitations', 'label' => 'Invitations', 'description' => 'Set activation link validity and reminder behavior.'],
                    ['id' => 'commercial', 'label' => 'Commercial lifecycle', 'description' => 'Configure organization trials, grace periods and evaluation quotas.'],
                    ['id' => 'communications', 'label' => 'Communications', 'description' => 'Maintain the public support contact used by the platform.'],
                    ['id' => 'governance', 'label' => 'Governance', 'description' => 'Apply platform-wide audit retention rules.'],
                ],
                'settings' => $items->values(),
                'safeguards' => $this->safeguards(),
            ],
            'summary' => [
                'settings' => $items->count(),
                'overrides' => $items->where('overridden', true)->count(),
                'enabled_controls' => $items->where('type', 'boolean')->where('value', true)->count(),
                'environment' => (string) config('app.env'),
            ],
        ];
    }

    /** @return list<array<string, string>> */
    private function safeguards(): array
    {
        $debug = (bool) config('app.debug');
        $https = str_starts_with((string) config('app.url'), 'https://');

        return [
            ['label' => 'Application environment', 'value' => (string) config('app.env'), 'status' => app()->environment('production') ? 'operational' : 'development'],
            ['label' => 'Debug mode', 'value' => $debug ? 'Enabled' : 'Disabled', 'status' => $debug ? 'attention' : 'operational'],
            ['label' => 'HTTPS application URL', 'value' => $https ? 'Enabled' : 'Local HTTP', 'status' => $https ? 'operational' : 'development'],
            ['label' => 'Queue connection', 'value' => (string) config('queue.default'), 'status' => config('queue.default') === 'sync' ? 'attention' : 'operational'],
            ['label' => 'Broadcast connection', 'value' => (string) config('broadcasting.default'), 'status' => config('broadcasting.default') === 'null' ? 'attention' : 'operational'],
        ];
    }

    private function authorizeAccess(Request $request): void
    {
        abort_unless($request->user()?->hasRole('super_admin') && $request->user()->can('settings.manage'), 403);
    }
}
