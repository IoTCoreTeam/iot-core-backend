<?php

namespace Modules\ControlModule\Services;

use Illuminate\Support\Facades\DB;
use Modules\ControlModule\Helpers\SystemLogHelper;
use Modules\ControlModule\Models\ControlJsonCommand;

class ControlJsonCommandService
{
    /**
     * Normalize request payload for DB schema.
     *
     * Accepts either:
     * - { name, command }
     * - { command: { name, payload } } (legacy frontend shape)
     *
     * @param array<string, mixed> $payload
     * @param bool $requireName
     * @return array<string, mixed>
     */
    private function normalizePayload(array $payload, bool $requireName = false): array
    {
        $normalized = $payload;
        $command = $payload['command'] ?? null;

        $legacyName = is_array($command) ? ($command['name'] ?? null) : null;
        $legacyPayload = is_array($command) && array_key_exists('payload', $command)
            ? $command['payload']
            : null;

        if (!array_key_exists('name', $normalized) || $normalized['name'] === null || $normalized['name'] === '') {
            if (is_string($legacyName) && trim($legacyName) !== '') {
                $normalized['name'] = trim($legacyName);
            }
        } elseif (is_string($normalized['name'])) {
            $normalized['name'] = trim($normalized['name']);
        }

        if (
            is_array($command) &&
            array_key_exists('name', $command) &&
            array_key_exists('payload', $command)
        ) {
            $normalized['command'] = $legacyPayload;
        }

        if ($requireName && (!isset($normalized['name']) || !is_string($normalized['name']) || $normalized['name'] === '')) {
            throw new \InvalidArgumentException('Command name is required.');
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{control_json_command: ControlJsonCommand, message: string, status: int}
     */
    public function create(array $payload): array
    {
        $normalizedPayload = $this->normalizePayload($payload, true);

        $controlJsonCommand = DB::transaction(function () use ($normalizedPayload) {
            return ControlJsonCommand::create($normalizedPayload);
        });

        SystemLogHelper::log('control_json_command.created', 'Control json command created successfully', [
            'control_json_command_id' => $controlJsonCommand->id,
        ]);

        return [
            'control_json_command' => $controlJsonCommand->refresh(),
            'message' => 'Control json command created successfully',
            'status' => 201,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{control_json_command: ControlJsonCommand, message: string}
     */
    public function update(string $id, array $payload): array
    {
        $normalizedPayload = $this->normalizePayload($payload, false);

        $controlJsonCommand = DB::transaction(function () use ($id, $normalizedPayload) {
            $controlJsonCommand = ControlJsonCommand::findOrFail($id);
            $controlJsonCommand->update($normalizedPayload);
            return $controlJsonCommand;
        });

        SystemLogHelper::log('control_json_command.updated', 'Control json command updated successfully', [
            'control_json_command_id' => $controlJsonCommand->id,
        ]);

        return [
            'control_json_command' => $controlJsonCommand->refresh(),
            'message' => 'Control json command updated successfully',
        ];
    }

    public function delete(string $id): void
    {
        DB::transaction(function () use ($id) {
            $controlJsonCommand = ControlJsonCommand::findOrFail($id);
            $controlJsonCommand->delete();
        });

        SystemLogHelper::log('control_json_command.deleted', 'Control json command deleted successfully', [
            'control_json_command_id' => $id,
        ]);
    }
}
