<?php

namespace App\Console\Commands;

use App\Models\ApprovalToken;
use Illuminate\Console\Command;

class ExpireApprovalTokens extends Command
{
    protected $signature = 'approval:expire-tokens';

    protected $description = 'Mark pending approval tokens that have passed their expiration time as expired';

    public function handle(): int
    {
        $count = ApprovalToken::markExpired();

        if ($count > 0) {
            $this->info("{$count} approval token(s) expired.");
        } else {
            $this->info('No approval tokens to expire.');
        }

        return self::SUCCESS;
    }
}