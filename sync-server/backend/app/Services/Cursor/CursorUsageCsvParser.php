<?php

namespace App\Services\Cursor;

class CursorUsageCsvParser
{
    /**
     * @return array{
     *   events: list<array<string, mixed>>,
     *   skipped: int,
     *   models: array<string, int>,
     *   source_file_hash: string
     * }
     */
    public function parse(string $path, string $projectRoot = '/Cursor'): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open CSV: {$path}");
        }

        try {
            $header = fgetcsv($handle);
            if ($header === false) {
                throw new \RuntimeException('CSV is empty.');
            }
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
            $index = array_flip($header);
            foreach ([
                'Date',
                'Kind',
                'Model',
                'Input (w/ Cache Write)',
                'Input (w/o Cache Write)',
                'Cache Read',
                'Output Tokens',
                'Total Tokens',
                'Cost',
            ] as $required) {
                if (! isset($index[$required])) {
                    throw new \RuntimeException("CSV missing column: {$required}");
                }
            }

            $sourceFileHash = hash_file('sha256', $path);
            $events = [];
            $skipped = 0;
            $models = [];
            $fingerprints = [];
            $line = 1;

            while (($row = fgetcsv($handle)) !== false) {
                $line++;
                if ($row === [null] || $row === false) {
                    continue;
                }
                $record = [];
                foreach ($index as $name => $i) {
                    $record[$name] = $row[$i] ?? '';
                }

                $input = $this->intOrNull($record['Input (w/o Cache Write)']);
                $cacheWrite = $this->intOrNull($record['Input (w/ Cache Write)']);
                $cacheRead = $this->intOrNull($record['Cache Read']);
                $output = $this->intOrNull($record['Output Tokens']);

                if ($input === null && $output === null) {
                    $skipped++;

                    continue;
                }

                $input ??= 0;
                $cacheWrite ??= 0;
                $cacheRead ??= 0;
                $output ??= 0;
                if ($input + $cacheWrite + $cacheRead + $output <= 0) {
                    $skipped++;

                    continue;
                }

                $model = trim((string) $record['Model']);
                $timestamp = trim((string) $record['Date']);
                $kind = trim((string) $record['Kind']);
                $cost = trim((string) $record['Cost']);
                $fingerprint = hash('sha256', implode("\0", [
                    $timestamp,
                    $model,
                    $kind,
                    (string) $input,
                    (string) $cacheWrite,
                    (string) $cacheRead,
                    (string) $output,
                    $cost,
                ]));
                $fingerprints[$fingerprint] = ($fingerprints[$fingerprint] ?? 0) + 1;
                $occurrence = $fingerprints[$fingerprint];

                $models[$model] = ($models[$model] ?? 0) + 1;

                $warnings = [];
                if ($kind !== '') {
                    $warnings[] = 'cursor_kind:'.$kind;
                }
                if ($cost !== '') {
                    $warnings[] = 'cursor_cost:'.$cost;
                }

                $events[] = [
                    'source_event_id' => "cursor-csv:{$fingerprint}:{$occurrence}",
                    'source_event_hash' => $fingerprint,
                    'source_file_hash' => $sourceFileHash,
                    'source_offset' => $line,
                    'provider_id' => 'cursor',
                    'timestamp' => $timestamp,
                    'model' => $model,
                    'project' => [
                        'root_path' => $projectRoot,
                        'display_name' => 'Cursor',
                    ],
                    'conversation' => null,
                    'tokens' => [
                        'input' => $input,
                        'output' => $output,
                        'cached_input' => 0,
                        'cache_write' => $cacheWrite,
                        'cache_read' => $cacheRead,
                        'reasoning' => 0,
                        'tool' => 0,
                        'unknown' => 0,
                    ],
                    'warnings' => $warnings,
                ];
            }
        } finally {
            fclose($handle);
        }

        return [
            'events' => $events,
            'skipped' => $skipped,
            'models' => $models,
            'source_file_hash' => $sourceFileHash,
        ];
    }

    private function intOrNull(string $value): ?int
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
