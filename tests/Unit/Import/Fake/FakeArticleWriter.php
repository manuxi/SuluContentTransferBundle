<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Tests\Unit\Import\Fake;

use Manuxi\SuluContentTransferBundle\Import\Write\ArticleWriter;

final class FakeArticleWriter implements ArticleWriter
{
    /** @var list<string> UUIDs whose create/modify fails */
    public array $failFor = [];

    public function __construct(private readonly WriteLog $log, private readonly ?FakeArticleTarget $routes = null)
    {
    }

    public function create(string $uuid, array $data): void
    {
        $this->failIfWanted($uuid);
        $this->log->add('create', $uuid, $data, 'create '.$uuid.' '.$data['locale']);
        $this->takeAddress($uuid, $data);
    }

    public function modify(string $uuid, array $data): void
    {
        $this->failIfWanted($uuid);
        $this->log->add('modify', $uuid, $data, 'modify '.$uuid.' '.$data['locale']);
        $this->takeAddress($uuid, $data);
    }

    public function publish(string $uuid, string $locale): void
    {
        $this->log->add('publish', $uuid, $locale, 'publish '.$uuid.' '.$locale);
    }

    /**
     * The database knows the address of an article once it is written.
     *
     * @param array<string, mixed> $data
     */
    private function takeAddress(string $uuid, array $data): void
    {
        if (null !== $this->routes && \is_string($data['url'] ?? null)) {
            $this->routes->routes[$data['locale'].'|'.$data['url']] = 'articles:'.$uuid;
        }
    }

    private function failIfWanted(string $uuid): void
    {
        if (\in_array($uuid, $this->failFor, true)) {
            throw new \RuntimeException('Sulu said no to '.$uuid);
        }
    }
}
