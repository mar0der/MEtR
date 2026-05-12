<?php

namespace App\Console\Commands;

use App\Models\UpdateAsset;
use App\Models\UpdateRelease;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PublishUpdateRelease extends Command
{
    protected $signature = 'metr:release:publish
        {--version= : Release version (e.g. 0.1.1)}
        {--notes= : Release notes}
        {--darwin-dmg= : Path to macOS .dmg artifact}
        {--darwin-sig= : Path to macOS .dmg.sig file}
        {--windows-msi= : Path to Windows .msi artifact}
        {--windows-sig= : Path to Windows .msi.sig file}
        {--force : Overwrite existing release with same version}';

    protected $description = 'Publish a new MEtR app update release';

    public function handle(): int
    {
        $version = $this->option('version');
        if (! $version) {
            $this->error('Version is required. Use --version=0.1.1');

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

        if ($this->option('darwin-dmg') && $this->option('darwin-sig')) {
            $dmgPath = $this->option('darwin-dmg');
            $sigPath = $this->option('darwin-sig');

            if (! file_exists($dmgPath) || ! file_exists($sigPath)) {
                $this->error('macOS artifact or signature file not found.');

                return self::FAILURE;
            }

            $filename = basename($dmgPath);
            $disk->putFileAs('', $dmgPath, $filename);

            $platforms[] = UpdateAsset::create([
                'update_release_id' => $release->id,
                'platform' => 'darwin-aarch64',
                'filename' => $filename,
                'signature' => trim(file_get_contents($sigPath)),
            ]);

            $this->info("Uploaded macOS artifact: {$filename}");
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
                'signature' => trim(file_get_contents($sigPath)),
            ]);

            $this->info("Uploaded Windows artifact: {$filename}");
        }

        if (empty($platforms)) {
            $this->warn('No artifacts uploaded. Provide --darwin-dmg/--darwin-sig and/or --windows-msi/--windows-sig.');
        }

        $this->info("Release {$version} published successfully.");

        return self::SUCCESS;
    }
}
