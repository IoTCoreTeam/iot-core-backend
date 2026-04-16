<?php

namespace Modules\ControlModule\Services\Workflows;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class WorkflowRunStateStore
{
    private const RUN_TTL_SECONDS = 7200;
    private const ACTIVE_LOCK_TTL_SECONDS = 3600;
    private const MAX_EVENTS = 1000;

    /**
     * @return array{run_id: string, status: string}
     */
    public function createRun(string $workflowId, ?int $actorId = null): array
    {
        $activeKey = $this->activeKey($workflowId);
        $runId = (string) Str::uuid();

        $acquired = Cache::add($activeKey, $runId, now()->addSeconds(self::ACTIVE_LOCK_TTL_SECONDS));
        if (! $acquired) {
            throw new \RuntimeException('Workflow is already running.');
        }

        $state = [
            'run_id' => $runId,
            'workflow_id' => $workflowId,
            'actor_id' => $actorId,
            'status' => 'queued',
            'events' => [],
            'result' => null,
            'error' => null,
            'main_finished' => false,
            'pending_off_jobs' => 0,
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        Cache::put($this->runKey($runId), $state, now()->addSeconds(self::RUN_TTL_SECONDS));

        return [
            'run_id' => $runId,
            'status' => 'queued',
        ];
    }

    public function markRunning(string $runId): void
    {
        $state = $this->state($runId);
        if (! $state) {
            return;
        }
        if ($this->isTerminalStatus((string) ($state['status'] ?? ''))) {
            return;
        }
        $state['status'] = 'running';
        $state['updated_at'] = now()->toISOString();
        $this->persist($runId, $state);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function incrementPendingOffJobs(string $runId, array $context = []): void
    {
        $state = $this->state($runId);
        if (! $state) {
            return;
        }
        if ($this->isTerminalStatus((string) ($state['status'] ?? ''))) {
            return;
        }

        $pending = (int) ($state['pending_off_jobs'] ?? 0);
        $pending++;
        $state['pending_off_jobs'] = $pending;
        $state['updated_at'] = now()->toISOString();
        $this->persist($runId, $state);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function completePendingOffJob(string $runId, bool $success, array $context = []): void
    {
        $state = $this->state($runId);
        if (! $state) {
            return;
        }
        if ($this->isTerminalStatus((string) ($state['status'] ?? ''))) {
            return;
        }

        $pending = (int) ($state['pending_off_jobs'] ?? 0);
        $pending = max(0, $pending - 1);
        $state['pending_off_jobs'] = $pending;

        $events = $this->normalizeEvents($state['events'] ?? []);
        $events[] = $this->buildEvent(
            $success ? 'action_off_delayed_completed' : 'action_off_delayed_failed',
            array_merge($context, ['pending_off_jobs' => $pending]),
            $success ? 'info' : 'error'
        );

        if (($state['main_finished'] ?? false) && $pending === 0 && ($state['status'] ?? null) !== 'failed') {
            $state['status'] = 'completed';
            $events[] = $this->buildEvent('workflow_completed', [
                'workflow_id' => $state['workflow_id'] ?? null,
                'mode' => 'after_delayed_off_jobs',
            ]);
            $this->releaseActiveLock($state, $runId);
        }

        $state['events'] = $this->trimEvents($events);
        $state['updated_at'] = now()->toISOString();
        $this->persist($runId, $state);
    }

    /**
     * @param array<string, mixed> $event
     */
    public function appendEvent(string $runId, array $event): void
    {
        $state = $this->state($runId);
        if (! $state) {
            return;
        }
        if ($this->isTerminalStatus((string) ($state['status'] ?? ''))) {
            return;
        }

        if (($state['status'] ?? null) === 'queued') {
            $state['status'] = 'running';
        }

        $events = $state['events'] ?? [];
        if (! is_array($events)) {
            $events = [];
        }
        $events[] = $event;
        if (count($events) > self::MAX_EVENTS) {
            $events = array_slice($events, -self::MAX_EVENTS);
        }

        $state['events'] = $events;
        $state['updated_at'] = now()->toISOString();
        $this->persist($runId, $state);
    }

    /**
     * @param array<string, mixed> $result
     */
    public function markMainFinished(string $runId, array $result): void
    {
        $state = $this->state($runId);
        if (! $state) {
            return;
        }
        if ($this->isTerminalStatus((string) ($state['status'] ?? ''))) {
            return;
        }
        $state['main_finished'] = true;
        $state['result'] = $result;
        $pending = (int) ($state['pending_off_jobs'] ?? 0);

        $events = $this->normalizeEvents($state['events'] ?? []);
        if ($pending > 0) {
            $state['status'] = 'waiting_off_jobs';
            $events[] = $this->buildEvent('workflow_waiting_off_jobs', [
                'pending_off_jobs' => $pending,
            ]);
        } else {
            $state['status'] = 'completed';
            $events[] = $this->buildEvent('workflow_completed', [
                'workflow_id' => $state['workflow_id'] ?? null,
                'mode' => 'no_delayed_off_jobs',
            ]);
            $this->releaseActiveLock($state, $runId);
        }

        $state['events'] = $this->trimEvents($events);
        $state['updated_at'] = now()->toISOString();
        $this->persist($runId, $state);
    }

    public function markFailed(string $runId, string $message): void
    {
        $state = $this->state($runId);
        if (! $state) {
            return;
        }
        if (($state['status'] ?? null) === 'stopped') {
            return;
        }
        $state['status'] = 'failed';
        $state['error'] = $message;
        $events = $this->normalizeEvents($state['events'] ?? []);
        $events[] = $this->buildEvent('workflow_failed', [
            'workflow_id' => $state['workflow_id'] ?? null,
            'error' => $message,
        ], 'error');
        $state['events'] = $this->trimEvents($events);
        $state['updated_at'] = now()->toISOString();
        $this->persist($runId, $state);
        $this->releaseActiveLock($state, $runId);
    }

    public function markStopped(string $runId, ?string $reason = null): void
    {
        $state = $this->state($runId);
        if (! $state) {
            return;
        }

        if (($state['status'] ?? null) === 'stopped') {
            return;
        }

        $state['status'] = 'stopped';
        if ($reason !== null && $reason !== '') {
            $state['error'] = $reason;
        }
        $state['main_finished'] = true;

        $events = $this->normalizeEvents($state['events'] ?? []);
        $events[] = $this->buildEvent('workflow_stopped', [
            'workflow_id' => $state['workflow_id'] ?? null,
            'run_id' => $runId,
            'reason' => $reason,
        ]);
        $state['events'] = $this->trimEvents($events);
        $state['updated_at'] = now()->toISOString();
        $this->persist($runId, $state);
        $this->releaseActiveLock($state, $runId);
    }

    public function getActiveRunId(string $workflowId): ?string
    {
        $value = Cache::get($this->activeKey($workflowId));
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function read(string $runId, int $offset = 0): array
    {
        $state = $this->state($runId);
        if (! $state) {
            throw new \RuntimeException('Workflow run not found.');
        }

        $events = $state['events'] ?? [];
        if (! is_array($events)) {
            $events = [];
        }

        $safeOffset = max(0, $offset);
        $slice = array_slice($events, $safeOffset);

        return [
            'run_id' => $runId,
            'workflow_id' => $state['workflow_id'] ?? null,
            'status' => $state['status'] ?? 'queued',
            'events' => array_values($slice),
            'next_offset' => $safeOffset + count($slice),
            'result' => $state['result'] ?? null,
            'error' => $state['error'] ?? null,
            'main_finished' => (bool) ($state['main_finished'] ?? false),
            'pending_off_jobs' => (int) ($state['pending_off_jobs'] ?? 0),
            'updated_at' => $state['updated_at'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function state(string $runId): ?array
    {
        $state = Cache::get($this->runKey($runId));
        return is_array($state) ? $state : null;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function persist(string $runId, array $state): void
    {
        Cache::put($this->runKey($runId), $state, now()->addSeconds(self::RUN_TTL_SECONDS));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function releaseActiveLock(array $state, string $runId): void
    {
        $workflowId = (string) ($state['workflow_id'] ?? '');
        if ($workflowId === '') {
            return;
        }

        $activeKey = $this->activeKey($workflowId);
        $activeRunId = Cache::get($activeKey);
        if ($activeRunId === $runId) {
            Cache::forget($activeKey);
        }
    }

    private function runKey(string $runId): string
    {
        return 'workflow_run:' . $runId;
    }

    private function activeKey(string $workflowId): string
    {
        return 'workflow_active_run:' . $workflowId;
    }

    /**
     * @param mixed $events
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEvents(mixed $events): array
    {
        return is_array($events) ? $events : [];
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return array<int, array<string, mixed>>
     */
    private function trimEvents(array $events): array
    {
        if (count($events) > self::MAX_EVENTS) {
            return array_slice($events, -self::MAX_EVENTS);
        }
        return $events;
    }

    private function isTerminalStatus(string $status): bool
    {
        return in_array($status, ['completed', 'failed', 'stopped'], true);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function buildEvent(string $type, array $context = [], string $level = 'info'): array
    {
        return array_merge([
            'timestamp' => now()->toISOString(),
            'type' => $type,
            'level' => $level,
        ], $context);
    }
}
