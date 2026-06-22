<?php

namespace HMWEvents\Providers\Newsletter;

use HMWEvents\Interfaces\NewsletterInterface;

class MailpoetProvider implements NewsletterInterface
{
  protected $mailpoet_api;

  public function __construct()
  {
    add_action('init', function () {
      if (class_exists(\MailPoet\API\API::class)) {
        $this->mailpoet_api = \MailPoet\API\API::MP('v1');
      } else {
        // throw new \Exception('MailPoet API not found');
      }
    });
  }

  public function addSubscriber(array $user, array $list_ids = []): mixed
  {
    // Code to add a subscriber to the newsletter provider
    if (!$this->mailpoet_api) {
      return null;
    }

    if (!empty($user)) {
      try {
        $get_subscriber = $this->mailpoet_api->getSubscriber($user['email']);
      } catch (\Exception $e) {
      }

      try {
        if (!$get_subscriber) {
          // Subscriber doesn't exist let's create one
          $subscriber_id =  $this->mailpoet_api->addSubscriber($user, $list_ids);
        } else {
          // In case subscriber exists just add them to new lists
          $subscriber_id =  $this->mailpoet_api->subscribeToLists($user['email'], $list_ids);
        }
      } catch (\Exception $e) {
        return new \WP_REST_Response($e->getMessage(), 400);
      }

      return $subscriber_id;
    }
  }

  public function removeSubscriber(array $email, array $list_ids = []): void
  {
    if (!$this->mailpoet_api) {
      return;
    }
    // Code to remove a subscriber from the newsletter provider
    $subscriber_id = $this->mailpoet_api->getSubscriber($email);
    if ($subscriber_id && isset($list_ids)) {
      try {
        $this->mailpoet_api->unSubscribeFromLists($subscriber_id, $list_ids);
      } catch (\Exception $e) {
        // Do nothing
      }
    }
  }

  public function getSubscriberCount($list = null): int
  {
    if (!$this->mailpoet_api) {
      return 0;
    }

    return $this->mailpoet_api->getSubscribersCount();
  }

  public function getLists(): array
  {
    if (!$this->mailpoet_api) {
      return [];
    }
    return $this->mailpoet_api->getLists();
  }
}
