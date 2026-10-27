<?php

namespace Tests\Feature\Push;

use App\Notifications\AppNotification;
use App\Notifications\NotificationType;

/**
 * Notification de test d'un type quelconque, envoyée par sendOnce comme les
 * notifications réelles.
 */
final class TestPushNotification extends AppNotification
{
    public function __construct(
        public NotificationType $kind,
        public string $key,
        public string $titleText,
        public string $bodyText,
        public ?string $link,
    ) {}

    public function type(): NotificationType
    {
        return $this->kind;
    }

    public function title(): string
    {
        return $this->titleText;
    }

    public function body(): string
    {
        return $this->bodyText;
    }

    public function url(): ?string
    {
        return $this->link;
    }

    protected function uniqueKey(): string
    {
        return 'test:'.$this->kind->value.':'.$this->key;
    }
}
