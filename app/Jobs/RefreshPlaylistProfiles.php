<?php

namespace App\Jobs;

use App\Models\Playlist;
use App\Models\PlaylistProfile;
use App\Services\ProfileService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RefreshPlaylistProfiles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * Cache key for tracking provider failures per playlist.
     */
    protected const FAILURE_CACHE_PREFIX = 'profile_refresh_failures:';

    /**
     * Cache key for cooldown state.
     */
    protected const COOLDOWN_CACHE_PREFIX = 'profile_refresh_cooldown:';

    /**
     * Number of consecutive failures before entering cooldown.
     */
    protected const FAILURE_THRESHOLD = 3;

    /**
     * Cooldown duration in seconds (30 minutes).
     */
    protected const COOLDOWN_DURATION = 1800;

    /**
     * Base delay between profile refresh requests in milliseconds.
     */
    protected const BASE_DELAY_MS = 1000;

    /**
     * Maximum delay between profile refresh requests in milliseconds (10 seconds).
     */
    protected const MAX_DELAY_MS = 10000;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ?int $playlistId = null,
        public ?int $profileId = null
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Refresh a specific profile
        if ($this->profileId) {
            $profile = PlaylistProfile::find($this->profileId);
            if ($profile) {
                $this->refreshProfile($profile);
            }

            return;
        }

        // Refresh all profiles for a specific playlist
        if ($this->playlistId) {
            $playlist = Playlist::find($this->playlistId);
            if ($playlist && $playlist->profiles_enabled) {
                // Skip if playlist is currently processing/syncing to avoid concurrent API calls
                // that could trigger provider rate limiting ("peer rejected" errors)
                if ($playlist->isProcessing()) {
                    Log::debug("Skipping profile refresh for playlist {$playlist->id} - currently processing");

                    return;
                }

                $this->refreshPlaylistProfiles($playlist);
            }

            return;
        }

        // Refresh all profiles for all playlists with profiles enabled
        $this->refreshAllProfiles();
    }

    /**
     * Refresh all profiles across all playlists.
     */
    protected function refreshAllProfiles(): void
    {
        $playlists = Playlist::where('profiles_enabled', true)->get();

        if ($playlists->isEmpty()) {
            // If no playlists with profiles enabled, nothing to do
            return;
        }

        Log::info('Starting refresh of all playlist profiles', [
            'playlist_count' => $playlists->count(),
        ]);

        foreach ($playlists as $playlist) {
            // Skip playlists that are in cooldown due to provider rate limiting
            if ($this->isInCooldown($playlist->id)) {
                Log::debug("Skipping profile refresh for playlist {$playlist->id} - in cooldown due to rate limiting", [
                    'playlist_name' => $playlist->name,
                    'cooldown_expires' => $this->getCooldownExpiry($playlist->id),
                ]);

                continue;
            }

            // Skip if playlist is currently processing
            if ($playlist->isProcessing()) {
                Log::debug("Skipping profile refresh for playlist {$playlist->id} - currently processing");

                continue;
            }

            $this->refreshPlaylistProfiles($playlist);
        }

        Log::info('Completed refresh of all playlist profiles');
    }

    /**
     * Refresh all profiles for a playlist.
     */
    protected function refreshPlaylistProfiles(Playlist $playlist): void
    {
        $profiles = $playlist->profiles()->get();

        Log::info("Refreshing profiles for playlist {$playlist->id}", [
            'playlist_name' => $playlist->name,
            'profile_count' => $profiles->count(),
        ]);

        $failures = 0;
        $successes = 0;

        foreach ($profiles as $profile) {
            $success = $this->refreshProfile($profile);

            if ($success) {
                $successes++;
            } else {
                $failures++;
            }

            // Use adaptive delay based on failure count
            $delay = $this->calculateDelay($failures, $profiles->count());
            usleep($delay * 1000); // Convert ms to microseconds
        }

        // Track failures for cooldown logic
        $this->trackFailures($playlist->id, $failures, $profiles->count());

        Log::info("Profile refresh completed for playlist {$playlist->id}", [
            'playlist_name' => $playlist->name,
            'successes' => $successes,
            'failures' => $failures,
        ]);
    }

    /**
     * Calculate delay between requests based on failure count.
     * Uses exponential backoff when failures occur.
     */
    protected function calculateDelay(int $failures, int $totalProfiles): int
    {
        if ($failures === 0) {
            return self::BASE_DELAY_MS;
        }

        // Exponential backoff: delay doubles for each failure
        $delay = self::BASE_DELAY_MS * pow(2, min($failures, 4));

        return min($delay, self::MAX_DELAY_MS);
    }

    /**
     * Track failures and enter cooldown if threshold exceeded.
     */
    protected function trackFailures(int $playlistId, int $failures, int $totalProfiles): void
    {
        $failureKey = self::FAILURE_CACHE_PREFIX.$playlistId;

        // If all profiles failed, this is likely a rate limiting issue
        if ($failures > 0 && $failures >= $totalProfiles) {
            $consecutiveFailures = Cache::increment($failureKey);
            Cache::put($failureKey, $consecutiveFailures, 3600); // Keep for 1 hour

            Log::warning("All profile refreshes failed for playlist {$playlistId}", [
                'consecutive_failures' => $consecutiveFailures,
                'threshold' => self::FAILURE_THRESHOLD,
            ]);

            // Enter cooldown if we've hit the threshold
            if ($consecutiveFailures >= self::FAILURE_THRESHOLD) {
                $this->enterCooldown($playlistId);
            }
        } elseif ($failures === 0) {
            // Reset failure count on full success
            Cache::forget($failureKey);
        }
    }

    /**
     * Enter cooldown mode for a playlist.
     */
    protected function enterCooldown(int $playlistId): void
    {
        $cooldownKey = self::COOLDOWN_CACHE_PREFIX.$playlistId;
        $expiresAt = now()->addSeconds(self::COOLDOWN_DURATION);

        Cache::put($cooldownKey, $expiresAt->timestamp, self::COOLDOWN_DURATION);

        Log::warning("Playlist {$playlistId} entered profile refresh cooldown", [
            'cooldown_duration_minutes' => self::COOLDOWN_DURATION / 60,
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);

        // Reset failure counter
        Cache::forget(self::FAILURE_CACHE_PREFIX.$playlistId);
    }

    /**
     * Check if a playlist is in cooldown.
     */
    protected function isInCooldown(int $playlistId): bool
    {
        return Cache::has(self::COOLDOWN_CACHE_PREFIX.$playlistId);
    }

    /**
     * Get cooldown expiry time for a playlist.
     */
    protected function getCooldownExpiry(int $playlistId): ?string
    {
        $timestamp = Cache::get(self::COOLDOWN_CACHE_PREFIX.$playlistId);

        return $timestamp ? \Carbon\Carbon::createFromTimestamp($timestamp)->toDateTimeString() : null;
    }

    /**
     * Refresh a single profile.
     *
     * @return bool True if refresh was successful, false otherwise
     */
    protected function refreshProfile(PlaylistProfile $profile): bool
    {
        try {
            $success = ProfileService::refreshProfile($profile);

            if ($success) {
                Log::info("Successfully refreshed profile {$profile->id}", [
                    'name' => $profile->name,
                    'playlist_id' => $profile->playlist_id,
                ]);

                // Check for expiration warnings
                $this->checkExpirationWarning($profile);

                return true;
            } else {
                Log::warning("Failed to refresh profile {$profile->id}", [
                    'name' => $profile->name,
                    'playlist_id' => $profile->playlist_id,
                ]);

                return false;
            }
        } catch (\Exception $e) {
            Log::error("Error refreshing profile {$profile->id}", [
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if profile is expiring soon and log a warning.
     */
    protected function checkExpirationWarning(PlaylistProfile $profile): void
    {
        $info = $profile->provider_info;

        if (! $info || ! isset($info['user_info']['exp_date'])) {
            return;
        }

        $expDate = $info['user_info']['exp_date'];

        // If exp_date is a Unix timestamp
        if (is_numeric($expDate)) {
            $expiresAt = \Carbon\Carbon::createFromTimestamp($expDate);
        } else {
            $expiresAt = \Carbon\Carbon::parse($expDate);
        }

        $daysUntilExpiry = now()->diffInDays($expiresAt, false);

        if ($daysUntilExpiry <= 0) {
            Log::warning("Profile {$profile->id} has EXPIRED", [
                'name' => $profile->name,
                'expired_at' => $expiresAt->toDateString(),
            ]);

            // Auto-disable expired profiles
            if ($profile->enabled) {
                $profile->update(['enabled' => false]);
                Log::info("Auto-disabled expired profile {$profile->id}");
            }
        } elseif ($daysUntilExpiry <= 7) {
            Log::warning("Profile {$profile->id} expires in {$daysUntilExpiry} days", [
                'name' => $profile->name,
                'expires_at' => $expiresAt->toDateString(),
            ]);
        }
    }
}
