<?php

namespace HMWEvents\Services;

use HMWEvents\Interfaces\NewsletterInterface;

class NewsletterService
{
  protected NewsletterInterface $provider;

  public function __construct(NewsletterInterface $provider)
  {
    $this->provider = $provider;
  }

  public function addSubscriber(array $user, array $list_ids = [])
  {
    return $this->provider->addSubscriber($user, $list_ids);
  }

  public function removeSubscriber(array $user, array $list_ids = [])
  {
    $this->provider->removeSubscriber($user, $list_ids);
  }

  public function getSubscriberCount($list = null)
  {
    $this->provider->getSubscriberCount($list);
  }

  public function getLists()
  {
    return $this->provider->getLists();
  }
}
