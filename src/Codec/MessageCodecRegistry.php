<?php

declare(strict_types=1);

namespace Fedor\MessageBus\Codec;

use Fedor\MessageBus\Message\Command;
use Fedor\MessageBus\Message\Event;
use Fedor\MessageBus\Message\Query;
use InvalidArgumentException;

final readonly class MessageCodecRegistry
{
    /** @var array<string, MessageCodecInterface> */
    private array $codecsByAlias;

    /** @var array<class-string, MessageCodecInterface> */
    private array $codecsByClass;

    /** @var array<string, array<int, MessageUpcasterInterface>> */
    private array $upcasters;

    /**
     * @param list<MessageCodecInterface> $codecs
     * @param list<MessageUpcasterInterface> $upcasters
     */
    public function __construct(array $codecs, array $upcasters = [])
    {
        $byAlias = [];
        $byClass = [];
        foreach ($codecs as $codec) {
            $alias = trim($codec->alias());
            $class = $codec->messageClass();
            $version = $codec->version();
            if ($alias === '') {
                throw new InvalidArgumentException('Message codec alias must not be empty.');
            }
            if ($version < 1) {
                throw new InvalidArgumentException(sprintf('Message codec "%s" version must be positive.', $alias));
            }
            if (
                !class_exists($class)
                || (!is_a($class, Query::class, true)
                    && !is_a($class, Command::class, true)
                    && !is_a($class, Event::class, true))
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Message codec "%s" must reference a Query, Command, or Event class.',
                    $alias,
                ));
            }
            if (isset($byAlias[$alias])) {
                throw new InvalidArgumentException(sprintf('Message codec alias "%s" is duplicated.', $alias));
            }
            if (isset($byClass[$class])) {
                throw new InvalidArgumentException(sprintf('Message codec class "%s" is duplicated.', $class));
            }

            $byAlias[$alias] = $codec;
            $byClass[$class] = $codec;
        }

        $indexedUpcasters = [];
        foreach ($upcasters as $upcaster) {
            $alias = trim($upcaster->alias());
            $fromVersion = $upcaster->fromVersion();
            if (!isset($byAlias[$alias])) {
                throw new InvalidArgumentException(sprintf(
                    'Upcaster references unknown message alias "%s".',
                    $alias,
                ));
            }
            if ($fromVersion < 1) {
                throw new InvalidArgumentException(sprintf(
                    'Upcaster for "%s" must declare a positive source version.',
                    $alias,
                ));
            }
            if (isset($indexedUpcasters[$alias][$fromVersion])) {
                throw new InvalidArgumentException(sprintf(
                    'Upcaster for "%s" version %d is duplicated.',
                    $alias,
                    $fromVersion,
                ));
            }

            $indexedUpcasters[$alias][$fromVersion] = $upcaster;
        }

        foreach ($byAlias as $alias => $codec) {
            $currentVersion = $codec->version();
            for ($fromVersion = 1; $fromVersion < $currentVersion; ++$fromVersion) {
                if (!isset($indexedUpcasters[$alias][$fromVersion])) {
                    throw new InvalidArgumentException(sprintf(
                        'Upcaster chain for "%s" is missing version %d -> %d.',
                        $alias,
                        $fromVersion,
                        $fromVersion + 1,
                    ));
                }
            }

            foreach (array_keys($indexedUpcasters[$alias] ?? []) as $fromVersion) {
                if ($fromVersion >= $currentVersion) {
                    throw new InvalidArgumentException(sprintf(
                        'Upcaster for "%s" starts at version %d but current codec version is %d.',
                        $alias,
                        $fromVersion,
                        $currentVersion,
                    ));
                }
            }

            if (isset($indexedUpcasters[$alias])) {
                ksort($indexedUpcasters[$alias], SORT_NUMERIC);
            }
        }

        ksort($byAlias, SORT_STRING);
        ksort($byClass, SORT_STRING);
        ksort($indexedUpcasters, SORT_STRING);

        $this->codecsByAlias = $byAlias;
        $this->codecsByClass = $byClass;
        $this->upcasters = $indexedUpcasters;
    }

    /** @param Query<mixed>|Command|Event $message */
    public function encode(Query|Command|Event $message): EncodedMessage
    {
        $codec = $this->codecsByClass[$message::class] ?? throw MessageCodecNotFound::forMessage($message::class);
        $payload = $codec->toPayload($message);

        return new EncodedMessage($codec->alias(), $codec->version(), $payload);
    }

    public function hasAlias(string $alias): bool
    {
        return isset($this->codecsByAlias[$alias]);
    }

    /** @return class-string */
    public function messageClassForAlias(string $alias): string
    {
        return ($this->codecsByAlias[$alias] ?? throw MessageCodecNotFound::forAlias($alias))->messageClass();
    }

    public function aliasForMessage(string $messageClass): ?string
    {
        $codec = $this->codecsByClass[$messageClass] ?? null;

        return $codec?->alias();
    }

    /** @return Query<mixed>|Command|Event */
    public function decode(string $alias, int $version, JsonValue $payload): Query|Command|Event
    {
        $codec = $this->codecsByAlias[$alias] ?? throw MessageCodecNotFound::forAlias($alias);
        $currentVersion = $codec->version();
        if ($version < 1 || $version > $currentVersion) {
            throw new UnsupportedMessageVersion($alias, $version, $currentVersion);
        }
        while ($version < $currentVersion) {
            $upcaster = $this->upcasters[$alias][$version] ?? throw new UnsupportedMessageVersion(
                $alias,
                $version,
                $currentVersion,
            );
            $payload = $upcaster->upcast($payload);
            ++$version;
        }

        $message = $codec->fromPayload($payload);
        $messageClass = $codec->messageClass();
        if (!$message instanceof $messageClass) {
            throw new InvalidMessagePayload(sprintf(
                'Message codec "%s" returned "%s" instead of "%s".',
                $alias,
                $message::class,
                $messageClass,
            ));
        }

        return $message;
    }
}
