# Tài liệu hợp nhất Workflow (Backend)

Tài liệu này gộp nội dung của:
- `WORKFLOW_QUEUE_REPORT.vi.md`
- `WORKFLOW_FILES_OVERVIEW.vi.md`

Mục tiêu:
- Có một nguồn sự thật duy nhất cho luồng chạy workflow.
- Giảm trùng lặp và giảm rủi ro tài liệu bị lệch.
- Cập nhật theo hành vi code hiện tại.

## 1) Vấn đề gốc và hướng giải quyết

Vấn đề ban đầu:
- Luồng run workflow có thể gây cảm giác chậm/đứng khi có action duration lớn.
- Trước đây có `sleep(duration)` + chờ ACK thiết bị.
- Nếu chạy trực tiếp trong HTTP request thì web worker bị giữ lâu.

Hướng đã triển khai:
- Chuyển `POST /workflows/{id}/run` sang queue job.
- Dùng run-state store để theo dõi tiến trình.
- Frontend theo dõi bằng polling events thay vì stream execute trực tiếp.
- Tách network call execute control khỏi DB transaction.

## 2) Kiến trúc chạy workflow hiện tại

Tổng quan:
1. Client gọi `POST /api/v1/workflows/{workflow}/run`.
2. Controller enqueue `RunWorkflowJob`, trả về `202`.
3. Queue worker load workflow và gọi `WorkflowRunService::run(...)`.
4. Service:
   - Kiểm tra online của node bắt buộc.
   - Đưa các thiết bị trong workflow về OFF trước khi chạy.
   - Duyệt graph `start -> ... -> end`.
   - Xử lý node `action` hoặc `condition`.
5. Event run được append vào store để frontend poll.

Lợi ích:
- Request web không bị giữ đến lúc workflow kết thúc.
- Có log/event để quan sát từng bước.

## 3) API flow

Lượt gọi chính:
1. `POST /api/v1/workflows/{id}/run`:
   - enqueue run job
   - response: `202`, status `queued`
2. `GET /api/v1/workflows/runs/{run_id}/events?offset=n`:
   - trả event theo offset cho UI
3. `POST /api/v1/workflows/{id}/stop`:
   - force OFF các thiết bị trong workflow

Ghi chú:
- `run/stream` đang disabled trong controller.

## 4) Danh sách file và vai trò

API/Route:
- `backend/Modules/ControlModule/routes/api.php`:
  - định nghĩa routes workflows và `available-nodes`.
- `backend/Modules/ControlModule/app/Http/Controllers/WorkflowController.php`:
  - entrypoint run/stop workflow.

Jobs:
- `backend/Modules/ControlModule/app/Jobs/RunWorkflowJob.php`:
  - queue worker entry cho mỗi lần run workflow.

Core services:
- `backend/Modules/ControlModule/app/Services/WorkflowRunService.php`:
  - business logic run graph.
- `backend/Modules/ControlModule/app/Services/WorkflowRunStateStore.php`:
  - lưu trạng thái run + events để frontend poll.
- `backend/Modules/ControlModule/app/Services/WorkflowRunDataHelper.php`:
  - helper index node/edge, resolve next node, normalize input type.
- `backend/Modules/ControlModule/app/Services/WorkflowRunHttpHelper.php`:
  - helper cho các network call (device status, metrics, wait config).
- `backend/Modules/ControlModule/app/Services/ControlUrlService.php`:
  - execute control command qua Node server.
- `backend/Modules/ControlModule/app/Services/ControlCommandExecutionService.php`:
  - call HTTP tới Node server và assert control response.

## 5) Hành vi quan trọng trong WorkflowRunService

### 5.1 run(...)
- Ghi event `workflow_start`.
- Lấy definition (ưu tiên `control_definition`).
- Kiểm tra online của các node action.
- `ensureWorkflowDevicesOff(...)` trước khi execute graph.
- Gọi `executeFlow(...)`.
- Ghi event kết thúc hoặc lỗi.

### 5.2 executeFlow(...)
- Index nodes + edges.
- Tìm `start` và `end`.
- Traverse theo edge:
  - node `action` -> `runActionNode(...)`
  - node `condition` -> `evaluateConditionNode(...)` rồi rẽ nhánh true/false
- Dừng khi đến `end`.

### 5.3 runActionNode(...)
Digital:
- `action_value = on/off`:
  - gửi command theo state.
  - nếu `on` và `duration_seconds > 0`:
    - chờ duration (sync wait trong queue worker),
    - gửi `off`,
    - rồi mới qua node tiếp theo.

Default action:
- Nếu không có `action_value`:
  - gửi `on`,
  - nếu có duration thì chờ duration rồi gửi `off`,
  - nếu không có duration thì gửi `off` ngay.

Analog:
- Nếu `action_value` là số thì gửi giá trị analog.

Lưu ý quan trọng:
- Hiện tại duration được xử lý tuần tự trong workflow worker để giữ timeline node ổn định.
- Đổi lại, workflow có duration dài sẽ giữ worker lâu hơn.

### 5.4 evaluateConditionNode(...)
- Lấy metric mới nhất.
- So sánh theo operator (`>`, `<`, `>=`, `<=`, `==`, `!=`).
- Ghi event kết quả condition.

### 5.5 stop(...)
- Force OFF các thiết bị thuộc workflow.
- Trả kết quả `stopped` hoặc lỗi.

## 6) Event model và frontend

Backend:
- Append event vào `WorkflowRunStateStore`.
- Run state và event được frontend poll theo offset.

Frontend:
- Theo dõi events trong composable scenario steps.
- Trạng thái stream realtime direct execute đã tạm dừng; hiện tại ưu tiên run queue + polling.

## 7) Trade-off hiện tại

Trade-off 1:
- Ưu điểm: timeline node dễ hiểu hơn, không còn chồng chéo OFF delayed.
- Nhược điểm: worker bị giữ trong thời gian chờ duration.

Trade-off 2:
- Ưu điểm: web request không bị block bởi workflow dài.
- Nhược điểm: cần scale số lượng queue workers nếu tải cao.

## 8) Vận hành và checklist

Checklist để run ổn định:
1. `QUEUE_CONNECTION` không để `sync` trong môi trường production.
2. Có process queue worker đang chạy.
3. Theo dõi logs:
   - `workflow.run.*`
   - `control_url.execute_*`
4. Theo dõi event polling cho `run_id`.
5. Nếu workflow duration lớn:
   - cân nhắc tăng số worker,
   - cảnh báo throughput queue.

## 9) Các thay đổi quan trọng đã thực hiện

1. Queue hóa luồng run workflow.
2. Bỏ execute trực tiếp trong stream request.
3. Tách network call execute khỏi DB transaction.
4. Chuẩn hóa run-state/events cho frontend polling.
5. Đồng bộ hóa xử lý duration theo node để tránh nhảy timeline.

## 10) Tình trạng tài liệu

- Tài liệu này là bản hợp nhất và được xem là nguồn chính.
- Hai file cũ giữ lại để tương thích và chỉ trỏ đến file này.
