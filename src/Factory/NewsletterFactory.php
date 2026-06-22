<?php

namespace HMWEvents\Factory;

use HMWEvents\Services\NewsletterService;

class NewsletterFactory
{
  public static function make()
  {
    $provider = bs_get_field('newsletter_provider', 'option') ?? 'mailpoet';
    $provider_class = 'HMWEvents\\Providers\\Newsletter\\' . ucfirst($provider) . 'Provider';
    return new NewsletterService(new $provider_class());
  }
}
