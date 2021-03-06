<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Search\Escargot;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Search\Escargot\Subscriber\EscargotSubscriberInterface;
use Contao\PageModel;
use Doctrine\DBAL\Connection;
use Nyholm\Psr7\Uri;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Terminal42\Escargot\BaseUriCollection;
use Terminal42\Escargot\Escargot;
use Terminal42\Escargot\Exception\InvalidJobIdException;
use Terminal42\Escargot\Queue\DoctrineQueue;
use Terminal42\Escargot\Queue\InMemoryQueue;
use Terminal42\Escargot\Queue\LazyQueue;
use Terminal42\Escargot\Queue\QueueInterface;
use Terminal42\Escargot\Subscriber\HtmlCrawlerSubscriber;
use Terminal42\Escargot\Subscriber\RobotsSubscriber;

class Factory
{
    /**
     * @var Connection
     */
    private $connection;

    /**
     * @var ContaoFramework
     */
    private $framework;

    /**
     * @var array<string>
     */
    private $additionalUris;

    /**
     * @var array
     */
    private $defaultHttpClientOptions;

    /**
     * @var EscargotSubscriberInterface[]
     */
    private $subscribers = [];

    public function __construct(Connection $connection, ContaoFramework $framework, array $additionalUris = [], array $defaultHttpClientOptions = [])
    {
        $this->connection = $connection;
        $this->framework = $framework;
        $this->additionalUris = $additionalUris;
        $this->defaultHttpClientOptions = $defaultHttpClientOptions;
    }

    public function addSubscriber(EscargotSubscriberInterface $subscriber): self
    {
        $this->subscribers[] = $subscriber;

        return $this;
    }

    /**
     * @return EscargotSubscriberInterface[]
     */
    public function getSubscribers(array $selectedSubscribers = []): array
    {
        if (0 === \count($selectedSubscribers)) {
            return $this->subscribers;
        }

        return array_filter(
            $this->subscribers,
            static function (EscargotSubscriberInterface $subscriber) use ($selectedSubscribers): bool {
                return \in_array($subscriber->getName(), $selectedSubscribers, true);
            }
        );
    }

    /**
     * @return array<string>
     */
    public function getSubscriberNames(): array
    {
        return array_map(
            static function (EscargotSubscriberInterface $subscriber): string {
                return $subscriber->getName();
            },
            $this->subscribers
        );
    }

    public function createLazyQueue(): LazyQueue
    {
        return new LazyQueue(
            new InMemoryQueue(),
            new DoctrineQueue(
                $this->connection,
                static function (): string {
                    return Uuid::uuid4()->toString();
                },
                'tl_search_index_queue'
            )
        );
    }

    public function getDefaultHttpClientOptions(): array
    {
        return $this->defaultHttpClientOptions;
    }

    public function getSearchUriCollection(): BaseUriCollection
    {
        return $this->getRootPageUriCollection()->mergeWith($this->getAdditionalSearchUriCollection());
    }

    public function getAdditionalSearchUriCollection(): BaseUriCollection
    {
        $collection = new BaseUriCollection();

        foreach ($this->additionalUris as $additionalUri) {
            $collection->add(new Uri($additionalUri));
        }

        return $collection;
    }

    public function getRootPageUriCollection(): BaseUriCollection
    {
        $this->framework->initialize();

        $collection = new BaseUriCollection();

        /** @var PageModel $pageModel */
        $pageModel = $this->framework->getAdapter(PageModel::class);
        $rootPages = $pageModel->findPublishedRootPages();

        if (null === $rootPages) {
            return $collection;
        }

        foreach ($rootPages as $rootPage) {
            $collection->add(new Uri($rootPage->getAbsoluteUrl()));
        }

        return $collection;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public function create(BaseUriCollection $baseUris, QueueInterface $queue, array $selectedSubscribers, ?HttpClientInterface $client = null): Escargot
    {
        $escargot = Escargot::create($baseUris, $queue, $client ?? $this->createDefaultHttpClient());

        $this->registerDefaultSubscribers($escargot);
        $this->registerSubscribers($escargot, $this->validateSubscribers($selectedSubscribers));

        return $escargot;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws InvalidJobIdException
     */
    public function createFromJobId(string $jobId, QueueInterface $queue, array $selectedSubscribers, ?HttpClientInterface $client = null): Escargot
    {
        $escargot = Escargot::createFromJobId($jobId, $queue, $client ?? $this->createDefaultHttpClient());

        $this->registerDefaultSubscribers($escargot);
        $this->registerSubscribers($escargot, $this->validateSubscribers($selectedSubscribers));

        return $escargot;
    }

    private function createDefaultHttpClient(): HttpClientInterface
    {
        return HttpClient::create($this->getDefaultHttpClientOptions());
    }

    private function registerDefaultSubscribers(Escargot $escargot): void
    {
        $escargot->addSubscriber(new RobotsSubscriber());
        $escargot->addSubscriber(new HtmlCrawlerSubscriber());
    }

    private function registerSubscribers(Escargot $escargot, array $selectedSubscribers): void
    {
        foreach ($this->subscribers as $subscriber) {
            if (\in_array($subscriber->getName(), $selectedSubscribers, true)) {
                $escargot->addSubscriber($subscriber);
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function validateSubscribers(array $selectedSubscribers): array
    {
        $msg = sprintf(
            'You have to specify at least one valid subscriber name. Valid subscribers are: %s',
            implode(', ', $this->getSubscriberNames())
        );

        if (0 === \count($selectedSubscribers)) {
            throw new \InvalidArgumentException($msg);
        }

        $selectedSubscribers = array_intersect($this->getSubscriberNames(), $selectedSubscribers);

        if (0 === \count($selectedSubscribers)) {
            throw new \InvalidArgumentException($msg);
        }

        return $selectedSubscribers;
    }
}
