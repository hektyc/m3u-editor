<?php

use App\Models\Network;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->user = User::factory()->create();
});

afterEach(function () {
    // Clean up any HLS files created during the test
    Carbon::setTestNow();

    // Clean up any created test directories
    $networksPath = storage_path('app/networks');
    if (File::exists($networksPath)) {
        foreach (File::directories($networksPath) as $dir) {
            $dirName = basename($dir);
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $dirName)) {
                File::deleteDirectory($dir);
            }
        }
    }
});

it('reconnect after stop cannot resume HLS playlist or segments', function () {
    // Fix the "Time Drift" - ensures now() in test matches now() in Controller
    Carbon::setTestNow(now());

    // Use the factory's activeBroadcast state to create a network that is already broadcasting
    // This ensures the broadcast_started_at and broadcast_pid are set atomically during creation
    $network = Network::factory()->for($this->user)->activeBroadcast()->create([
        'enabled' => true,
    ]);

    // Create HLS files for the test
    $hlsPath = $network->getHlsStoragePath();
    File::ensureDirectoryExists($hlsPath);

    // Create a playlist and a segment
    File::put("{$hlsPath}/live.m3u8", "#EXTM3U\n#EXT-X-TARGETDURATION:6\n#EXTINF:6,\nlive000001.ts\n");
    File::put("{$hlsPath}/live000001.ts", 'segment-data');

    // Verify the network is broadcasting
    expect($network->isBroadcasting())->toBeTrue();

    // Sanity check: endpoints are reachable while "broadcasting"
    $this->get(route('network.hls.playlist', ['network' => $network->uuid]))
        ->assertStatus(200);

    $segmentResp = $this->get(route('network.hls.segment', ['network' => $network->uuid, 'segment' => 'live000001']));
    $segmentResp->assertStatus(200);

    // Cache headers
    $cacheHeader = $segmentResp->headers->get('Cache-Control');
    expect($cacheHeader)->toContain('no-cache');
    expect($cacheHeader)->toContain('no-store');

    // ACTION: Stop the broadcast
    app(\App\Services\NetworkBroadcastService::class)->stop($network);

    // Refresh the network to get the updated state
    $network->refresh();

    // VERIFY: After stopping, reconnecting should NOT be able to resume playback
    // Allow either 503 (not active) or 404 (files removed)
    $playlistResp = $this->get(route('network.hls.playlist', ['network' => $network->uuid]));
    expect(in_array($playlistResp->getStatusCode(), [503, 404]))->toBeTrue();

    $segmentResp = $this->get(route('network.hls.segment', ['network' => $network->uuid, 'segment' => 'live000001']));
    expect(in_array($segmentResp->getStatusCode(), [503, 404]))->toBeTrue();
});
