<?php

namespace HMWEvents\Services\Maps;

use HMWEvents\Interfaces\MapRendererInterface;

defined('ABSPATH') || die('Don\'t run this file directly!');

class MapRendererDispatcher
{
	public function register(): void
	{
		add_action('hmwevents_event_map', [$this, 'render_map']);
	}

	public function render_map(\WP_Post $event): void
	{
		$renderer = $this->resolve_renderer();

		if ($renderer === null || !$renderer->is_available()) {
			return;
		}

		$allowed = array_merge(wp_kses_allowed_html('post'), [
			'iframe' => [
				'src'             => true,
				'width'           => true,
				'height'          => true,
				'style'           => true,
				'loading'         => true,
				'allowfullscreen' => true,
				'referrerpolicy'  => true,
				'frameborder'     => true,
			],
		]);

		echo wp_kses($renderer->render($event), $allowed);
	}

	private function resolve_renderer(): ?MapRendererInterface
	{
		$override = apply_filters('hmwevents_map_renderer', null);

		if ($override instanceof MapRendererInterface) {
			return $override;
		}

		$provider = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_map_provider', 'none');

		return match ($provider) {
			'google_maps' => new GoogleMapsRenderer(),
			default => null,
		};
	}
}
