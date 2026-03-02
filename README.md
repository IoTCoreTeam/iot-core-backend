# IoT Core Backend

This repository serves as the central backend service for the IoT-core platform, handling authentication, user management, and IoT device control through a modular architecture built on the Laravel framework.

## Core Features

The backend provides centralized authentication with Laravel Passport, including login, token refresh, logout, registration, and password changes for web and service clients while managing personal access and password grant clients. It delivers user and company account manage features with role-aware access, filtering, and updates, plus admin-only visibility into system logs and metrics such as weekly log counts for audit and monitoring workflows. The Control Module exposes versioned endpoints to manage gateways, nodes, and control URLs, including registration, deactivation, execution, and deletion actions, alongside a public endpoint for listing available active nodes. The Map Module provides versioned endpoints to manage areas and maps used by location features.

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
