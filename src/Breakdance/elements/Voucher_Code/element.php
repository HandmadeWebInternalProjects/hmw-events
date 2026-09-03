<?php

namespace HMWEvents;

defined('ABSPATH') || die('Don\'t run this file directly!');

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;


\Breakdance\ElementStudio\registerElementForEditing(
    "HMWEvents\\VoucherCode",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class VoucherCode extends \Breakdance\Elements\Element
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
        return 'Voucher Code';
    }

    static function className()
    {
        return 'hmwevents-voucher-code';
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
        return ['content' => ['content' => ['course_id_source' => 'url', 'input_placeholder' => 'Enter voucher code (optional)', 'button_text' => 'Apply Voucher', 'apply_to_field' => 'is_deposit'], 'design' => ['input_style' => ['width' => ['number' => 100, 'unit' => '%', 'style' => '100%']], 'message_style' => ['success_color' => '#10b981', 'error_color' => '#ef4444']]]];
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
        return [getPresetSection(
      "EssentialElements\\spacing_padding_all",
      "Container Padding",
      "container_padding",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\spacing_margin_y",
      "Container Margin",
      "container_margin",
       ['type' => 'popout']
     ), c(
        "input_style",
        "Input Style",
        [c(
        "width",
        "Width",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'unitOptions' => ['types' => ['px', '%', 'em'], 'defaultType' => '%']],
        true,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\typography",
      "Typography",
      "typography",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\borders",
      "Borders",
      "borders",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\spacing_padding_all",
      "Padding",
      "padding",
       ['type' => 'popout']
     )],
        ['type' => 'section', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\AtomV1ButtonDesign",
      "Button",
      "button",
       ['type' => 'popout']
     ), c(
        "message_style",
        "Message Style",
        [getPresetSection(
      "EssentialElements\\typography",
      "Typography",
      "typography",
       ['type' => 'popout']
     ), c(
        "success_color",
        "Success Color",
        [],
        ['type' => 'color', 'layout' => 'inline', 'colorOptions' => ['type' => 'solidAndGradient']],
        false,
        false,
        [],
        
      ), c(
        "error_color",
        "Error Color",
        [],
        ['type' => 'color', 'layout' => 'inline', 'colorOptions' => ['type' => 'solidAndGradient']],
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

    static function contentControls()
    {
        return [c(
        "content",
        "Content",
        [c(
        "course_id_source",
        "Course ID Source",
        [],
        ['type' => 'dropdown', 'layout' => 'vertical', 'items' => [['value' => 'url', 'text' => 'URL Parameter (course_id)'], ['value' => 'custom', 'text' => 'Custom/Manual']]],
        false,
        false,
        [],
        
      ), c(
        "custom_course_id",
        "Course ID",
        [],
        ['type' => 'number', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), c(
        "input_placeholder",
        "Input Placeholder",
        [],
        ['type' => 'text', 'layout' => 'vertical', 'textOptions' => ['multiline' => false]],
        false,
        false,
        [],
        
      ), c(
        "button_text",
        "Button Text",
        [],
        ['type' => 'text', 'layout' => 'vertical', 'textOptions' => ['multiline' => false]],
        false,
        false,
        [],
      ), c(
        "payment_type_field_name",
        "Payment Type Field Name",
        [],
        ['type' => 'text', 'layout' => 'vertical', 'textOptions' => ['multiline' => false]],
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
        return ['0' =>  ['scripts' => ['%%BREAKDANCE_REUSABLE_CMS%%/src/Breakdance/elements/Voucher_Code/VoucherCode.js'],'inlineScripts' => ['new window.HMWEventsVoucherCode(document.querySelector("%%SELECTOR%%"));'],],];
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

    static function order()
    {
        return 0;
    }

    static function dynamicPropertyPaths()
    {
        return [['accepts' => 'string', 'path' => 'content.content.button_text']];
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
        return ['design.button.styles'];
    }

    static function propertyPathsToSsrElementWhenValueChanges()
    {
        return ['content.content,course_id_source', 'content.content.custom_course_id', 'content.content.input_placeholder', 
        'content.content.button_text', 'content.content.apply_to_field', 'design.button'];
    }
}
