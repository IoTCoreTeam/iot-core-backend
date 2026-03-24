# Tài liệu kỹ thuật Workflow (Backend)

## 1) Mục tiêu tài liệu

Tài liệu này mô tả các file backend liên quan đến Workflow trong `ControlModule`, bao gồm:
1. Vai trò từng file.
2. Danh sách hàm chính trong mỗi file.
3. Mô tả chi tiết các hàm core điều khiển luồng chạy workflow.

Phạm vi: backend Laravel (`backend/Modules/ControlModule/...`).

---

## 2) Tổng quan kiến trúc chạy Workflow hiện tại

Luồng hiện tại là **queue-based**:
1. API `POST /api/v1/workflows/{workflow}/run` nhận request.
2. Controller đẩy `RunWorkflowJob` vào queue và trả `202` ngay.
3. Queue worker chạy job ở nền, gọi `WorkflowRunService->run(...)`.
4. Service duyệt flow (`start -> action/condition -> ... -> end`), gửi lệnh thiết bị qua `ControlUrlService`.
5. Nếu action có `duration_seconds > 0`, service tạo delayed job `ExecuteWorkflowOffCommandJob` để tắt thiết bị sau thời gian hẹn.

Ghi chú:
1. API stream trạng thái `runStream` hiện đang tắt tạm thời (trả `410`).
2. `WorkflowRunStateStore` vẫn tồn tại cho cơ chế run-state/event, nhưng luồng controller hiện tại đang queue không kèm `run_id`.

---

## 3) Danh sách file liên quan và vai trò

### 3.1 API/Controller

1. `backend/Modules/ControlModule/app/Http/Controllers/WorkflowController.php`
- Vai trò: entrypoint HTTP cho CRUD workflow, run/stop workflow.
- Hàm:
  1. `index(Request $request)`: danh sách workflow.
  2. `store(StoreWorkflowRequest $request)`: tạo workflow.
  3. `show(Workflow $workflow)`: xem chi tiết.
  4. `update(UpdateWorkflowRequest $request, Workflow $workflow)`: cập nhật.
  5. `destroy(Workflow $workflow)`: xóa.
  6. `run(Request $request, Workflow $workflow)`: enqueue job chạy workflow, trả `202`.
  7. `stop(Workflow $workflow)`: gọi service tắt thiết bị trong workflow.
  8. `runStream(Request $request, Workflow $workflow)`: tạm thời trả lỗi `410`.

2. `backend/Modules/ControlModule/routes/api.php`
- Vai trò: định nghĩa route `workflows`:
  1. `GET /workflows`
  2. `GET /workflows/{workflow}`
  3. `POST /workflows`
  4. `PUT /workflows/{workflow}`
  5. `POST /workflows/{workflow}/run`
  6. `POST /workflows/{workflow}/stop`
  7. `GET /workflows/{workflow}/run/stream` (đang disabled ở controller)
  8. `DELETE /workflows/{workflow}`

### 3.2 Jobs

1. `backend/Modules/ControlModule/app/Jobs/RunWorkflowJob.php`
- Vai trò: job chính chạy workflow ở queue.
- Hàm:
  1. `__construct(...)`: nhận `workflowId`, `actorId`, `runId?`.
  2. `handle(...)`: load workflow, gọi `WorkflowRunService->run(...)`, ghi lỗi/log trạng thái nếu cần.

2. `backend/Modules/ControlModule/app/Jobs/ExecuteWorkflowOffCommandJob.php`
- Vai trò: delayed job để gửi lệnh OFF sau `duration_seconds`.
- Hàm:
  1. `__construct(...)`: nhận `controlUrlId`, `actionType`, `normalizedType`, `runId?`.
  2. `handle(...)`: gửi lệnh OFF qua `ControlUrlService`, cập nhật state store nếu có `runId`.

### 3.3 Services (Core)

1. `backend/Modules/ControlModule/app/Services/WorkflowRunService.php`
- Vai trò: service nghiệp vụ trung tâm chạy workflow.
- Hàm core:
  1. `run(...)`
  2. `executeFlow(...)`
  3. `runActionNode(...)`
  4. `dispatchDelayedOffCommand(...)`
  5. `evaluateConditionNode(...)`
  6. `assertDevicesOnline(...)`
  7. `ensureWorkflowDevicesOff(...)`
  8. `assertActionDeviceOnline(...)`
  9. `stop(...)`
- Hàm hỗ trợ nội bộ:
  1. `collectRequiredNodes(...)`
  2. `resolveControlUrlInputType(...)`
  3. `abortWorkflowDevices(...)`
  4. `recordEvent(...)`

2. `backend/Modules/ControlModule/app/Services/WorkflowRunDataHelper.php`
- Vai trò: tách các hàm helper xử lý cấu trúc dữ liệu/graph.
- Hàm:
  1. `indexNodes(...)`
  2. `indexEdges(...)`
  3. `findNodeIdByType(...)`
  4. `resolveNextNodeId(...)`
  5. `indexOnlineNodes(...)`
  6. `collectActionControlUrls(...)`
  7. `mapMetricKey(...)`
  8. `normalizeControlInputType(...)`

3. `backend/Modules/ControlModule/app/Services/WorkflowRunHttpHelper.php`
- Vai trò: tách helper HTTP/payload cho workflow run.
- Hàm:
  1. `fetchDeviceStatus()`
  2. `fetchLatestMetricValue(...)`
  3. `withControlResponseWait(...)`
  4. `serviceAuthHeaders()` (private)

4. `backend/Modules/ControlModule/app/Services/WorkflowRunStateStore.php`
- Vai trò: lưu run-state và event (cache) theo `run_id`, quản lý `pending_off_jobs`.
- Hàm:
  1. `createRun(...)`
  2. `markRunning(...)`
  3. `incrementPendingOffJobs(...)`
  4. `completePendingOffJob(...)`
  5. `appendEvent(...)`
  6. `markMainFinished(...)`
  7. `markFailed(...)`
  8. `read(...)`
  9. Các hàm private quản lý state/key/event.

5. `backend/Modules/ControlModule/app/Services/WorkflowDefinitionService.php`
- Vai trò: lọc/chuẩn hóa `definition` trước khi lưu hoặc xử lý.
- Hàm:
  1. `filter(array $definition): array`

### 3.4 Model

1. `backend/Modules/ControlModule/app/Models/Workflow.php`
- Vai trò: model Eloquent cho bảng workflows (dữ liệu định nghĩa flow, metadata workflow).

---

## 4) Mô tả chi tiết các hàm core chạy workflow

## 4.1 `WorkflowController::run(...)`

Mục đích:
1. Không chạy workflow trực tiếp trong HTTP request.
2. Chỉ enqueue `RunWorkflowJob` rồi trả `202`.

Ý nghĩa:
1. Tránh giữ web worker lâu.
2. Đưa xử lý nặng sang queue worker.

## 4.2 `RunWorkflowJob::handle(...)`

Mục đích:
1. Là entrypoint của queue worker cho 1 lần chạy workflow.

Luồng:
1. Nếu có `runId` thì đánh dấu running và gắn callback append event.
2. Load workflow theo `workflowId`.
3. Gọi `WorkflowRunService->run($workflow, $actor)`.
4. Nếu có lỗi, log lỗi và mark failed (nếu có runId).
5. Cleanup callback/run context ở `finally`.

## 4.3 `WorkflowRunService::run(...)`

Mục đích:
1. Điều phối toàn bộ lifecycle chạy workflow.

Luồng:
1. Ghi event `workflow_start`.
2. Lấy definition (`control_definition` hoặc `definition`), validate không rỗng.
3. Lấy device status từ node server.
4. `assertDevicesOnline(...)` để check node cần dùng đều online.
5. `ensureWorkflowDevicesOff(...)` để đưa thiết bị về trạng thái OFF ban đầu.
6. `executeFlow(...)` để duyệt graph và thực thi node.
7. Nếu thành công: ghi log/notification completed.
8. Nếu lỗi: ghi log/notification failed, gọi `abortWorkflowDevices(...)`, ném lỗi lên cho job xử lý.

## 4.4 `WorkflowRunService::executeFlow(...)`

Mục đích:
1. Duyệt đồ thị workflow theo node/edge.

Luồng:
1. Dùng helper để index node/edge.
2. Tìm `start` và `end`.
3. Loop từ node hiện tại:
   1. `action` -> `runActionNode(...)`.
   2. `condition` -> `evaluateConditionNode(...)`, chọn nhánh `true/false`.
   3. node khác -> đi cạnh mặc định.
4. Khi chạm `end` thì dừng.
5. Có guard `maxSteps` để tránh loop vô hạn.

## 4.5 `WorkflowRunService::runActionNode(...)`

Mục đích:
1. Thực thi node action theo loại digital/analog và duration.

Luồng:
1. Validate `control_url_id`.
2. Resolve `actionType`, `normalizedType`, `duration`, `action_value`.
3. `assertActionDeviceOnline(...)` trước khi bắn lệnh.
4. Nhánh analog:
   1. Yêu cầu `action_value` là số.
   2. Gửi lệnh với `value`.
5. Nhánh digital:
   1. Yêu cầu `action_value` là `on/off`.
   2. Gửi lệnh với `state`.
   3. Nếu `state=on` và `duration>0` -> gọi `dispatchDelayedOffCommand(...)`.
6. Nhánh mặc định:
   1. Bật `on`.
   2. Nếu `duration>0` thì schedule delayed off.
   3. Nếu không duration thì tắt `off` ngay.

Điểm quan trọng:
1. Không dùng `sleep(duration)` để chờ đồng bộ.
2. Delay được xử lý bằng queue job riêng.

## 4.6 `WorkflowRunService::dispatchDelayedOffCommand(...)`

Mục đích:
1. Lên lịch tắt thiết bị sau `duration`.

Luồng:
1. Ghi event `action_off_scheduled`.
2. Nếu có `currentRunId`, tăng `pending_off_jobs` trong state store.
3. `dispatch(ExecuteWorkflowOffCommandJob)->delay(now()->addSeconds(duration))`.

Ý nghĩa:
1. Chờ thời gian bằng lịch queue, không chặn tiến trình hiện tại.

## 4.7 `ExecuteWorkflowOffCommandJob::handle(...)`

Mục đích:
1. Thực thi lệnh OFF tại thời điểm đã delay.

Luồng:
1. Build payload OFF (`state=off` cho digital, `value=0` cho analog).
2. Gọi `ControlUrlService->execute(...)`.
3. Cập nhật state store `completePendingOffJob(...)` nếu có `runId`.
4. Ghi system log executed/failed.

## 4.8 `WorkflowRunService::evaluateConditionNode(...)`

Mục đích:
1. Đánh giá node điều kiện để chọn nhánh.

Luồng:
1. Lấy `metric_key`, `operator`, `value`.
2. Gọi helper HTTP lấy metric mới nhất từ node server.
3. So sánh theo toán tử (`>`, `<`, `>=`, `<=`, `==`, `!=`).
4. Ghi event `condition_evaluated`.

## 4.9 `WorkflowRunService::assertDevicesOnline(...)` và `assertActionDeviceOnline(...)`

Mục đích:
1. Chặn chạy workflow nếu thiết bị mục tiêu không online.

Luồng:
1. Mapping thiết bị cần dùng từ action nodes.
2. Đối chiếu với dữ liệu online lấy từ node server.
3. Nếu thiếu/offline -> throw exception để fail sớm.

## 4.10 `WorkflowRunService::ensureWorkflowDevicesOff(...)`

Mục đích:
1. Đưa tất cả thiết bị action trong workflow về OFF trước khi chạy.

Ý nghĩa:
1. Tránh trạng thái “rác” từ lần chạy trước ảnh hưởng kết quả.

## 4.11 `WorkflowRunService::stop(...)`

Mục đích:
1. API stop gọi vào đây để tắt thiết bị thuộc workflow.

Luồng:
1. Validate definition.
2. Gọi `ensureWorkflowDevicesOff(...)`.
3. Trả trạng thái `stopped` hoặc throw nếu lỗi.

---

## 5) Nhóm helper đã tách khỏi `WorkflowRunService`

Mục tiêu refactor:
1. Giữ `WorkflowRunService` làm nơi điều phối nghiệp vụ.
2. Đưa các hàm hỗ trợ kỹ thuật sang helper để file dễ đọc hơn.

Đã tách:
1. Data/graph helper -> `WorkflowRunDataHelper`.
2. HTTP/payload helper -> `WorkflowRunHttpHelper`.

Chưa tách (cố ý giữ trong service):
1. Logic nghiệp vụ chạy workflow (`run`, `executeFlow`, `runActionNode`, `evaluateConditionNode`, `stop`...).

---

## 6) Tình trạng stream trạng thái

Hiện tại:
1. `runStream` đã disable tạm thời và trả `410`.
2. Luồng chạy workflow vẫn hoạt động qua queue.

Hệ quả:
1. Trạng thái realtime qua stream không còn dùng ở thời điểm này.
2. Hệ thống tập trung ổn định phần execute workflow trước.

