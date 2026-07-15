<?php
declare(strict_types=1);

/**
 * Kachelgröße im Bento-Grid.
 * - normal: 1×1
 * - wide:   2×1
 * - tall:   1×2
 * - large:  2×2
 */
enum Size: string
{
    case Normal = 'normal';
    case Wide   = 'wide';
    case Tall   = 'tall';
    case Large  = 'large';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Standard · 1×1',
            self::Wide   => 'Breit · 2×1',
            self::Tall   => 'Hoch · 1×2',
            self::Large  => 'Groß · 2×2',
        };
    }

    public function cssClass(): string
    {
        return 'size-' . $this->value;
    }

    public static function tryFromOrNormal(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Normal;
    }
}
