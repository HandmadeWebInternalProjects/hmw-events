<?php

namespace HMWEvents\Interfaces;

interface NewsletterInterface
{
  public function addSubscriber(array $user, array $list_ids = []): mixed;
  public function removeSubscriber(array $user, array $list_ids = []): void;
  public function getSubscriberCount($list = null): int;
  public function getLists(): array;
}
