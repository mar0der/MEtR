<?php

namespace App\Console\Commands;

use App\Models\UpdateAsset;
use App\Models\UpdateRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PublishUpdateRelease extends Command
{
    protected $signature = 'metr:release:publish
        {--release-version= : Release version (e.g. 0.1.1)}
        {--notes= : Release notes}
        {--darwin-tgz= : Path to macOS .tar.gz updater archive}
        {--darwin-sig= : Path to macOS .tar.gz.sig signature file}
        {--darwin-dmg= : Path to macOS .dmg installer (optional, for fresh installs)}
        {--windows-msi= : Path to Windows .msi artifact}
        {--windows-sig= : Path to Windows .msi.sig file}
        {--force : Overwrite existing release with same version}';

    protected $description = 'Publish a new MEtR app update release';

    public function handle(): int
    {
        $version = $this->option('release-version');
        if (! $version) {
            $this->error('Version is required. Use --release-version=0.1.1');

            return self::FAILURE;
        }

        $notes = $this->option('notes') ?? '';
        $disk = Storage::disk('updates');

        $existing = UpdateRelease::where('version', $version)->first();
        if ($existing && ! $this->option('force')) {
            $this->error("Release {$version} already exists. Use --force to overwrite.");

            return self::FAILURE;
        }

        if ($existing) {
            $existing->assets()->delete();
            $existing->delete();
        }

        $release = UpdateRelease::create([
            'version' => $version,
            'release_notes' => $notes,
            'released_at' => now(),
        ]);

        $platforms = [];

        // macOS updater uses .tar.gz archive
        if ($this->option('darwin-tgz') && $this->option('darwin-sig')) {
            $tgzPath = $this->option('darwin-tgz');
            $sigPath = $this->option('darwin-sig');

            if (! file_exists($tgzPath) || ! file_exists($sigPath)) {
                $this->error('macOS updater archive or signature file not found.');

                return self::FAILURE;
            }

            $filename = basename($tgzPath);
            $disk->putFileAs('', $tgzPath, $filename);

            $platforms[] = UpdateAsset::create([
                'update_release_id' => $release->id,
                'platform' => 'darwin-aarch64',
                'filename' => $filename,
                'signature' => $this->normalizeSignature(file_get_contents($sigPath)),
            ]);

            $this->info("Uploaded macOS updater archive: {$filename}");
        }

        // Optional: macOS DMG for fresh installs
        if ($this->option('darwin-dmg')) {
            $dmgPath = $this->option('darwin-dmg');
            if (file_exists($dmgPath)) {
                $filename = basename($dmgPath);
                $disk->putFileAs('', $dmgPath, $filename);
                $this->info("Uploaded macOS installer DMG: {$filename}");
            }
        }

        if ($this->option('windows-msi') && $this->option('windows-sig')) {
            $msiPath = $this->option('windows-msi');
            $sigPath = $this->option('windows-sig');

            if (! file_exists($msiPath) || ! file_exists($sigPath)) {
                $this->error('Windows artifact or signature file not found.');

                return self::FAILURE;
            }

            $filename = basename($msiPath);
            $disk->putFileAs('', $msiPath, $filename);

            $platforms[] = UpdateAsset::create([
                'update_release_id' => $release->id,
                'platform' => 'windows-x86_64',
                'filename' => $filename,
                'signature' => $this->normalizeSignature(file_get_contents($sigPath)),
            ]);

            $this->info("Uploaded Windows artifact: {$filename}");
        }

        if (empty($platforms)) {
            $this->warn('No updater artifacts uploaded. Provide --darwin-tgz/--darwin-sig and/or --windows-msi/--windows-sig.');
        }

        $this->info("Release {$version} published successfully.");

        return self::SUCCESS;
    }

    /**
     * Normalize a signature file for Tauri's updater.
     *
     * Tauri expects the signature JSON field to be base64-encoded.
     * macOS .sig files are raw minisign text (multi-line), while
     * Windows .sig files from GitHub Actions are already base64-encoded.
     * This helper detects which format we have and encodes only when needed.
     */
    private function normalizeSignature(string $content): string
    {
        $content = trim($content);

        // If it contains newlines, it's raw minisign text → base64 encode it
        if (str_contains($content, "\n")) {
            return base64_encode($content);
        }

        // Single line: might already be base64. Try decoding it.
        // If decoding succeeds and yields "untrusted comment", it's already base64.
        $decoded = base64_decode($content, true);
        if ($decoded !== false && str_contains($decoded, 'untrusted comment')) {
            return $content; // Already base64-encoded
        }

        // Otherwise, treat as raw text and base64 encode
        return base64_encode($content);
    }
}
