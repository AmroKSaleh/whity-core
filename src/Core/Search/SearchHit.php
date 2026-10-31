<?php

declare(strict_types=1);

namespace Whity\Core\Search;

/**
 * One record a search found (WHIT-783): what it is, what to call it, and where
 * a person opens it. Never more than the list route for that record shows.
 */
final class SearchHit
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $subtitle = null,
        public readonly ?string $url = null,
    ) {
    }

    /** @return array{type: string, id: string, title: string, subtitle: ?string, url: ?string} */
    public function toArray(): array
    {
        return [
            'type'     => $this->type,
            'id'       => $this->id,
            'title'    => $this->title,
            'subtitle' => $this->subtitle,
            'url'      => $this->url,
        ];
    }
}
