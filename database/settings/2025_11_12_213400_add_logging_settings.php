<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('general.laravel_log_path', '/var/www/config/logs/laravel.log');
        $this->migrator->add('general.m3u_proxy_log_path', '/var/www/config/logs/m3u-proxy.log');
        $this->migrator->add('general.nginx_log_path', '/var/www/config/logs/nginx.log');
        $this->migrator->add('general.postgres_log_path', '/var/www/config/logs/postgres.log');
        $this->migrator->add('general.queue_log_path', '/var/www/config/logs/queue.log');
        $this->migrator->add('general.redis_log_path', '/var/www/config/logs/redis.log');
        $this->migrator->add('general.websockets_log_path', '/var/www/config/logs/websockets.log');
        $this->migrator->add('general.laravel_log_enabled', false);
        $this->migrator->add('general.m3u_proxy_log_enabled', false);
        $this->migrator->add('general.nginx_log_enabled', false);
        $this->migrator->add('general.postgres_log_enabled', false);
        $this->migrator->add('general.queue_log_enabled', false);
        $this->migrator->add('general.redis_log_enabled', false);
        $this->migrator->add('general.websockets_log_enabled', false);
    }
};
