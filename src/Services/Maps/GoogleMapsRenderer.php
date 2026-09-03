<?php

namespace HMWEvents\Services\Maps;

use HMWEvents\Helpers\GoogleMapField;
use HMWEvents\Interfaces\MapRendererInterface;

defined('ABSPATH') || die('Don\'t run this file directly!');

class GoogleMapsRenderer implements MapRendererInterface
{
    private ?\HMWEvents\Services\EventDataService $event_data_service = null;

    private function event_data(): \HMWEvents\Services\EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new \HMWEvents\Services\EventDataService();
        }
        return $this->event_data_service;
    }

    public function is_available(): bool
    {
        return (bool) \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_google_maps_api_key');
    }

    public function get_renderer_id(): string
    {
        return 'google_maps';
    }

    public function get_renderer_name(): string
    {
        return __('Google Maps', 'hmw-events');
    }

    public function render(\WP_Post $event): string
    {
        $eds        = $this->event_data();
        $venue_name = $eds->get_venue_name($event->ID);
        $map_field  = $eds->get_venue_address($event->ID);

		$address = GoogleMapField::get_address_string($map_field);
		$lat     = GoogleMapField::get_lat($map_field);
		$lng     = GoogleMapField::get_lng($map_field);

		if (empty($address) && empty($venue_name)) {
			return '';
		}

		$api_key = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_google_maps_api_key');
		$query   = ($lat && $lng) ? "{$lat},{$lng}" : trim($venue_name . ', ' . $address, ', ');
		$src     = esc_url("https://www.google.com/maps/embed/v1/place?key={$api_key}&q=" . urlencode($query) . "&zoom=15");

		ob_start();
		?>
		<div class="hmwevents-event-map">
			<iframe
				width="100%"
				height="300"
				style="border:0;"
				loading="lazy"
				allowfullscreen
				referrerpolicy="no-referrer-when-downgrade"
				src="<?php echo $src; ?>"
			></iframe>
		</div>
		<?php
		return ob_get_clean();
	}
}
