<?php

namespace HMWEvents\Interfaces;

defined('ABSPATH') || die('Don\'t run this file directly!');

interface MapRendererInterface
{
	public function render(\WP_Post $event): string;

	public function is_available(): bool;

	public function get_renderer_id(): string;

	public function get_renderer_name(): string;
}
