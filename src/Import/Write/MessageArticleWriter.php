<?php

declare(strict_types=1);

namespace Manuxi\SuluContentTransferBundle\Import\Write;

use Sulu\Article\Application\Message\ApplyWorkflowTransitionArticleMessage;
use Sulu\Article\Application\Message\CreateArticleMessage;
use Sulu\Article\Application\Message\ModifyArticleMessage;
use Sulu\Content\Domain\Model\WorkflowInterface;
use Sulu\Messenger\Infrastructure\Symfony\Messenger\FlushMiddleware\EnableFlushStamp;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Writes articles with the messages the admin uses, so routes, search index, references, cache and activity log follow.
 * The caller clears the entity manager before each call (see ImportUserContext::refresh()).
 */
class MessageArticleWriter implements ArticleWriter
{
    public function __construct(private readonly MessageBusInterface $messageBus)
    {
    }

    public function create(string $uuid, array $data): void
    {
        $this->dispatch(new CreateArticleMessage(['uuid' => $uuid] + $data));
    }

    public function modify(string $uuid, array $data): void
    {
        $this->dispatch(new ModifyArticleMessage(['uuid' => $uuid], $data));
    }

    public function publish(string $uuid, string $locale): void
    {
        $this->dispatch(new ApplyWorkflowTransitionArticleMessage(['uuid' => $uuid], $locale, WorkflowInterface::WORKFLOW_TRANSITION_PUBLISH));
    }

    private function dispatch(object $message): void
    {
        $this->messageBus->dispatch(new Envelope($message, [new EnableFlushStamp()]));
    }
}
