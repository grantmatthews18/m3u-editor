<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! $this->migrator->exists('general.regex_sync_schedule')) {
            // no default; leave null until the user specifies a cron expression
            $this->migrator->add('general.regex_sync_schedule', null);
        }
    }
};
