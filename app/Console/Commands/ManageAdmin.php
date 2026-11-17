<?php

namespace App\Console\Commands;

use App\Repositories\UserRepository;
use Illuminate\Console\Command;

class ManageAdmin extends Command
{
    protected $signature = 'pixelforge:admin {email : Existing account email} {--revoke : Remove admin access}';

    protected $description = 'Grant or revoke report access for an explicitly selected account';

    public function handle(UserRepository $userRepository): int
    {
        $user = $userRepository->findByEmail($this->argument('email'));
        if ($user === null) {
            $this->error('No account exists with that email. Register it first.');

            return self::FAILURE;
        }
        $userRepository->setAdmin($user, ! $this->option('revoke'));
        $this->info($this->option('revoke') ? 'Admin access revoked.' : 'Admin access granted.');

        return self::SUCCESS;
    }
}
