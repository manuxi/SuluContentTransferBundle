<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Plan;

use Manuxi\SuluContentTransferBundle\Format\Data;

/**
 * An article of the package as the analysis sees it: how it relates to this system, what is suggested and allowed,
 * which languages and addresses it would get.
 */
final class PlanArticle
{
    public const MATCH_MAPPING = 'mapping';
    public const MATCH_UUID = 'uuid';

    /** @var array<string, string> */
    public readonly array $titles;

    /** @var list<string> */
    public readonly array $allowedActions;

    /** @var array<string, PlanLocale> */
    public readonly array $locales;

    /** @var array<string, PlanUrl> */
    public readonly array $urls;

    /** @var array<string, PlanUrl> */
    public readonly array $copyUrls;

    /**
     * @param array<string, string>   $titles         title per language
     * @param string                  $state
     * @param ?string                 $matchedBy      how the article on this system was found ("mapping", "uuid") or null
     * @param ?string                 $targetId       UUID of the article on this system, if there is one
     * @param string                  $defaultAction
     * @param list<string>            $allowedActions
     * @param array<int, PlanLocale>  $locales
     * @param array<string, PlanUrl>  $urls           addresses for the actions "create" and "update"
     * @param array<string, PlanUrl>  $copyUrls       addresses for the action "copy" (the original keeps its own)
     * @param ?string                 $mainWebspace   webspace the article asks for in the package
     * @param ?string                 $targetWebspace webspace the article gets on this system
     * @param ?\DateTimeImmutable     $changedAt      when the article was last changed on this system (state "changed")
     * @param ?string                 $changedBy      who changed it
     */
    public function __construct(
        public readonly string $uuid,
        array $titles,
        public readonly ?string $templateKey,
        public readonly string $state,
        public readonly ?string $matchedBy,
        public readonly ?string $targetId,
        public readonly string $defaultAction,
        array $allowedActions,
        array $locales,
        array $urls,
        array $copyUrls,
        public readonly ?string $mainWebspace,
        public readonly ?string $targetWebspace,
        public readonly ?\DateTimeImmutable $changedAt = null,
        public readonly ?string $changedBy = null,
    ) {
        ksort($titles, \SORT_STRING);
        $this->titles = $titles;
        $this->allowedActions = $allowedActions;

        $byLocale = [];
        foreach ($locales as $locale) {
            $byLocale[$locale->locale] = $locale;
        }
        ksort($byLocale, \SORT_STRING);
        $this->locales = $byLocale;

        ksort($urls, \SORT_STRING);
        $this->urls = $urls;
        ksort($copyUrls, \SORT_STRING);
        $this->copyUrls = $copyUrls;
    }

    /**
     * @return list<string> languages the import writes
     */
    public function importLocales(): array
    {
        return array_keys(array_filter($this->locales, static fn (PlanLocale $locale): bool => $locale->import));
    }

    public function title(): string
    {
        foreach ($this->titles as $title) {
            if ('' !== $title) {
                return $title;
            }
        }

        return $this->uuid;
    }

    /**
     * @param array<int|string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $path = 'plan.article';

        $state = Data::string($data, 'state', $path);
        if (!\in_array($state, ArticleState::ALL, true)) {
            throw Data::error($path, 'state', 'a known state');
        }
        $default = Data::string($data, 'defaultAction', $path);
        if (!\in_array($default, ArticleAction::ALL, true)) {
            throw Data::error($path, 'defaultAction', 'a known action');
        }

        $locales = [];
        foreach (Data::list($data, 'locales', $path) as $locale) {
            $locales[] = PlanLocale::fromArray(\is_array($locale) ? $locale : throw Data::error($path, 'locales', 'a list of objects'));
        }

        return new self(
            Data::string($data, 'uuid', $path),
            Data::stringMap($data, 'titles', $path),
            Data::nullableString($data, 'templateKey', $path),
            $state,
            Data::nullableString($data, 'matchedBy', $path),
            Data::nullableString($data, 'targetId', $path),
            $default,
            Data::stringList($data, 'allowedActions', $path),
            $locales,
            self::urlsFrom($data, 'urls', $path),
            self::urlsFrom($data, 'copyUrls', $path),
            Data::nullableString($data, 'mainWebspace', $path),
            Data::nullableString($data, 'targetWebspace', $path),
            Data::nullableDateTime($data, 'changedAt', $path),
            Data::nullableString($data, 'changedBy', $path),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'titles' => Data::mapOut($this->titles),
            'templateKey' => $this->templateKey,
            'state' => $this->state,
            'matchedBy' => $this->matchedBy,
            'targetId' => $this->targetId,
            'defaultAction' => $this->defaultAction,
            'allowedActions' => $this->allowedActions,
            'locales' => array_values(array_map(static fn (PlanLocale $locale): array => $locale->toArray(), $this->locales)),
            'urls' => Data::mapOut(array_map(static fn (PlanUrl $url): array => $url->toArray(), $this->urls)),
            'copyUrls' => Data::mapOut(array_map(static fn (PlanUrl $url): array => $url->toArray(), $this->copyUrls)),
            'mainWebspace' => $this->mainWebspace,
            'targetWebspace' => $this->targetWebspace,
            'changedAt' => Data::dateOut($this->changedAt),
            'changedBy' => $this->changedBy,
        ];
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<string, PlanUrl>
     */
    private static function urlsFrom(array $data, string $key, string $path): array
    {
        $urls = [];
        foreach (Data::map($data, $key, $path) as $locale => $url) {
            $urls[(string) $locale] = PlanUrl::fromArray(\is_array($url) ? $url : throw Data::error($path.'.'.$key, (string) $locale, 'an object'));
        }

        return $urls;
    }
}
