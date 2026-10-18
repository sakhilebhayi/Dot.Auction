<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Platform-operator status is never grantable through the web app or mass assignment; it is set
 * here, by someone with shell access to the deployment. Mirrors `analytics:operator`.
 *
 * `--import-env` is the one-time bridge from the retired AUCTION_OPERATOR_EMAILS list.
 */
class GrantPlatformOperator extends Command
{
    protected $signature = 'auction:operator
                                {email? : Email of the user}
                                {--revoke : Remove operator status instead of granting it}
                                {--import-env : One time: grant the flag to the verified users listed in the deprecated AUCTION_OPERATOR_EMAILS}';

    protected $description = 'Grant or revoke platform-operator status (payment operations pages) for a user';

    public function handle(): int
    {
        $email = $this->argument('email');

        if ($this->option('import-env')) {
            if ($email !== null || $this->option('revoke')) {
                $this->error('--import-env stands alone: it takes no email and cannot be combined with --revoke.');

                return self::FAILURE;
            }

            return $this->importFromEnv();
        }

        if ($email === null || trim((string) $email) === '') {
            $this->error('Give the email of the user, or use --import-env.');

            return self::FAILURE;
        }

        $user = $this->findUser((string) $email);

        if (! $user) {
            $this->error('No user with the email '.trim((string) $email).'.');

            return self::FAILURE;
        }

        return $this->option('revoke') ? $this->revoke($user) : $this->grant($user);
    }

    private function grant(User $user): int
    {
        if ($user->email_verified_at === null) {
            $this->error("The account {$user->email} is not verified, so it cannot be made an operator.");

            return self::FAILURE;
        }

        if ($user->isPlatformOperator()) {
            $this->info("{$user->email} is already a platform operator. Nothing changed.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_platform_operator' => true])->save();
        Log::notice('Platform-operator status granted.', ['user_id' => $user->id, 'via' => 'auction:operator']);
        $this->info("Granted platform-operator status for {$user->email}.");

        return self::SUCCESS;
    }

    private function revoke(User $user): int
    {
        if (! $user->isPlatformOperator()) {
            $this->info("{$user->email} is not an operator. Nothing changed.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_platform_operator' => false])->save();
        Log::notice('Platform-operator status revoked.', ['user_id' => $user->id, 'via' => 'auction:operator']);
        $this->info("Revoked platform-operator status for {$user->email}.");

        return self::SUCCESS;
    }

    private function importFromEnv(): int
    {
        $emails = array_values(array_unique(array_filter(array_map(
            fn ($e) => strtolower(trim((string) $e)),
            (array) config('auction.operator_emails', [])
        ))));

        if ($emails === []) {
            $this->info('AUCTION_OPERATOR_EMAILS is empty. Nothing to import.');

            return self::SUCCESS;
        }

        $granted = 0;
        $skipped = [];

        foreach ($emails as $email) {
            $user = $this->findUser($email);

            if (! $user) {
                $skipped[] = "{$email} (no such user)";

                continue;
            }

            if ($user->email_verified_at === null) {
                $skipped[] = "{$email} (not verified)";

                continue;
            }

            if (! $user->isPlatformOperator()) {
                $user->forceFill(['is_platform_operator' => true])->save();
                Log::notice('Platform-operator status granted.', ['user_id' => $user->id, 'via' => 'auction:operator --import-env']);
            }

            $granted++;
        }

        $this->info('Granted platform-operator status to '.$granted.' of '.count($emails).' listed email(s).');

        foreach ($skipped as $line) {
            $this->warn("Skipped: {$line}");
        }

        $this->line('AUCTION_OPERATOR_EMAILS is deprecated and no longer authorises anyone. Remove it from the environment.');

        return self::SUCCESS;
    }

    private function findUser(string $email): ?User
    {
        return User::query()->whereRaw('lower(email) = ?', [strtolower(trim($email))])->first();
    }
}
