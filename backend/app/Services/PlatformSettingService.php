<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PlatformSettingService
{
    /** @var Collection<string, string>|null */
    private ?Collection $stored = null;

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return [
            'client_registration_enabled' => [
                'group' => 'access',
                'label' => 'Public client registration',
                'description' => 'Allow drivers to create a local account from the public registration page.',
                'type' => 'boolean',
                'default' => true,
                'rules' => ['required', 'boolean'],
            ],
            'demo_requests_enabled' => [
                'group' => 'access',
                'label' => 'Demo requests',
                'description' => 'Accept new organization demo requests from the landing page.',
                'type' => 'boolean',
                'default' => true,
                'rules' => ['required', 'boolean'],
            ],
            'employee_invitation_expiration_hours' => [
                'group' => 'invitations',
                'label' => 'Employee invitation validity',
                'description' => 'Validity period for operator and technician activation links.',
                'type' => 'integer',
                'default' => (int) config('invitations.employee_expiration_hours', 72),
                'rules' => ['required', 'integer', 'min:12', 'max:336'],
                'unit' => 'hours',
                'min' => 12,
                'max' => 336,
            ],
            'employee_invitation_reminder_minutes' => [
                'group' => 'invitations',
                'label' => 'Invitation reminder cooldown',
                'description' => 'Minimum delay before an administrator can resend an employee invitation.',
                'type' => 'integer',
                'default' => (int) config('invitations.reminder_cooldown_minutes', 10),
                'rules' => ['required', 'integer', 'min:1', 'max:1440'],
                'unit' => 'minutes',
                'min' => 1,
                'max' => 1440,
            ],
            'demo_invitation_expiration_hours' => [
                'group' => 'invitations',
                'label' => 'Demo administrator invitation validity',
                'description' => 'Validity period for the activation link sent after provisioning an organization.',
                'type' => 'integer',
                'default' => (int) config('demo.invitation_expiration_hours', 48),
                'rules' => ['required', 'integer', 'min:12', 'max:168'],
                'unit' => 'hours',
                'min' => 12,
                'max' => 168,
            ],
            'audit_retention_days' => [
                'group' => 'governance',
                'label' => 'Audit history retention',
                'description' => 'Delete platform audit entries older than the selected retention period.',
                'type' => 'integer',
                'default' => 90,
                'rules' => ['required', 'integer', 'min:30', 'max:730'],
                'unit' => 'days',
                'min' => 30,
                'max' => 730,
            ],
            'support_email' => [
                'group' => 'communications',
                'label' => 'Platform support email',
                'description' => 'Public contact address used for account and platform assistance.',
                'type' => 'string',
                'default' => (string) config('mail.from.address', 'support@chargetrackr.local'),
                'rules' => ['required', 'email:rfc', 'max:254'],
            ],
        ];
    }

    public function value(string $key): mixed
    {
        $definition = $this->definition($key);
        $stored = $this->storedValues()->get($key);

        return $stored === null ? $definition['default'] : $this->decode($stored, $definition['type']);
    }

    public function boolean(string $key): bool
    {
        return (bool) $this->value($key);
    }

    public function integer(string $key): int
    {
        return (int) $this->value($key);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        $stored = $this->storedValues();

        return collect($this->definitions())->map(function (array $definition, string $key) use ($stored): array {
            $isOverridden = $stored->has($key);

            return [
                'key' => $key,
                'group' => $definition['group'],
                'label' => $definition['label'],
                'description' => $definition['description'],
                'type' => $definition['type'],
                'value' => $this->value($key),
                'default_value' => $definition['default'],
                'overridden' => $isOverridden,
                'unit' => $definition['unit'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
            ];
        })->values()->all();
    }

    public function set(string $key, mixed $value, User $actor): PlatformSetting
    {
        $definition = $this->definition($key);
        $setting = PlatformSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $this->encode($value, $definition['type']), 'updated_by_id' => $actor->id],
        );
        $this->stored = null;

        return $setting;
    }

    /** @return array<string, mixed> */
    private function definition(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if ($definition === null) {
            throw ValidationException::withMessages(["settings.{$key}" => ['This platform setting is not supported.']]);
        }

        return $definition;
    }

    /** @return Collection<string, string> */
    private function storedValues(): Collection
    {
        return $this->stored ??= PlatformSetting::query()->pluck('value', 'key');
    }

    private function encode(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'integer' => (string) ((int) $value),
            default => trim((string) $value),
        };
    }

    private function decode(string $value, string $type): mixed
    {
        return match ($type) {
            'boolean' => $value === '1',
            'integer' => (int) $value,
            default => $value,
        };
    }
}
