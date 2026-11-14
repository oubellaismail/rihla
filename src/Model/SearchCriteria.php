<?php

namespace App\Model;

use App\Enum\LuggageSize;
use Symfony\Component\HttpFoundation\InputBag;

/** The filters and the sort order of the search results, read from the query string; invalid values are ignored. */
final readonly class SearchCriteria
{
    public const TIME_SLOTS = ['morning', 'afternoon', 'evening'];
    public const SORTS = ['departure', 'price', 'rating'];
    public const BAGS = [LuggageSize::Medium, LuggageSize::Large];

    /**
     * @param list<string> $times slots among TIME_SLOTS
     */
    public function __construct(
        public array $times = [],
        public ?int $maxPrice = null,
        public ?LuggageSize $bag = null,
        public bool $rated = false,
        public string $sort = 'departure',
    ) {
    }

    public static function fromQuery(InputBag $query): self
    {
        $times = array_values(array_intersect(self::TIME_SLOTS, array_filter((array) ($query->all()['time'] ?? []), 'is_string')));

        $maxPrice = $query->all()['maxPrice'] ?? null;
        $maxPrice = \is_string($maxPrice) && ctype_digit($maxPrice) && (int) $maxPrice > 0 ? (int) $maxPrice : null;

        $bag = LuggageSize::tryFrom((string) ($query->all()['bag'] ?? ''));
        $bag = \in_array($bag, self::BAGS, true) ? $bag : null;

        $sort = $query->all()['sort'] ?? '';
        $sort = \is_string($sort) && \in_array($sort, self::SORTS, true) ? $sort : 'departure';

        return new self($times, $maxPrice, $bag, '1' === ($query->all()['rated'] ?? null), $sort);
    }

    public function isFiltered(): bool
    {
        return [] !== $this->times || null !== $this->maxPrice || null !== $this->bag || $this->rated;
    }

    /** The query parameters to put back in a link, with another sort if asked. @return array<string, mixed> */
    public function toQuery(?string $sort = null): array
    {
        $sort ??= $this->sort;
        $query = [];
        if ([] !== $this->times) {
            $query['time'] = $this->times;
        }
        if (null !== $this->maxPrice) {
            $query['maxPrice'] = $this->maxPrice;
        }
        if (null !== $this->bag) {
            $query['bag'] = $this->bag->value;
        }
        if ($this->rated) {
            $query['rated'] = 1;
        }
        if ('departure' !== $sort) {
            $query['sort'] = $sort;
        }

        return $query;
    }
}
