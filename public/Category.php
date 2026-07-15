<?php
declare(strict_types=1);

/**
 * Kategorie-Enum.
 * Stellt sicher, dass nur definierte Kategorien gespeichert/gefiltert werden.
 */
enum Category: string
{
    case All      = 'all';
    case News     = 'news';
    case Alarm    = 'alarm';
    case Wetter   = 'wetter';
    case Links    = 'links';
    case Internal = 'internal';
    case Events   = 'events';

    public function label(): string
    {
        return match ($this) {
            self::All      => 'Alle',
            self::News     => 'Neuigkeiten',
            self::Alarm    => 'Alarme',
            self::Wetter   => 'Wetter',
            self::Links    => 'Links',
            self::Internal => 'Intern',
            self::Events   => 'Veranstaltungen',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::All      => '✦',
            self::News     => '◐',
            self::Alarm    => '◉',
            self::Wetter   => '◑',
            self::Links    => '◇',
            self::Internal => '◈',
            self::Events   => '◎',
        };
    }

    /** Akzent-Farbton als HSL-Hue (0–360) — wird im CSS für badge-tints verwendet. */
    public function hue(): int
    {
        return match ($this) {
            self::All      => 220,
            self::News     => 200,
            self::Alarm    => 0,
            self::Wetter   => 190,
            self::Links    => 270,
            self::Internal => 150,
            self::Events   => 35,
        };
    }

    public static function tryFromOrAll(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }

    /** Reihenfolge für die Filterleiste (ohne "all"). */
    public static function selectable(): array
    {
        return [
            self::News,
            self::Alarm,
            self::Wetter,
            self::Events,
            self::Internal,
            self::Links,
        ];
    }
}
