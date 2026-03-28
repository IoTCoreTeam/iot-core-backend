<?php

namespace Modules\ControlModule\Services;

use Illuminate\Support\Facades\DB;
use Modules\ControlModule\Helpers\SystemLogHelper;
use Modules\ControlModule\Models\ControlJsonCommand;

class ControlJsonCommandService
{
    /**
     * @param array<string, mixed> $payload
     * @return array{control_json_command: ControlJsonCommand, message: string, status: int}
     */
    public function create(array $payload): array
    {
        $controlJsonCommand = DB::transaction(function () use ($payload) {
            return ControlJsonCommand::create($payload);
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
        $controlJsonCommand = DB::transaction(function () use ($id, $payload) {
            $controlJsonCommand = ControlJsonCommand::findOrFail($id);
            $controlJsonCommand->update($payload);
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
