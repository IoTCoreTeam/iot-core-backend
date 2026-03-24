# Báo cáo xử lý lỗi Workflow làm "đứng server" (bản cập nhật mới nhất)

## 1) Vấn đề ban đầu

Bạn gặp hiện tượng khi chạy workflow có action duration (ví dụ 10 giây), server có cảm giác bị đứng/chậm.

Nguyên nhân chính lúc đầu:
1. Luồng chạy workflow đi trực tiếp trong request HTTP.
2. Có `sleep(duration)` trong `WorkflowRunService`.
3. Lệnh control chờ ACK thiết bị (`wait_for_response=true`) nên request có thể giữ lâu.
4. Có network call nằm trong `DB::transaction` tại `ControlUrlService::execute()`.

## 2) Những gì đã triển khai

### 2.1 Chuyển run workflow sang queue + run state store

Đã làm:
1. Tạo store trung gian lưu trạng thái run và event: `WorkflowRunStateStore`.
2. `POST /workflows/{id}/run` tạo `run_id`, enqueue job và trả `202`.
3. Thêm API lấy event theo offset:
   - `GET /api/v1/workflows/runs/{run_id}/events?offset=n`
4. `RunWorkflowJob` ghi event/trạng thái vào store trong lúc worker chạy.

Kết quả:
1. Request web không còn giữ đến khi workflow kết thúc.
2. Frontend có thể theo dõi tiến trình từ run store.

### 2.2 Chuyển runStream sang mô hình queue/store (không block web worker)

Đã làm:
1. `runStream` không còn tự execute workflow trực tiếp trong request.
2. `runStream` delegate sang hành vi khởi tạo run queue (cùng logic với `run`).
3. Frontend `ScenarioBuilderSection.vue` được đổi:
   - Gọi `POST /workflows/{id}/run` để nhận `run_id`.
   - Poll `GET /workflows/runs/{run_id}/events?offset=...`.

Kết quả:
1. Không còn request SSE dài để giữ worker web.
2. Luồng theo dõi tiến trình dựa trên poll event từ store.

### 2.3 Bỏ sleep đồng bộ bằng delayed job cho lệnh OFF

Đã làm:
1. Tạo `ExecuteWorkflowOffCommandJob`.
2. Thay `sleep(duration)` bằng dispatch delayed job OFF:
   - `delay(now()->addSeconds(duration))`
3. `WorkflowRunService` ghi event `action_off_scheduled`.

Kết quả:
1. Worker không bị block bởi sleep.
2. Lệnh OFF được xử lý nền theo thời gian hẹn.

### 2.4 Tách network call khỏi DB transaction

Đã làm:
1. `ControlUrlService::execute()` bỏ `DB::transaction` bao quanh HTTP control call.
2. Vẫn trả kết quả execute như cũ, nhưng không giữ transaction khi chờ network.

Kết quả:
1. Giảm lock/thời gian transaction.
2. Giảm rủi ro "kéo dài transaction" khi gateway chậm/lỗi.

### 2.5 Điều chỉnh dev trên Windows

Đã làm:
1. Thêm `composer dev:win` để bỏ `php artisan pail` (vì Windows thiếu `pcntl`).
2. README cập nhật cách chạy trên Windows.

### 2.6 Sửa lỗi UI báo "end" quá sớm khi có delayed OFF

Vấn đề:
1. Sau khi chuyển sang delayed OFF, job chính có thể kết thúc sớm hơn thời điểm OFF thực thi.
2. UI nhận trạng thái completed sớm, dù thiết bị vẫn đang bật đủ thời gian duration.

Đã làm:
1. Thêm cơ chế `pending_off_jobs` trong `WorkflowRunStateStore`.
2. Khi schedule delayed OFF:
   - tăng `pending_off_jobs`.
3. Khi `ExecuteWorkflowOffCommandJob` chạy xong:
   - giảm `pending_off_jobs`.
4. `RunWorkflowJob` không còn gọi completed trực tiếp:
   - chuyển sang `markMainFinished`.
5. Chỉ khi:
   - main flow đã xong, và
   - `pending_off_jobs == 0`
   thì trạng thái run mới được set `completed`.
6. Nếu còn pending OFF:
   - trạng thái là `waiting_off_jobs`,
   - phát event `workflow_waiting_off_jobs`.
7. Frontend `useWorkflowSteps` được chỉnh:
   - khi nhận `workflow_end_reached` thì chưa finish ngay,
   - chờ `workflow-complete` mới đánh dấu kết thúc.

Kết quả:
1. UI không báo hoàn tất sớm khi OFF delayed chưa chạy xong.
2. Trạng thái "completed" phản ánh đúng "thiết bị đã chạy đủ duration và đã tắt theo lịch".

## 3) File đã thay đổi

Backend:
1. `backend/Modules/ControlModule/app/Services/WorkflowRunStateStore.php` (mới)
2. `backend/Modules/ControlModule/app/Jobs/RunWorkflowJob.php`
3. `backend/Modules/ControlModule/app/Jobs/ExecuteWorkflowOffCommandJob.php` (mới)
4. `backend/Modules/ControlModule/app/Http/Controllers/WorkflowController.php`
5. `backend/Modules/ControlModule/app/Services/WorkflowRunService.php`
6. `backend/Modules/ControlModule/app/Services/ControlUrlService.php`
7. `backend/Modules/ControlModule/routes/api.php`
8. `backend/composer.json`
9. `backend/README.md`

Frontend:
1. `frontend/app/components/devices-control/sections/ScenarioBuilderSection.vue`
2. `frontend/app/composables/Scenario/useWorkflowSteps.ts`

## 4) API flow hiện tại

1. Frontend gọi `POST /api/v1/workflows/{workflow}/run`.
2. Backend tạo `run_id`, enqueue `RunWorkflowJob`, trả `202`.
3. Frontend poll `GET /api/v1/workflows/runs/{run_id}/events?offset=n`.
4. Worker chạy main workflow, append event vào store.
5. Nếu có delayed OFF:
   - `pending_off_jobs` tăng và trạng thái có thể là `waiting_off_jobs`.
6. Các delayed OFF job chạy xong sẽ giảm `pending_off_jobs`.
7. Khi `pending_off_jobs` về `0` và main flow đã kết thúc:
   - run chuyển sang `completed`.
8. Frontend nhận trạng thái `completed` hoặc `failed` rồi kết thúc polling.

## 5) Vấn đề đã xác nhận từ log

Đã thấy các lỗi nghiệp vụ/kết nối từng xuất hiện:
1. `gateway not whitelisted: GW_001`
2. `Timed out waiting control status event (15000 ms)`

Những lỗi trên là lỗi nghiệp vụ/kết nối gateway, không phải lỗi kiến trúc queue.

## 6) Cách chạy sau cập nhật

Trong thư mục `backend`:
1. `composer install`
2. `npm install`
3. Chạy:
   - Windows: `composer dev:win`
   - Linux/macOS: `composer dev`

Nếu chạy thủ công:
1. Terminal 1: `php artisan serve`
2. Terminal 2: `php artisan queue:work`
3. (nếu cần frontend assets backend) Terminal 3: `npm run dev`

## 7) Ghi chú quan trọng

1. Nếu worker không chạy, run sẽ dừng ở `queued`.
2. Frontend đã dùng polling run events, không nên dựa vào stream cũ để execute workflow.
3. Để giảm thất bại nghiệp vụ cần xử lý tiếp:
   - whitelist gateway đúng (`GW_001`)
   - ổn định ACK từ node server/gateway
   - điều chỉnh timeout nếu cần.

