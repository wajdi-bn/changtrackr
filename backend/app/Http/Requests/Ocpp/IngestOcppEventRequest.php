<?php

namespace App\Http\Requests\Ocpp;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IngestOcppEventRequest extends FormRequest
{
    private const ACTIONS = [
        'ConnectionOpened',
        'ConnectionClosed',
        'BootNotification',
        'Heartbeat',
        'StatusNotification',
        'Authorize',
        'StartTransaction',
        'MeterValues',
        'StopTransaction',
    ];

    private const STATUSES = [
        'Available',
        'Preparing',
        'Charging',
        'SuspendedEVSE',
        'SuspendedEV',
        'Finishing',
        'Reserved',
        'Unavailable',
        'Faulted',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $action = $this->input('action');
        $isBoot = $action === 'BootNotification';
        $isStatus = $action === 'StatusNotification';
        $isAuthorize = $action === 'Authorize';
        $isStart = $action === 'StartTransaction';
        $isMeterValues = $action === 'MeterValues';
        $isStop = $action === 'StopTransaction';

        return [
            'event_id' => ['required', 'uuid'],
            'connection_id' => ['nullable', 'uuid'],
            'station_identity' => ['required', 'string', 'max:80'],
            'message_id' => ['required', 'string', 'max:120'],
            'protocol_version' => ['required', 'in:1.6'],
            'action' => ['required', Rule::in(self::ACTIONS)],
            'payload' => ['present', 'array'],
            'payload.chargePointVendor' => [Rule::requiredIf($isBoot), 'string', 'max:255'],
            'payload.chargePointModel' => [Rule::requiredIf($isBoot), 'string', 'max:255'],
            'payload.connectorId' => [Rule::requiredIf($isStatus || $isStart || $isMeterValues), 'integer', 'min:0', 'max:65535'],
            'payload.errorCode' => [Rule::requiredIf($isStatus), 'string', 'max:120'],
            'payload.status' => [Rule::requiredIf($isStatus), Rule::in(self::STATUSES)],
            'payload.idTag' => [Rule::requiredIf($isAuthorize || $isStart), 'nullable', 'string', 'max:20'],
            'payload.meterStart' => [Rule::requiredIf($isStart), 'nullable', 'integer', 'min:0'],
            'payload.meterStop' => [Rule::requiredIf($isStop), 'nullable', 'integer', 'min:0'],
            'payload.transactionId' => [Rule::requiredIf($isStop), 'nullable', 'integer', 'min:1'],
            'payload.timestamp' => [Rule::requiredIf($isStart || $isStop), 'nullable', 'date'],
            'payload.reservationId' => ['nullable', 'integer', 'min:0'],
            'payload.reason' => $isStop
                ? ['nullable', Rule::in([
                    'EmergencyStop', 'EVDisconnected', 'HardReset', 'Local', 'Other', 'PowerLoss',
                    'Reboot', 'Remote', 'SoftReset', 'UnlockCommand', 'DeAuthorized',
                ])]
                : ['nullable', 'string', 'max:255'],
            'payload.meterValue' => [Rule::requiredIf($isMeterValues), 'nullable', 'array', 'min:1'],
            'payload.meterValue.*.timestamp' => ['required', 'date'],
            'payload.meterValue.*.sampledValue' => ['required', 'array', 'min:1'],
            'payload.meterValue.*.sampledValue.*.value' => ['required', 'numeric'],
            'payload.meterValue.*.sampledValue.*.context' => ['nullable', 'string', 'max:40'],
            'payload.meterValue.*.sampledValue.*.format' => ['nullable', 'in:Raw'],
            'payload.meterValue.*.sampledValue.*.measurand' => ['nullable', 'string', 'max:80'],
            'payload.meterValue.*.sampledValue.*.phase' => ['nullable', 'string', 'max:24'],
            'payload.meterValue.*.sampledValue.*.location' => ['nullable', 'string', 'max:24'],
            'payload.meterValue.*.sampledValue.*.unit' => ['nullable', 'string', 'max:16'],
            'payload.transactionData' => ['nullable', 'array'],
            'occurred_at' => ['required', 'date'],
        ];
    }
}
