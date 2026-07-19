<?php

namespace App\Console\Commands;

use App\Models\OcppIdTag;
use App\Models\User;
use App\Services\Ocpp\OcppAuthorizationService;
use Illuminate\Console\Command;

class ProvisionOcppIdTag extends Command
{
    protected $signature = 'ocpp:provision-id-tag
        {email : Email of an active client account}
        {--token= : OCPP idTag; defaults to OCPP_SIMULATOR_ID_TAG}
        {--label=Simulator RFID : Human-readable label}';

    protected $description = 'Assign an OCPP idTag to an existing client without storing the raw token';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $token = (string) ($this->option('token') ?: config('ocpp.simulator.id_tag'));
        $user = User::query()->where('email', $email)->first();

        if ($user === null || $user->status !== 'active' || ! $user->hasRole('client')) {
            $this->error('The account must exist and be an active client.');

            return self::FAILURE;
        }

        if (strlen($token) < 4 || strlen($token) > 20) {
            $this->error('The OCPP idTag must contain between 4 and 20 characters.');

            return self::FAILURE;
        }

        OcppIdTag::query()->updateOrCreate(
            ['token_hash' => OcppAuthorizationService::hash($token)],
            [
                'user_id' => $user->id,
                'masked_token' => OcppAuthorizationService::mask($token),
                'label' => (string) $this->option('label'),
                'status' => 'active',
                'expires_at' => null,
            ],
        );

        $this->info("OCPP idTag assigned to [{$email}] as [".OcppAuthorizationService::mask($token).'].');

        return self::SUCCESS;
    }
}
