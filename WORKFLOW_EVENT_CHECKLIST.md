# Workflow Event Split Checklist (Backend -> Server -> Frontend)

Mục tiêu: tách rõ `workflow-status` (lifecycle) và `control-queue-status` (command-level), không đưa `start/end` vào queue command.

## Commit 1 - Backend: Event contract + run_id/workflow_id propagation

- [ ] Định nghĩa contract event thống nhất:
- `workflow-status`: `workflow_started`, `workflow_completed`, `workflow_failed`, `workflow_stopped`
- `control-queue-status`: giữ nguyên status command (`queued`, `dispatched`, `completed`, `failed`, ...)
- field chung bắt buộc: `run_id`, `workflow_id`, `ts`, `source`
- [ ] Tạo/chuẩn hóa `run_id` tại luồng run workflow.
- [ ] Đảm bảo `run_id`, `workflow_id` được gắn vào payload command gửi xuống Node server.
- [ ] Gắn metadata trên tất cả action commands (bao gồm OFF sinh tự động).
- [ ] Cập nhật docs backend nếu cần (workflow run flow + event schema).

File tâm điểm:
- `backend/Modules/ControlModule/app/Http/Controllers/WorkflowController.php`
- `backend/Modules/ControlModule/app/Jobs/RunWorkflowJob.php`
- `backend/Modules/ControlModule/app/Services/WorkflowRunService.php`
- `backend/Modules/ControlModule/app/Services/ControlCommandExecutionService.php`

Commit message gợi ý:
- `feat(workflow): propagate run_id/workflow_id and define workflow-status contract`

Acceptance:
- API run trả về đủ thông tin để FE theo dõi run (`workflow_id`, `run_id`, `status=queued`).
- Mọi command do workflow sinh ra đều có metadata run/workflow.

## Commit 2 - Server: SSE workflow-status + enrich control-queue-status

- [ ] Thêm luồng SSE `workflow-status` (có thể tại route SSE riêng hoặc cùng channel).
- [ ] Tiếp nhận event workflow lifecycle từ backend (HTTP nội bộ/queue/pub-sub tùy kiến trúc hiện tại).
- [ ] Emit `workflow-status` event đến client SSE với schema đã chốt.
- [ ] Bổ sung metadata `run_id`, `workflow_id` vào `control-queue-status`.
- [ ] Không đưa node `start/end` vào queue command (giữ queue semantics chỉ cho action command).
- [ ] Đảm bảo backward compatibility nếu metadata mới chưa có (fallback `null`).

File tâm điểm:
- `server/services/controlQueueSseService.js`
- `server/services/controlQueueService.js`
- `server/bootstrap/sse.js`
- `server/bootstrap/mqtt.js`
- (nếu cần) route/controller mới cho backend push workflow event

Commit message gợi ý:
- `feat(sse): add workflow-status stream and attach run metadata to queue status`

Acceptance:
- Client SSE nhận được event lifecycle workflow độc lập với command queue.
- Event command có `run_id/workflow_id` để FE lọc đúng run.

## Commit 3 - Frontend: Merge 2 streams by run_id

- [ ] `ScenarioBuilderSection` theo dõi `run_id` hiện tại khi bấm Run.
- [ ] Lắng nghe cả 2 event:
- `workflow-status` để hiển thị mốc tổng quan (Started/Completed/Failed/Stopped)
- `control-queue-status` để hiển thị step command-level
- [ ] Filter event theo `run_id` (ưu tiên), fallback theo `workflow_id` khi cần.
- [ ] `WorkflowProgressPanel` cập nhật UI cho 2 tầng status (workflow-level + command-level).
- [ ] Xử lý state khi reconnect SSE: tránh duplicate step, đánh dấu disconnected/reconnected.

File tâm điểm:
- `frontend/app/components/devices-control/sections/ScenarioBuilderSection.vue`
- `frontend/app/components/devices-control/sections/WorkflowProgressPanel.vue`
- (nếu cần) composable workflow handling

Commit message gợi ý:
- `feat(frontend): render workflow lifecycle + command timeline by run_id`

Acceptance:
- 2 workflow chạy song song không trộn timeline.
- UI có mốc đầu/cuối workflow rõ ràng mà không cần giả lập start/end trong queue.

## Commit 4 - Tests + docs + rollout safety

- [ ] Unit test backend cho propagation `run_id/workflow_id`.
- [ ] Integration test server SSE events (`workflow-status`, `control-queue-status`).
- [ ] Frontend test mapping/filter theo `run_id`.
- [ ] E2E: run success, run fail, stop giữa chừng, 2 run song song.
- [ ] Cập nhật docs:
- `server/WORKFLOW_SSE_STREAM.md`
- `server/CONTROL_COMMAND_TIMESTAMPS.md`
- [ ] Feature flag (nếu cần) để bật dần trên staging -> production.

Commit message gợi ý:
- `test+docs(workflow): cover split status streams and update SSE/timestamp docs`

Acceptance:
- Không có regression với command trace drawer.
- Metrics latency vẫn đúng nghĩa command-level.

## Definition of Done

- [ ] Queue command chỉ chứa action command thật sự.
- [ ] UI hiển thị đủ status workflow lifecycle.
- [ ] Event được correlation theo `run_id` ổn định.
- [ ] Docs + test đồng bộ với implementation.
