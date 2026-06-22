<?php

namespace HMWEvents;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;


\Breakdance\ElementStudio\registerElementForEditing(
    "HMWEvents\\EducatorsByState",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class EducatorsByState extends \Breakdance\Elements\Element
{
    static function uiIcon()
    {
        return 'SquareIcon';
    }

    static function tag()
    {
        return 'div';
    }

    static function tagOptions()
    {
        return [];
    }

    static function tagControlPath()
    {
        return false;
    }

    static function name()
    {
        return 'Educators By State';
    }

    static function className()
    {
        return 'hmwevents-educators-by-state';
    }

    static function category()
    {
        return 'hmw-events';
    }

    static function badge()
    {
        return false;
    }

    static function slug()
    {
        return __CLASS__;
    }

    static function template()
    {
        return file_get_contents(__DIR__ . '/html.twig');
    }

    static function defaultCss()
    {
        return file_get_contents(__DIR__ . '/default.css');
    }

    static function defaultProperties()
    {
        return ['content' => ['box_icon' => ['icon' => ['type' => 'svg', 'svgCode' => '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z"/></svg>']]], 'design' => ['box' => ['background_color' => '#f5f5f5', 'padding' => ['padding' => ['top' => ['number' => 20, 'unit' => 'px'], 'right' => ['number' => 20, 'unit' => 'px'], 'bottom' => ['number' => 20, 'unit' => 'px'], 'left' => ['number' => 20, 'unit' => 'px']]], 'borders' => ['radius' => ['all' => ['number' => 8, 'unit' => 'px']]]], 'hover' => ['background_color' => '#e0e0e0', 'transition' => ['number' => 300, 'unit' => 'ms']], 'columns' => ['desktop' => 3, 'tablet' => 2, 'mobile' => 1, 'gap' => ['number' => 20, 'unit' => 'px']]]];
    }

    static function defaultChildren()
    {
        return false;
    }

    static function cssTemplate()
    {
        $template = file_get_contents(__DIR__ . '/css.twig');
        return $template;
    }

    static function designControls()
    {
        return [c(
        "box",
        "State Box",
        [c(
        "background_color",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "padding",
        "Padding",
        [],
        ['type' => 'spacing_complex', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\borders",
      "Borders",
      "borders",
       ['type' => 'popout']
     )],
        ['type' => 'section', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), c(
        "state_box_hover",
        "State Box Hover",
        [c(
        "background_color",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "transition",
        "Transition Duration",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['ms', 's']]],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\AtomV1IconDesignWithHover",
      "Icon",
      "icon",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\typography",
      "State Text",
      "state_text",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\typography",
      "State Count",
      "state_count",
       ['type' => 'popout']
     )];
    }

    static function contentControls()
    {
        return [c(
        "box_icon",
        "Box Icon",
        [c(
        "icon",
        "Icon",
        [],
        ['type' => 'icon', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      )];
    }

    static function settingsControls()
    {
        return [];
    }

    static function dependencies()
    {
        return ['0' =>  ['scripts' => ['%%BREAKDANCE_REUSABLE_CMS%%/src/Breakdance/elements/Educators_By_State/EducatorsByState.js'],'inlineScripts' => ['function initMap() {
              // Check if window.googlemapinstances is defined, if not, create it
              if (!window.locationMapInstances) {
                window.locationMapInstances = [];
              }  
              
              window.locationMapInstances[%%ID%%] = new window.EducatorsByState(
                document.querySelector("%%SELECTOR%%"), 
                {{ content.content|json_encode() }}
              );
            }

window.addEventListener("DOMContentLoaded", initMap);'],],];
    }

    static function settings()
    {
        return false;
    }

    static function addPanelRules()
    {
        return false;
    }

    static public function actions()
    {
        return false;
    }

    static function nestingRule()
    {
        return ['type' => 'final'];
    }

    static function spacingBars()
    {
        return false;
    }

    static function attributes()
    {
        return false;
    }

    static function experimental()
    {
        return false;
    }

    static function availableIn()
    {
        return ['breakdance'];
    }


    static function order()
    {
        return 0;
    }

    static function dynamicPropertyPaths()
    {
        return false;
    }

    static function additionalClasses()
    {
        return false;
    }

    static function projectManagement()
    {
        return false;
    }

    static function propertyPathsToWhitelistInFlatProps()
    {
        return [''];
    }

    static function propertyPathsToSsrElementWhenValueChanges()
    {
        return ['content.box_icon.icon'];
    }
}
