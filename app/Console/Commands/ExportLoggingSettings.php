<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\File;

class ExportLoggingSettings extends Command
{
    protected $signature = 'settings:export-logging-env';
    protected $description = 'Export logging settings to a shell script and restart services.';

    public function handle(GeneralSettings $settings)
    {
        $this->info('Exporting logging settings...');

        $env_vars = [
            'M3U_PROXY_LOG_ENABLED' => $settings->m3u_proxy_log_enabled ? 'true' : 'false',
            'M3U_PROXY_LOG_LEVEL' => $settings->log_level,
            'M3U_PROXY_LOG_TYPE' => $settings->log_type,
            'M3U_PROXY_LOG_PATH' => $settings->m3u_proxy_log_path,
            'LARAVEL_LOG_ENABLED' => $settings->laravel_log_enabled ? 'true' : 'false',
            'LARAVEL_LOG_LEVEL' => $settings->log_level,
            'LARAVEL_LOG_TYPE' => $settings->log_type,
            'LARAVEL_LOG_PATH' => $settings->laravel_log_path,
            'NGINX_LOG_ENABLED' => $settings->nginx_log_enabled ? 'true' : 'false',
            'NGINX_LOG_LEVEL' => $settings->log_level,
            'NGINX_LOG_PATH' => $settings->nginx_log_path,
            'POSTGRES_LOG_ENABLED' => $settings->postgres_log_enabled ? 'true' : 'false',
            'POSTGRES_LOG_LEVEL' => $settings->log_level,
            'POSTGRES_LOG_PATH' => $settings->postgres_log_path,
            'QUEUE_LOG_ENABLED' => $settings->queue_log_enabled ? 'true' : 'false',
            'QUEUE_LOG_LEVEL' => $settings->log_level,
            'QUEUE_LOG_PATH' => $settings->queue_log_path,
            'REDIS_LOG_ENABLED' => $settings->redis_log_enabled ? 'true' : 'false',
            'REDIS_LOG_LEVEL' => $settings->log_level,
            'REDIS_LOG_PATH' => $settings->redis_log_path,
            'WEBSOCKETS_LOG_ENABLED' => $settings->websockets_log_enabled ? 'true' : 'false',
            'WEBSOCKETS_LOG_LEVEL' => $settings->log_level,
            'WEBSOCKETS_LOG_PATH' => $settings->websockets_log_path,
        ];

        $script_content = '#!/usr/bin/env bash' . PHP_EOL;
        foreach ($env_vars as $key => $value) {
            $script_content .= "export {$key}=\"{$value}\"" . PHP_EOL;
        }

        $script_path = '/var/www/config/logging_env.sh';
        File::put($script_path, $script_content);

        $this->info('Logging settings exported successfully.');

        $this->info('Restarting services...');
        shell_exec('supervisorctl restart m3u-proxy');
        shell_exec('supervisorctl restart php-fpm');
        shell_exec('supervisorctl restart queue');
        shell_exec('supervisorctl restart websocket');
        shell_exec('supervisorctl restart nginx');
        shell_exec('supervisorctl restart postgres');
        shell_exec('supervisorctl restart redis');
        $this->info('Services restarted.');
    }
}
