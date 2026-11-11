<?php

namespace App\Shared\Calendar;

final class CalendarRegistry
{
    /** @var array<string, CalendarSource> */
    private array $sources = [];

    public function register(CalendarSource $source): void
    {
        $this->sources[$source->key()] = $source;
    }

    /**
     * @return array<string, CalendarSource>
     */
    public function all(): array
    {
        return $this->sources;
    }
}
