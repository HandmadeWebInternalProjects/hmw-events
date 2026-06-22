<?php

namespace HMWEvents\Traits;

trait HasComponents
{
    abstract public static function get_components(): array;

    protected function register_components()
    {
        foreach (self::get_components() as $component) {
            // Check if the component has a static init method, call it and move on
            if (method_exists($component, 'init')) {
                $component::init();
                continue;
            }

            // Otherwise, instantiate the component and check if it has a register method
            $component = new $component();
            if (method_exists($component, 'register')) {
                $component->register();
            }
        }
    }
}
