<?php

namespace HMWEvents\Breakdance;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;


\Breakdance\ElementStudio\registerElementForEditing(
    "HMWEvents\Breakdance\\EducatorUpcomingCourses",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class EducatorUpcomingCourses extends \Breakdance\Elements\Element
{
    static function uiIcon()
    {
        return 'ThumbsUpIcon';
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
        return 'Educator Upcoming Courses';
    }

    static function className()
    {
        return 'bde-educator-upcoming-courses';
    }

    static function category()
    {
        return 'blocks';
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
        return ['design' => ['size' => ['space_between' => ['number' => 6, 'unit' => 'px', 'style' => '6px']], 'icons' => ['size' => ['breakpoint_base' => ['number' => 24, 'unit' => 'px', 'style' => '24px']], 'padding' => ['breakpoint_base' => ['number' => 10, 'unit' => 'px', 'style' => '10px']], 'shape' => '0', 'margin' => ['breakpoint_base' => ['number' => 8, 'unit' => 'px', 'style' => '8px']]], 'layout' => ['width' => null, 'direction' => ['breakpoint_base' => 'row']], 'hover' => ['scale' => 'undefined'], 'effect' => ['opacity' => 1, 'opacity_hover' => 0.8, 'scale_on_hover' => 1.1, 'transition_duration' => null]], 'content' => ['content' => ['social_networks' => [['link' => ['type' => 'url', 'url' => 'https://www.facebook.com/'], 'type' => 'bde-social-icons__icon-facebook'], ['type' => 'bde-social-icons__icon-twitter', 'link' => ['type' => 'url', 'url' => 'https://twitter.com/']], ['type' => 'bde-social-icons__icon-instagram', 'link' => ['type' => 'url', 'url' => 'https://www.instagram.com/']], ['type' => 'bde-social-icons__icon-linkedin', 'link' => ['type' => 'url', 'url' => 'http://linkedin.com/']], ['type' => 'bde-social-icons__icon-youtube', 'link' => ['type' => 'url', 'url' => 'https://www.youtube.com/']]]]]];
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
        "card",
        "Card",
        [c(
        "direction",
        "Direction",
        [],
        ['type' => 'button_bar', 'layout' => 'inline', 'items' => [['value' => 'row', 'text' => 'Horizontal', 'icon' => 'EllipsisIcon'], ['value' => 'column', 'text' => 'Horizontal', 'icon' => 'EllipsisVerticalIcon']], 'buttonBarOptions' => ['size' => 'small']],
        true,
        false,
        [],
        
      ), c(
        "space_between",
        "Space Between",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['step' => 1, 'min' => 0, 'max' => 128], 'unitOptions' => ['types' => ['px', 'rem', 'em', 'custom', 'calc', '%', 'vw', 'vh'], 'defaultType' => 'px']],
        true,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\borders",
      "Borders",
      "borders",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\spacing_padding_all",
      "Padding (All)",
      "spacing_padding_all",
       ['type' => 'popout']
     )],
        ['type' => 'section'],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\AtomV1IconDesignWithHover",
      "Accordion Icon",
      "accordion_icon",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\AtomV1IconDesignWithHover",
      "Date Icon",
      "date_icon",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\AtomV1IconDesignWithHover",
      "Location Icon",
      "location_icon",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\AtomV1IconDesignWithHover",
      "Notes Icon",
      "notes_icon",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\AtomV1IconDesignWithHover",
      "Course Availability Icon",
      "course_availability_icon",
       ['type' => 'popout']
     )];
    }

    static function contentControls()
    {
        return [c(
        "icons",
        "Icons",
        [c(
        "accordion",
        "Accordion",
        [],
        ['type' => 'icon', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), c(
        "dates",
        "Dates",
        [],
        ['type' => 'icon', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), c(
        "location",
        "Location",
        [],
        ['type' => 'icon', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), c(
        "notes",
        "Notes",
        [],
        ['type' => 'icon', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), c(
        "course_availability",
        "Course Availability",
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
        return ['0' =>  ['scripts' => ['%%BREAKDANCE_REUSABLE_CMS%%/src/Breakdance/elements/Educator_Upcoming_Courses/EducatorUpcomingCourses.js'],'inlineScripts' => ['function initUpcomingCourses() {
              // Check if window.googlemapinstances is defined, if not, create it
              if (!window.educatorCourseInstances) {
                window.educatorCourseInstances = [];
              }  
              
              window.educatorCourseInstances[%%ID%%] = new window.EducatorUpcomingCourses(
                document.querySelector("%%SELECTOR%%"), 
                {{ content.content|json_encode() }}
              );
            }

window.addEventListener("DOMContentLoaded", initUpcomingCourses);'],],];
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
        return [['location' => 'outside-top', 'cssProperty' => 'margin-top', 'affectedPropertyPath' => 'design.spacing.margin_top.%%BREAKPOINT%%'], ['location' => 'outside-bottom', 'cssProperty' => 'margin-bottom', 'affectedPropertyPath' => 'design.spacing.margin_bottom.%%BREAKPOINT%%']];
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
        return 750;
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
        return ['looksGood' => 'yes', 'optionsGood' => 'yes', 'optionsWork' => 'yes'];
    }

    static function propertyPathsToWhitelistInFlatProps()
    {
        return false;
    }

    static function propertyPathsToSsrElementWhenValueChanges()
    {
        return false;
    }
}
