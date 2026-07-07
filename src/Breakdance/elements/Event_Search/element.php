<?php

namespace HMWEvents;

use function Breakdance\Elements\c;
use function Breakdance\Elements\PresetSections\getPresetSection;


\Breakdance\ElementStudio\registerElementForEditing(
    "HMWEvents\\EducatorSearch",
    \Breakdance\Util\getdirectoryPathRelativeToPluginFolder(__DIR__)
);

class EventSearch extends \Breakdance\Elements\Element
{
    static function uiIcon()
    {
        return 'SearchIcon';
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
        return 'Educator Search';
    }

    static function className()
    {
        return 'event-search-form';
    }

    static function category()
    {
        return 'site';
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
        return ['design' => ['form' => ['size' => null, 'classic_styles' => null, 'style' => 'full-screen', 'expand_styles' => null, 'full_screen_styles' => ['open_button' => ['background' => '#e7e5e4', 'background_hover' => '#d6d3d1']]]], 'content' => ['form' => ['placeholder' => 'Search']]];
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
        "form",
        "Form",
        [c(
        "style",
        "Style",
        [],
        ['type' => 'dropdown', 'layout' => 'inline', 'items' => [['value' => 'classic', 'text' => 'Classic'], ['text' => 'Full Screen', 'value' => 'full-screen']]],
        false,
        false,
        [],
        
      ), c(
        "classic_styles",
        "Classic Styles",
        [c(
        "padding",
        "Padding",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 0, 'max' => 36, 'step' => 1]],
        true,
        false,
        [],
        
      ), c(
        "background",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline', 'colorOptions' => ['type' => 'solidAndGradient']],
        false,
        false,
        [],
        
      ), c(
        "placeholder",
        "Placeholder",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\borders",
      "Borders",
      "borders",
       ['type' => 'popout']
     ), getPresetSection(
      "EssentialElements\\typography",
      "Typography",
      "typography",
       ['type' => 'popout']
     ), c(
        "focused",
        "Focused",
        [c(
        "background",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "border",
        "Border",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "shadow",
        "Shadow",
        [],
        ['type' => 'shadow', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'inline', 'sectionOptions' => ['type' => 'popout']],
        false,
        false,
        [],
        
      ), c(
        "icon_button",
        "Icon Button",
        [c(
        "type",
        "Type",
        [],
        ['type' => 'button_bar', 'layout' => 'inline', 'items' => [['text' => 'Custom', 'value' => 'custom'], ['text' => 'Text', 'value' => 'text']], 'buttonBarOptions' => ['layout' => 'default', 'size' => 'small']],
        false,
        false,
        [],
        
      ), c(
        "icon",
        "Icon",
        [],
        ['type' => 'icon', 'layout' => 'vertical', 'condition' => ['path' => 'design.form.classic_styles.icon_button.type', 'operand' => 'equals', 'value' => 'custom']],
        false,
        false,
        [],
        
      ), c(
        "padding",
        "Padding",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 4, 'max' => 24, 'step' => 1], 'condition' => ['path' => 'design.form.classic_styles.icon_button.type', 'operand' => 'equals', 'value' => 'text']],
        false,
        false,
        [],
        
      ), c(
        "text",
        "Text",
        [],
        ['type' => 'text', 'layout' => 'vertical', 'condition' => ['path' => 'design.form.classic_styles.icon_button.type', 'operand' => 'equals', 'value' => 'text']],
        false,
        false,
        [],
        
      ), c(
        "position",
        "Position",
        [],
        ['type' => 'button_bar', 'layout' => 'inline', 'items' => [['text' => 'Left', 'value' => 'after'], ['text' => 'Right', 'value' => 'right']]],
        false,
        false,
        [],
        
      ), c(
        "size",
        "Size",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'condition' => ['path' => 'design.form.classic_styles.icon_button.type', 'operand' => 'not equals', 'value' => 'text']],
        true,
        false,
        [],
        
      ), c(
        "background",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        true,
        [],
        
      ), c(
        "color",
        "Color",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'inline', 'sectionOptions' => ['type' => 'popout']],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'sectionOptions' => ['type' => 'popout'], 'condition' => ['path' => 'design.form.style', 'operand' => 'equals', 'value' => 'classic']],
        false,
        false,
        [],
        
      ), c(
        "full_screen_styles",
        "Full Screen Styles",
        [c(
        "open_button",
        "Open Button",
        [c(
        "size",
        "Size",
        [],
        ['type' => 'unit', 'layout' => 'inline'],
        true,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\borders",
      "Borders",
      "borders",
       ['type' => 'popout']
     ), c(
        "background",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        true,
        [],
        
      ), c(
        "color",
        "Color",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'inline', 'sectionOptions' => ['type' => 'popout']],
        false,
        false,
        [],
        
      ), c(
        "search_box",
        "Search Box",
        [c(
        "width",
        "Width",
        [],
        ['type' => 'unit', 'layout' => 'inline'],
        true,
        false,
        [],
        
      ), c(
        "height",
        "Height",
        [],
        ['type' => 'unit', 'layout' => 'inline'],
        true,
        false,
        [],
        
      ), c(
        "background",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline', 'colorOptions' => ['type' => 'solidAndGradient']],
        false,
        false,
        [],
        
      ), c(
        "placeholder",
        "Placeholder",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\typography",
      "Typography",
      "typography",
       ['type' => 'popout']
     ), c(
        "icon",
        "Icon",
        [c(
        "hide",
        "Hide",
        [],
        ['type' => 'toggle', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "position",
        "Position",
        [],
        ['type' => 'button_bar', 'layout' => 'inline', 'items' => [['text' => 'Left', 'value' => 'left'], ['text' => 'Right', 'value' => 'right']]],
        false,
        false,
        [],
        
      ), c(
        "color",
        "Color",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "size",
        "Size",
        [],
        ['type' => 'unit', 'layout' => 'inline'],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'inline', 'sectionOptions' => ['type' => 'popout']],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\borders",
      "Borders",
      "borders",
       ['type' => 'popout']
     ), c(
        "focused",
        "Focused",
        [c(
        "background",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "border",
        "Border",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "shadow",
        "Shadow",
        [],
        ['type' => 'shadow', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'inline', 'sectionOptions' => ['type' => 'popout']],
        false,
        false,
        [],
        
      ), c(
        "padding",
        "Padding",
        [],
        ['type' => 'spacing_complex', 'layout' => 'vertical'],
        true,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'inline', 'sectionOptions' => ['type' => 'popout']],
        false,
        false,
        [],
        
      ), c(
        "close_icon",
        "Close Icon",
        [c(
        "color",
        "Color",
        [],
        ['type' => 'color', 'layout' => 'inline'],
        false,
        false,
        [],
        
      ), c(
        "size",
        "Size",
        [],
        ['type' => 'unit', 'layout' => 'inline'],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'layout' => 'inline', 'sectionOptions' => ['type' => 'popout']],
        false,
        false,
        [],
        
      ), c(
        "background",
        "Background",
        [],
        ['type' => 'color', 'layout' => 'inline', 'colorOptions' => ['type' => 'solidAndGradient']],
        false,
        false,
        [],
        
      )],
        ['type' => 'section', 'sectionOptions' => ['type' => 'popout'], 'condition' => ['path' => 'design.form.style', 'operand' => 'equals', 'value' => 'full-screen']],
        false,
        false,
        [],
        
      ), c(
        "width",
        "Width",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'condition' => ['path' => 'design.form.style', 'operand' => 'is none of', 'value' => ['full-screen']]],
        true,
        false,
        [],
        
      ), c(
        "height",
        "Height",
        [],
        ['type' => 'unit', 'layout' => 'inline', 'rangeOptions' => ['min' => 20, 'max' => 100, 'step' => 1]],
        true,
        false,
        [],
        
      ), c(
        "padding",
        "Padding",
        [],
        ['type' => 'spacing_complex', 'layout' => 'vertical', 'rangeOptions' => ['min' => 20, 'max' => 100, 'step' => 1]],
        true,
        false,
        [],
        
      )],
        ['type' => 'section'],
        false,
        false,
        [],
        
      ), getPresetSection(
      "EssentialElements\\spacing_margin_y",
      "Spacing",
      "spacing",
       ['type' => 'popout']
     )];
    }

    static function contentControls()
    {
        return [c(
        "form",
        "Form",
        [c(
        "placeholder",
        "Placeholder",
        [],
        ['type' => 'text', 'layout' => 'vertical'],
        false,
        false,
        [],
        ['accepts' => 'string']
      ), c(
        "icon",
        "Icon",
        [],
        ['type' => 'icon', 'layout' => 'vertical'],
        false,
        false,
        [],
        
      ), c(
        "restrict_search_to_course_type",
        "Restrict Search To Course Type",
        [],
        ['type' => 'dropdown', 'layout' => 'vertical', 'dropdownOptions' => ['populate' => ['path' => '', 'text' => '', 'value' => '', 'fetchDataAction' => 'hmwevents_get_course_types', 'fetchContextPath' => '', 'refetchPaths' => []]]],
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
        return ['0' =>  ['scripts' => ['%%BREAKDANCE_ELEMENTS_PLUGIN_URL%%dependencies-files/breakdance-search-form@1/search-form.js'],'title' => 'Breakdance Search Form',],'1' =>  ['inlineScripts' => ['// Define the country selector init function globally so actions can re-init
if (typeof window.initEducatorSearchCountrySelector === \'undefined\') {
  window.initEducatorSearchCountrySelector = function(selector) {
    var form = document.querySelector(selector + \' .js-search-form\');
    if (!form) return;

    // Parse SSR data from data-ssr attribute
    var ssrData = { countries: [], currentCountry: \'AU\', flagsUrl: \'\', search: \'\' };
    try {
      ssrData = JSON.parse(form.getAttribute(\'data-ssr\') || \'{}\');
    } catch(e) {}

    // Restore search value
    var searchInput = form.querySelector(\'.js-search-form-field\');
    if (searchInput && ssrData.search) {
      searchInput.value = ssrData.search;
    }

    // Add hidden country input
    var countryInput = form.querySelector(\'input[name="country"]\');
    if (!countryInput) {
      countryInput = document.createElement(\'input\');
      countryInput.type = \'hidden\';
      countryInput.name = \'country\';
      countryInput.value = ssrData.currentCountry || \'AU\';
      form.appendChild(countryInput);
    }

    // Build country selector if we have countries
    if (!ssrData.countries || !ssrData.countries.length) return;

    var container = form.querySelector(\'.search-form__container\');
    if (!container) return;

    // Remove any existing country selector
    var existing = container.querySelector(\'.search-form__country-wrap\');
    if (existing) existing.remove();

    var flagsUrl = ssrData.flagsUrl || \'\';
    var currentCountry = ssrData.currentCountry || \'AU\';

    var wrap = document.createElement(\'div\');
    wrap.className = \'search-form__country-wrap\';

    var btn = document.createElement(\'button\');
    btn.type = \'button\';
    btn.className = \'search-form__country-btn\';
    btn.setAttribute(\'aria-label\', \'Select country\');
    btn.setAttribute(\'aria-expanded\', \'false\');
    var btnImg = document.createElement(\'img\');
    btnImg.src = flagsUrl + currentCountry.toLowerCase() + \'.svg\';
    btnImg.alt = currentCountry;
    btnImg.className = \'search-form__country-flag\';
    btn.appendChild(btnImg);

    var dropdown = document.createElement(\'div\');
    dropdown.className = \'search-form__country-dropdown\';
    var ul = document.createElement(\'ul\');

    ssrData.countries.forEach(function(c) {
      var li = document.createElement(\'li\');
      li.className = \'search-form__country-item\' + (c.shortcode === currentCountry ? \' is-active\' : \'\');
      li.setAttribute(\'data-country\', c.shortcode);
      var flagImg = document.createElement(\'img\');
      flagImg.src = flagsUrl + c.shortcode.toLowerCase() + \'.svg\';
      flagImg.alt = \'\';
      flagImg.className = \'search-form__country-flag\';
      var span = document.createElement(\'span\');
      span.textContent = c.name;
      li.appendChild(flagImg);
      li.appendChild(span);
      li.addEventListener(\'click\', function(e) {
        e.stopPropagation();
        countryInput.value = c.shortcode;
        btnImg.src = flagsUrl + c.shortcode.toLowerCase() + \'.svg\';
        btnImg.alt = c.shortcode;
        dropdown.querySelectorAll(\'.search-form__country-item\').forEach(function(el) { el.classList.remove(\'is-active\'); });
        li.classList.add(\'is-active\');
        dropdown.classList.remove(\'is-open\');
        btn.setAttribute(\'aria-expanded\', \'false\');
      });
      ul.appendChild(li);
    });

    dropdown.appendChild(ul);
    wrap.appendChild(btn);
    wrap.appendChild(dropdown);

    // Insert before the submit button
    var searchBtn = container.querySelector(\'.search-form__lightbox-button, .search-form__button\');
    if (searchBtn) {
      container.insertBefore(wrap, searchBtn);
    } else {
      container.appendChild(wrap);
    }

    // Toggle dropdown on button click
    btn.addEventListener(\'click\', function(e) {
      e.preventDefault();
      e.stopPropagation();
      var isOpen = dropdown.classList.toggle(\'is-open\');
      btn.setAttribute(\'aria-expanded\', isOpen ? \'true\' : \'false\');
    });

    // Close dropdown on outside click
    document.addEventListener(\'click\', function(e) {
      if (!wrap.contains(e.target)) {
        dropdown.classList.remove(\'is-open\');
        btn.setAttribute(\'aria-expanded\', \'false\');
      }
    });
  };
}'],'title' => 'Country selector helpers',],'2' =>  ['inlineScripts' => ['new BreakdanceSearchForm(\'%%SELECTOR%%\', {});
if (typeof window.initEducatorSearchCountrySelector === \'function\') { window.initEducatorSearchCountrySelector(\'%%SELECTOR%%\'); }'],'builderCondition' => 'return false;','title' => 'Frontend init',],];
    }

    static function settings()
    {
        return ['proOnly' => true];
    }

    static function addPanelRules()
    {
        return false;
    }

    static public function actions()
    {
        return [

'onMountedElement' => [['script' => '(if (!window.breakdanceSearchFormInstances) window.breakdanceSearchFormInstances = {};
if (window.breakdanceSearchFormInstances && window.breakdanceSearchFormInstances[%%ID%%]) {
  window.breakdanceSearchFormInstances[%%ID%%].destroy();
}
window.breakdanceSearchFormInstances[%%ID%%] = new BreakdanceSearchForm(\'%%SELECTOR%%\', {});
if (typeof window.initEducatorSearchCountrySelector === \'function\') {
  window.initEducatorSearchCountrySelector(\'%%SELECTOR%%\');
})();',
],],

'onPropertyChange' => [['script' => '(if (!window.breakdanceSearchFormInstances) window.breakdanceSearchFormInstances = {};
if (window.breakdanceSearchFormInstances && window.breakdanceSearchFormInstances[%%ID%%]) {
  window.breakdanceSearchFormInstances[%%ID%%].destroy();
}
window.breakdanceSearchFormInstances[%%ID%%] = new BreakdanceSearchForm(\'%%SELECTOR%%\', {});
if (typeof window.initEducatorSearchCountrySelector === \'function\') {
  window.initEducatorSearchCountrySelector(\'%%SELECTOR%%\');
})();',
],],

'onBeforeDeletingElement' => [['script' => '(function() {
if (window.breakdanceSearchFormInstances && window.breakdanceSearchFormInstances[%%ID%%]) {
  window.breakdanceSearchFormInstances[%%ID%%].destroy();
  delete window.breakdanceSearchFormInstances[%%ID%%];
}
}());',
],],

'onMovedElement' => [['script' => '(if (!window.breakdanceSearchFormInstances) window.breakdanceSearchFormInstances = {};
if (window.breakdanceSearchFormInstances && window.breakdanceSearchFormInstances[%%ID%%]) {
  window.breakdanceSearchFormInstances[%%ID%%].destroy();
}
window.breakdanceSearchFormInstances[%%ID%%] = new BreakdanceSearchForm(\'%%SELECTOR%%\', {});
if (typeof window.initEducatorSearchCountrySelector === \'function\') {
  window.initEducatorSearchCountrySelector(\'%%SELECTOR%%\');
})();',
],],];
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
        return 100;
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
        return false;
    }

    static function propertyPathsToSsrElementWhenValueChanges()
    {
        return false;
    }
}
