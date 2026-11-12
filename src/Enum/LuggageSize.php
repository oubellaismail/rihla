<?php

namespace App\Enum;

/**
 * Bag sizes a passenger can declare. A handbag or small backpack is never declared:
 * it travels with the passenger.
 */
enum LuggageSize: string
{
    case Cabin = 'cabin';
    case Medium = 'medium';
    case Large = 'large';

    /** Volume counted in the trunk for one bag of this size. */
    public function litres(): int
    {
        return match ($this) {
            self::Cabin => 40,
            self::Medium => 70,
            self::Large => 100,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Cabin => 'Bagage cabine',
            self::Medium => 'Valise moyenne',
            self::Large => 'Grande valise',
        };
    }

    /**
     * Total volume of a set of bags.
     *
     * @param array<string, int> $bags counts keyed by size value; missing sizes count 0
     */
    public static function litresOf(array $bags): int
    {
        $litres = 0;
        foreach (self::cases() as $size) {
            $litres += ($bags[$size->value] ?? 0) * $size->litres();
        }

        return $litres;
    }

    /**
     * « 1 bagage cabine, 1 grande valise »: smallest size first, sizes with no bag skipped.
     *
     * @param array<string, int> $bags counts keyed by size value
     */
    public static function describeBags(array $bags): string
    {
        $parts = [];
        foreach (self::cases() as $size) {
            $count = $bags[$size->value] ?? 0;
            if ($count <= 0) {
                continue;
            }
            $parts[] = sprintf('%d %s', $count, $size->noun($count));
        }

        return [] === $parts ? 'Aucun bagage en soute' : implode(', ', $parts);
    }

    /** « valise moyenne » / « valises moyennes » */
    public function noun(int $count): string
    {
        $plural = $count > 1;

        return match ($this) {
            self::Cabin => $plural ? 'bagages cabine' : 'bagage cabine',
            self::Medium => $plural ? 'valises moyennes' : 'valise moyenne',
            self::Large => $plural ? 'grandes valises' : 'grande valise',
        };
    }
}
