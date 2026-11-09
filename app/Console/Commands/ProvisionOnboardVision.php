<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class ProvisionOnboardVision extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'vision:provision {device? : The onboard vision device ID} {--user= : Admin email to attach the token to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Provision a Sanctum bearer token for an ESP32-S3 onboard vision camera';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $device = (string) ($this->argument('device') ?: config('services.robot.onboard.device_id', 'CAM-S3-001'));

        if (preg_match('/^[A-Za-z0-9_-]{1,50}$/', $device) !== 1) {
            $this->error('Invalid device identifier. Use 1-50 alphanumeric, underscore, or hyphen characters.');

            return self::FAILURE;
        }

        $email = $this->option('user');
        $user = $email
            ? User::query()->where('email', $email)->first()
            : User::query()->where('is_admin', true)->orderBy('id')->first();

        if (! $user instanceof User) {
            $this->error('No administrator account found to attach the onboard vision token.');

            return self::FAILURE;
        }

        $token = $user->createToken('onboard-vision:'.$device, [
            'vision:ingest',
            'vision:device:'.$device,
        ]);

        $this->info('Provisioned onboard vision token for '.$device.':');
        $this->line($token->plainTextToken);

        return self::SUCCESS;
    }
}
