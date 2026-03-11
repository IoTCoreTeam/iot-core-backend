# IoT Core Backend

This repository serves as the central backend service for the IoT-core platform, handling authentication, user management, and IoT device control through a modular architecture built on the Laravel framework.

## Core Features

The backend provides centralized authentication with Laravel Passport, including login, token refresh, logout, registration, and password changes for web and service clients while managing personal access and password grant clients. It delivers user and company account manage features with role-aware access, filtering, and updates, plus admin-only visibility into system logs and metrics such as weekly log counts for audit and monitoring workflows. The Control Module exposes versioned endpoints to manage gateways, nodes, and control URLs, including registration, deactivation, execution, and deletion actions, alongside a public endpoint for listing available active nodes. The Map Module provides versioned endpoints to manage areas and maps used by location features.

---

## Auth Flow (Frontend ↔ Backend)

This project uses Laravel Passport access tokens (Bearer) plus an HTTP-only refresh token cookie. The frontend never reads the refresh token directly; it relies on `credentials: "include"` so the browser attaches the cookie.

```mermaid
sequenceDiagram
    autonumber
    participant FE as Frontend (Nuxt)
    participant BE as Backend (Laravel)
    participant DB as DB (Passport)

    FE->>BE: POST /api/login (email, password)
    BE->>DB: Verify user + create access token + refresh token
    BE-->>FE: 200 {access_token, token_type, expires_at, user}
    BE-->>FE: Set-Cookie iot_core_refresh_token (HttpOnly)
    FE->>BE: Authenticated API calls\nAuthorization: Bearer <access_token>
    BE->>DB: Validate access token + scopes/roles
    BE-->>FE: Protected response

    FE->>BE: POST /api/refresh-token (credentials: include)
    BE->>DB: Validate refresh token + revoke old access token
    BE-->>FE: 200 {new access_token, token_type, expires_at, user}
    BE-->>FE: Set-Cookie iot_core_refresh_token (rotated)

    FE->>BE: POST /api/logout (credentials: include)
    BE->>DB: Revoke access token + refresh token
    BE-->>FE: Set-Cookie iot_core_refresh_token (expired)
```

### Key endpoints used by the frontend
1. `POST /api/login` issues an access token and sets the refresh cookie.
2. `POST /api/refresh-token` rotates tokens using the refresh cookie.
3. `POST /api/logout` revokes tokens and expires the refresh cookie.

### Implementation references
1. Backend auth controller and refresh cookie handling: `backend/app/Http/Controllers/Api/AuthController.php`
2. Access/refresh token issuance and revocation: `backend/app/Services/AuthService.php`
3. Passport token TTL configuration: `backend/app/Providers/AppServiceProvider.php`
4. Frontend login + token storage: `frontend/app/pages/login.vue`, `frontend/stores/auth.ts`
5. Frontend refresh middleware: `frontend/app/middleware/auth.global.ts`

---

## Technical Setup

### 1. Prerequisites
- PHP 8.2 or higher
- Composer
- Node.js 18 or higher (for asset compilation)
- MySQL or a compatible relational database

### 2. Installation Steps

1. **Install Dependencies**
   ```bash
   composer install
   npm install
   ```

2. **Environment Configuration**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   *Configure your database connection details in the newly created `.env` file.*

3. **Database Initialization**
   ```bash
   php artisan migrate --seed
   ```

4. **Passport Initialization**
   ```bash
   php artisan install:api --passport
   php artisan passport:keys --force
   ```
   *This generates the RSA keys required for secure token signing in the `storage/` directory.*

5. **Personal Access Client Setup**
   ```bash
   php artisan passport:client --personal
   ```

### 3. Running the Application

- **Development Server:**
  ```bash
  php artisan serve
  ```

- **Full Stack Concurrency (Server, Queue, Vite):**
  ```bash
  composer dev
  ```

---

## Utility Commands

- `composer setup`: Orchestrates dependency installation, environment setup, migrations, and builds.
- `php artisan test`: Executes the automated test suite.
- `php artisan storage:link`: Establishes the symbolic link for public file storage.

For detailed information on the setup process, refer to `docs/Setup_tutorial.txt` or contact the development team.
