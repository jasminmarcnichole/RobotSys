# RobotSys

RobotSys is a Laravel 12 dashboard and API for an autonomous trash collecting robot. It monitors telemetry, stores laptop-camera detections, tracks waste sorting counts, and queues robot commands.

## Stack

- PHP 8.2
- Laravel 12
- Laravel Sanctum for robot API tokens
- PHPUnit 11
- Vite and Tailwind CSS 4
- MySQL locally by default, SQLite in tests

## Setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
npm run build
```

For local development:

```bash
composer run dev
```

The seeded local admin account is:

- Email: `admin@example.com`
- Password: `password`

Change this before using a shared or production environment.

## Dashboard

The dashboard is protected by Laravel session auth and the `access-admin` gate. Available pages:

- `/dashboard`
- `/dashboard/camera`
- `/dashboard/capabilities`
- `/dashboard/waste-sorting`
- `/dashboard/commands`
- `/dashboard/evaluation`
- `/dashboard/logs`

The dashboard uses Vite-built assets from `resources/css/app.css` and `resources/js/robot-dashboard.js`.

## Robot API Authentication

Robot API routes require a Sanctum bearer token with the correct ability.

Create a local device token:

```bash
php artisan tinker
```

```php
$user = App\Models\User::where('email', 'admin@example.com')->first();

$token = $user->createToken('robot-device', [
    'robot:log',
    'robot:read',
    'robot:command:read',
    'robot:command:ack',
])->plainTextToken;
```

Use the token as:

```text
Authorization: Bearer <token>
Accept: application/json
```

## API Endpoints

`POST /api/robot/log`

Required ability: `robot:log`

Stores telemetry and optional camera snapshot data.

```json
{
  "robot_id": "ROBOT-001",
  "battery_level": 88,
  "movement_status": "Moving",
  "detected_trash_count": 4,
  "successful_pickup_count": 3,
  "failed_pickup_count": 1,
  "plastic_waste_count": 2,
  "general_waste_count": 2,
  "plastic_bin_level": "Low",
  "general_bin_level": "Medium"
}
```

`GET /api/robot/latest?robot_id=ROBOT-001`

Required ability: `robot:read`

Returns the latest telemetry log, optionally scoped to one robot.

`GET /api/robot/command/pending?robot_id=ROBOT-001`

Required ability: `robot:command:read`

Returns the latest pending command for the robot.

`POST /api/robot/command/{robotCommand}/acknowledge`

Required ability: `robot:command:ack`

Transitions a pending command to `received`.

`POST /api/robot/command/{robotCommand}/status`

Required ability: `robot:command:ack`

Updates command status to `received`, `completed`, or `failed`.

## Quality Checks

```bash
vendor/bin/pint --dirty
php artisan test --compact
npm run build
```

On Windows PowerShell, use `npm.cmd run build` if script execution policy blocks `npm`.
