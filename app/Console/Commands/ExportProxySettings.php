<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Settings\GeneralSettings;

class ExportProxySettings extends Command
{
    protected $signature = 'settings:export-proxy-env';
    protected $description = 'Export proxy settings as environment variables.';

    public function handle(GeneralSettings $settings)
    {
        $this->output->writeln("export M3U_PROXY_LOG_ENABLED='" . ($settings->log_enabled ? 'true' : 'false') . "'");
        $this->output->writeln("export M3U_PROXY_LOG_LEVEL='" . $settings->log_level . "'");
        $this->output->writeln("export M3U_PROXY_LOG_TYPE='" . $settings->log_type . "'");

        if (!empty($settings->log_path)) {
            $this->output->writeln("export M3U_PROXY_LOG_PATH='" . $settings->log_path . "'");
        }
    }
}
