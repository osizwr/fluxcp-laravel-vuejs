<?php

declare(strict_types=1);

namespace App\Services\Rathena;

/**
 * Resolves the names behind rAthena's bare numeric ids.
 *
 * The emulator stores a character's job and a homunculus's species as integers
 * and does not expose their names to anything outside itself, so the mapping
 * has to live in the panel. Keeping it here rather than in the frontend means
 * it exists once: the API returns a resolved name beside the id.
 */
final class ReferenceData
{
    /**
     * The name of a job class, or a readable fallback for an id a server has
     * added without extending the configuration.
     */
    public function jobName(int $jobId): string
    {
        $jobs = (array) config('rathena_reference.jobs', []);

        return isset($jobs[$jobId]) ? (string) $jobs[$jobId] : "Job {$jobId}";
    }

    public function homunculusName(int $classId): string
    {
        $classes = (array) config('rathena_reference.homunculus', []);

        return isset($classes[$classId]) ? (string) $classes[$classId] : "Homunculus {$classId}";
    }

    /**
     * Every job class, for a filter control.
     *
     * @return array<int, string>
     */
    public function jobs(): array
    {
        return (array) config('rathena_reference.jobs', []);
    }

    public function isKnownJob(int $jobId): bool
    {
        return array_key_exists($jobId, $this->jobs());
    }
}
