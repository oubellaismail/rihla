<?php

namespace App\Twig;

use App\Entity\Trip;
use App\Entity\TripStop;
use App\Enum\LuggageSize;
use App\Service\Luggage\TrunkSpace;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

/** Luggage wording for the templates: what still fits in a trunk, bag sizes, a bag summary. */
final class LuggageExtension extends AbstractExtension
{
    public function __construct(private readonly TrunkSpace $trunkSpace)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('trunk_line', $this->trunkLine(...)),
            new TwigFunction('luggage_sizes', $this->sizes(...)),
            new TwigFunction('trunk_words', $this->trunkSpace->describe(...)),
        ];
    }

    public function getFilters(): array
    {
        return [new TwigFilter('describe_bags', LuggageSize::describeBags(...))];
    }

    /**
     * What still fits in the trunk on a portion.
     *
     * @return array{free: int, words: string, full: bool}
     */
    public function trunkLine(Trip $trip, TripStop $from, TripStop $to): array
    {
        $free = $this->trunkSpace->freeLitres($trip, $from->getPosition(), $to->getPosition());
        $words = $this->trunkSpace->describe($free);

        return ['free' => $free, 'words' => $words, 'full' => 'plein' === $words];
    }

    /**
     * The bag sizes with the words the seat page needs (also read by the Stimulus controller).
     *
     * @return list<array{key: string, label: string, litres: int, one: string, many: string, example: string}>
     */
    public function sizes(): array
    {
        $examples = [
            LuggageSize::Cabin->value => 'Petite valise à roulettes, grand sac à dos',
            LuggageSize::Medium->value => 'Pour quelques jours',
            LuggageSize::Large->value => 'Valise de soute, sac de voyage plein',
        ];
        $sizes = [];
        foreach (LuggageSize::cases() as $size) {
            $sizes[] = [
                'key' => $size->value,
                'label' => $size->label(),
                'litres' => $size->litres(),
                'one' => $size->noun(1),
                'many' => $size->noun(2),
                'example' => $examples[$size->value],
            ];
        }

        return $sizes;
    }
}
