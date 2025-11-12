<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.log_enabled', false);
        $this->migrator->add('general.log_level', 'debug');
        $this->migrator->add('general.log_type', 'file');
        $this->migrator->add('general.log_path', null);
    }

    public function down(): void
    {
        $this->migrator->delete('general.log_enabled');
        $this->migrator->delete('general.log_level');
        $this->migrator->delete('general.log_type');
        $this->migrator->delete('general.log_path');
    }
};
