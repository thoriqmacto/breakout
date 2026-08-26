<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Whether Google Drive can actually be used right now.
 *
 * The backup page used to report Drive as "Connected and scanned" or
 * "Unavailable or not configured", which cannot tell a revoked refresh token
 * from a typo in the client secret from a firewall. Those need different
 * remedies, so this resolves the disk (which performs the OAuth exchange) and
 * then reads from it, and classifies whatever comes back.
 *
 * Nothing sensitive leaves this class. Credentials are reported only as
 * configured true/false, and the message and guidance are fixed strings chosen
 * by the classifier -- never Google's raw response, which describes a request
 * carrying the client secret and refresh token.
 */
class GoogleDriveHealth
{
    public function __construct(
        private readonly GoogleDriveOAuthClassifier $classifier,
    ) {}

    /**
     * Probe the disk and describe what happened.
     *
     * @return array{
     *     status:string, configured:bool, connected:bool, refresh_token_status:string,
     *     can_read:bool, code:?string, message:string, guidance:array<int, string>, checked_at:string
     * }
     */
    public function check(string $diskName = 'gdrive'): array
    {
        $checkedAt = Carbon::now()->toIso8601String();
        $configured = $this->configured($diskName);

        // Deliberately probe rather than gate on configuration. Health is a
        // question about what the application can actually do right now, and
        // the provider already refuses to build a disk when a credential is
        // missing -- the classifier recognises that refusal, so an unset
        // variable still surfaces as not_configured without a second code path
        // that could disagree with the first.
        try {
            $disk = Storage::disk($diskName);
        } catch (Throwable $e) {
            // The gdrive driver exchanges the refresh token while resolving, so
            // a rejected grant surfaces here rather than at the first read.
            return $this->result(
                $this->classifier->classify($this->flatten($e)),
                $configured,
                false,
                false,
                $checkedAt,
            );
        }

        try {
            // Resolving proves the token exchange worked; it does not prove the
            // token can reach Drive. A listing is the cheapest call that does.
            $disk->directories('');
        } catch (Throwable $e) {
            return $this->result(
                $this->classifier->classify($this->flatten($e)),
                $configured,
                true,
                false,
                $checkedAt,
            );
        }

        // Reaching here means the token was exchanged and Drive answered, so
        // the refresh token is valid *now* -- which is the only sense in which
        // that can be known. Google does not hand out an expiry to count down.
        return $this->result($this->classifier->healthy(), $configured, true, true, $checkedAt);
    }

    /**
     * Whether all three credentials are present. Their values are never read
     * beyond emptiness.
     */
    public function configured(string $diskName = 'gdrive'): bool
    {
        $config = config("filesystems.disks.{$diskName}");

        if (! is_array($config)) {
            return false;
        }

        foreach (['clientId', 'clientSecret', 'refreshToken'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Collapse an exception chain into one string for classification.
     *
     * Flysystem wraps the Google error, and the part worth matching on is
     * usually innermost. The result is used only to choose a category; it is
     * never returned to the caller.
     */
    private function flatten(Throwable $error): string
    {
        $messages = [];

        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        return implode(' ', $messages);
    }

    /**
     * @param  array{status:string, refresh_token_status:string, message:string, guidance:array<int, string>}  $verdict
     * @return array{
     *     status:string, configured:bool, connected:bool, refresh_token_status:string,
     *     can_read:bool, code:?string, message:string, guidance:array<int, string>, checked_at:string
     * }
     */
    private function result(
        array $verdict,
        bool $configured,
        bool $connected,
        bool $canRead,
        string $checkedAt,
    ): array {
        return [
            'status' => $verdict['status'],
            'configured' => $configured,
            'connected' => $connected,
            'refresh_token_status' => $verdict['refresh_token_status'],
            'can_read' => $canRead,
            'code' => $verdict['status'] === GoogleDriveOAuthClassifier::HEALTHY ? null : $verdict['status'],
            'message' => $verdict['message'],
            'guidance' => $verdict['guidance'],
            'checked_at' => $checkedAt,
        ];
    }
}
