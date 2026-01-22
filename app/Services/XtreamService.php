<?php

namespace App\Services;

use App\Models\Playlist;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service to interact with Xtream Codes API for IPTV services.
 */
class XtreamService
{
    protected string $server;

    protected string $user;

    protected string $pass;

    protected int $retryLimit;

    protected ?Playlist $playlist;

    protected ?array $xtream_config;

    /**
     * Factory method to create an instance of XtreamService.
     *
     * @param  int  $retryLimit  Number of retries for HTTP requests
     */
    public static function make(
        ?Playlist $playlist = null,
        ?array $xtream_config = null,
        $retryLimit = 5
    ): self {
        $instance = new self;

        return $instance->init($playlist, $xtream_config, $retryLimit);
    }

    /**
     * Initialize the XtreamService with a Playlist or Xtream config.
     *
     * @param  int  $retryLimit  Number of retries for HTTP requests
     * @return bool|self Returns false if initialization fails, otherwise returns the instance.
     */
    public function init(
        ?Playlist $playlist = null,
        ?array $xtream_config = null,
        $retryLimit = 5
    ): bool|self {
        // If Playlist, and not an xtream playlist, return false
        if ($playlist && ! $playlist->xtream) {
            return false;
        }

        // Set Playlist and Xtream config
        $this->playlist = $playlist;
        $this->xtream_config = $xtream_config;

        // Setup server, user, and pass
        if ($playlist) {
            $config = $playlist->xtream_config;
            $this->server = $config['url'] ?? '';
            $this->user = $config['username'] ?? '';
            $this->pass = $config['password'] ?? '';
        } elseif ($xtream_config) {
            $this->server = $xtream_config['url'] ?? '';
            $this->user = $xtream_config['username'] ?? '';
            $this->pass = $xtream_config['password'] ?? '';
        } else {
            return false;
        }

        $this->retryLimit = $retryLimit;

        return $this;
    }

    protected function call(string $url, int $timeout = 60 * 15)
    {
        if (! ($this->playlist || $this->xtream_config)) {
            throw new Exception('Config not initialized. Call init() first with Playlist or Xtream config array.');
        }
        $attempts = 0;
        $lastException = null;
        $response = null;

        do {
            try {
                $user_agent = $this->playlist?->user_agent ?? 'VLC/3.0.21 LibVLC/3.0.21';
                $verify = ! ($this->playlist?->disable_ssl_verification ?? false);
                $response = Http::timeout($timeout) // defaults to 15 minutes
                    ->withOptions(['verify' => $verify])
                    ->withHeaders(['User-Agent' => $user_agent])
                    ->get($url);

                if ($response->ok()) {
                    return $response->json();
                }

                // Non-OK response - increment attempts and retry
                $attempts++;
                Log::debug("XtreamService request failed with status {$response->status()}, attempt {$attempts}/{$this->retryLimit}");
            } catch (\Illuminate\Http\Client\ConnectionException $e) {
                $lastException = $e;
                $attempts++;
                $errorMessage = $e->getMessage();

                // Log the connection error
                Log::warning("XtreamService connection error on attempt {$attempts}/{$this->retryLimit}: {$errorMessage}");

                // Check if this is a retryable error
                $isRetryable = str_contains($errorMessage, 'Connection reset by peer')
                    || str_contains($errorMessage, 'Connection refused')
                    || str_contains($errorMessage, 'Operation timed out')
                    || str_contains($errorMessage, 'cURL error 56')
                    || str_contains($errorMessage, 'cURL error 28')
                    || str_contains($errorMessage, 'cURL error 7');

                if (! $isRetryable) {
                    // Non-retryable connection error - throw immediately
                    throw $e;
                }
            }

            // Wait with exponential backoff before retrying
            if ($attempts < $this->retryLimit) {
                $waitSeconds = min(pow(2, $attempts), 10); // 2s, 4s, 8s, max 10s
                sleep($waitSeconds);
            }
        } while ($attempts < $this->retryLimit);

        // All retries exhausted
        if ($lastException) {
            Log::error("XtreamService: All {$this->retryLimit} retries exhausted due to connection errors", [
                'url' => preg_replace('/password=[^&]+/', 'password=***', $url),
                'last_error' => $lastException->getMessage(),
            ]);
            throw $lastException;
        }

        // If we got here with a response, throw based on the last response
        if ($response) {
            $response->throw();
        }

        throw new Exception('XtreamService: Request failed after all retries');
    }

    protected function makeUrl(string $action, array $extra = []): string
    {
        $params = array_merge([
            'username' => $this->user,
            'password' => $this->pass,
            'action' => $action,
        ], $extra);

        if (! Str::startsWith($this->server, 'http://') && ! Str::startsWith($this->server, 'https://')) {
            $this->server = 'http://'.$this->server; // ensure server URL starts with http:// or https://
        }

        return $this->server
            .'/player_api.php?'.http_build_query($params);
    }

    public function authenticate(): array
    {
        $url = $this->server
            ."/player_api.php?username={$this->user}&password={$this->pass}";

        return $this->call(url: $url, timeout: 5)['user_info'] ?? []; // set short timeout
    }

    public function userInfo($timeout = 5): array
    {
        $url = $this->server
            ."/player_api.php?username={$this->user}&password={$this->pass}";

        return $this->call(url: $url, timeout: $timeout) ?? []; // set short timeout
    }

    public function getLiveCategories(): array
    {
        return $this->call($this->makeUrl('get_live_categories')) ?? [];
    }

    public function getLiveStreams(string $catId): array
    {
        return $this->call($this->makeUrl('get_live_streams', ['category_id' => $catId])) ?? [];
    }

    public function getVodCategories(): array
    {
        return $this->call($this->makeUrl('get_vod_categories')) ?? [];
    }

    public function getVodStreams(string $catId): array
    {
        return $this->call($this->makeUrl('get_vod_streams', ['category_id' => $catId])) ?? [];
    }

    public function getSeriesCategories(): array
    {
        return $this->call($this->makeUrl('get_series_categories')) ?? [];
    }

    public function getSeries(string $catId): array
    {
        return $this->call($this->makeUrl('get_series', ['category_id' => $catId])) ?? [];
    }

    public function getVodInfo(string $vodId): array
    {
        return $this->call($this->makeUrl('get_vod_info', ['vod_id' => $vodId])) ?? [];
    }

    public function getSeriesInfo(string $seriesId): array
    {
        return $this->call($this->makeUrl('get_series_info', ['series_id' => $seriesId])) ?? [];
    }

    public function buildMovieUrl(string $id, ?string $ext): string
    {
        $ext = $ext ? ".{$ext}" : '';

        return "{$this->server}/movie/{$this->user}/{$this->pass}/{$id}{$ext}";
    }

    public function buildSeriesUrl(string $id, ?string $ext): string
    {
        $ext = $ext ? ".{$ext}" : '';

        return "{$this->server}/series/{$this->user}/{$this->pass}/{$id}{$ext}";
    }
}
