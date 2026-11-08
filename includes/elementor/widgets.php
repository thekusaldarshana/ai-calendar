<?php
defined('ABSPATH') || exit;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;

/** True inside the Elementor editor and its preview, where assets must be declared up front. */
function wpaical_el_editing() {
    $p = \Elementor\Plugin::$instance;
    return ($p->editor && $p->editor->is_edit_mode()) || ($p->preview && $p->preview->is_preview_mode());
}

/** Join Elementor's SELECT2 array (or a plain string) into the comma list the shortcodes use. */
function wpaical_el_list($value) {
    return is_array($value) ? implode(',', array_filter($value, 'strlen')) : (string) $value;
}

class WPAICal_El_Upcoming extends Widget_Base {
    public function get_name() { return 'wpaical_upcoming'; }
    public function get_title() { return 'AI Event Cards'; }
    public function get_icon() { return 'eicon-posts-grid'; }
    public function get_categories() { return ['wpaical']; }
    public function get_keywords() { return ['events', 'calendar', 'cards', 'upcoming', 'program']; }
    public function get_style_depends() { return wpaical_el_editing() ? ['wpaical-widget'] : []; }
    public function get_script_depends() { return wpaical_el_editing() ? ['wpaical-widget', 'wpaical-elementor'] : []; }

    protected function register_controls() {
        $this->start_controls_section('content', ['label' => 'Events']);
        $this->add_control('program', [
            'label' => 'Program', 'type' => Controls_Manager::SELECT2, 'multiple' => true, 'label_block' => true,
            'options' => WPAICal_Elementor::program_options(),
            'description' => 'Show events whose title starts with this program. Leave empty to show every event. Edit pictures, links and date-less cards under AI Calendar → Program cards.',
        ]);
        $this->add_control('program_custom', ['label' => 'Or type a program', 'type' => Controls_Manager::TEXT, 'label_block' => true, 'description' => 'Comma separated, in addition to the list above.']);
        $this->add_control('match', ['label' => 'Match title', 'type' => Controls_Manager::SELECT, 'default' => 'prefix', 'options' => ['prefix' => 'Starts with', 'exact' => 'Exactly', 'contains' => 'Contains']]);
        $this->add_control('category', ['label' => 'Only category', 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => ['' => 'Any'] + WPAICal_Elementor::category_options()]);
        $this->add_control('limit', ['label' => 'Maximum cards', 'type' => Controls_Manager::NUMBER, 'default' => 10, 'min' => 1, 'max' => 50]);
        $this->add_control('days', ['label' => 'Look ahead (days)', 'type' => Controls_Manager::NUMBER, 'default' => 365, 'min' => 1, 'max' => 730]);
        $this->add_control('series', ['label' => 'Recurring events', 'type' => Controls_Manager::SELECT, 'default' => 'all', 'options' => ['all' => 'Every date', 'next' => 'Only the next date of each series']]);
        $this->end_controls_section();

        $this->start_controls_section('text', ['label' => 'Text']);
        $this->add_control('heading', ['label' => 'Heading', 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => 'Upcoming Events']);
        $this->add_control('lead', ['label' => 'Sub-heading', 'type' => Controls_Manager::TEXT, 'label_block' => true]);
        $this->add_control('button', ['label' => 'Button text', 'type' => Controls_Manager::TEXT, 'default' => 'Details']);
        $this->add_control('empty', ['label' => 'When there are no events', 'type' => Controls_Manager::TEXT, 'label_block' => true, 'default' => 'No upcoming events right now. Please check back soon.']);
        $this->end_controls_section();

        $this->start_controls_section('style', ['label' => 'Colours & type', 'tab' => Controls_Manager::TAB_STYLE]);
        foreach (['accent' => 'Accent / button', 'accent-hover' => 'Button hover', 'text' => 'Text', 'muted' => 'Secondary text', 'soft' => 'Tag background', 'line' => 'Card border', 'card' => 'Card background'] as $k => $label) {
            $this->add_control('c_' . $k, ['label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .wpaical-up' => '--wpaical-' . $k . ': {{VALUE}};']]);
        }
        $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'title_type', 'label' => 'Heading typography', 'selector' => '{{WRAPPER}} .wpaical-up-title']);
        $this->add_group_control(Group_Control_Typography::get_type(), ['name' => 'card_type', 'label' => 'Card title typography', 'selector' => '{{WRAPPER}} .wpaical-up h3']);
        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();
        $program = trim(wpaical_el_list($s['program']) . ',' . ($s['program_custom'] ?? ''), ',');
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render()
        echo WPAICal_Widget::render([
            'program' => $program, 'match' => $s['match'], 'category' => $s['category'], 'limit' => $s['limit'], 'days' => $s['days'],
            'series' => $s['series'], 'heading' => $s['heading'], 'lead' => $s['lead'], 'button' => $s['button'], 'empty' => $s['empty'],
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}

class WPAICal_El_Calendar extends Widget_Base {
    public function get_name() { return 'wpaical_calendar'; }
    public function get_title() { return 'AI Calendar'; }
    public function get_icon() { return 'eicon-calendar'; }
    public function get_categories() { return ['wpaical']; }
    public function get_keywords() { return ['events', 'calendar', 'month', 'schedule']; }
    public function get_style_depends() { return wpaical_el_editing() ? ['wpaical-calendar'] : []; }
    public function get_script_depends() { return wpaical_el_editing() ? ['wpaical-calendar', 'wpaical-elementor'] : []; }

    protected function register_controls() {
        $this->start_controls_section('content', ['label' => 'Calendar']);
        $this->add_control('views', ['label' => 'Views', 'type' => Controls_Manager::SELECT2, 'multiple' => true, 'label_block' => true, 'default' => ['month', 'week', 'list'],
            'options' => ['month' => 'Month', 'week' => 'Week', 'list' => 'List']]);
        $this->add_control('default_view', ['label' => 'Start on', 'type' => Controls_Manager::SELECT, 'default' => 'month', 'options' => ['month' => 'Month', 'week' => 'Week', 'list' => 'List']]);
        $this->add_control('program', ['label' => 'Only program', 'type' => Controls_Manager::SELECT2, 'multiple' => true, 'label_block' => true, 'options' => WPAICal_Elementor::program_options(), 'description' => 'Empty shows every event.']);
        $this->add_control('categories', ['label' => 'Only categories', 'type' => Controls_Manager::SELECT2, 'multiple' => true, 'label_block' => true, 'options' => WPAICal_Elementor::category_options(), 'description' => 'Empty shows every category.']);
        $this->add_control('week_start', ['label' => 'Week starts on', 'type' => Controls_Manager::SELECT, 'default' => '', 'options' => ['' => 'WordPress setting', '0' => 'Sunday', '1' => 'Monday', '6' => 'Saturday']]);
        $this->add_control('chips', ['label' => 'Events shown per day (month view)', 'type' => Controls_Manager::NUMBER, 'default' => 2, 'min' => 1, 'max' => 6]);
        $this->add_control('legend', ['label' => 'Category filter chips', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes', 'return_value' => 'yes']);
        $this->add_control('timezone_toggle', ['label' => 'Your time / event time switch', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes', 'return_value' => 'yes']);
        $this->add_control('new_tab', ['label' => 'Open event links in a new tab', 'type' => Controls_Manager::SWITCHER, 'default' => 'yes', 'return_value' => 'yes']);
        $this->end_controls_section();

        $this->start_controls_section('text', ['label' => 'Text']);
        $this->add_control('eyebrow', ['label' => 'Small label above the title', 'type' => Controls_Manager::TEXT, 'label_block' => true]);
        $this->add_control('title', ['label' => 'Title', 'type' => Controls_Manager::TEXT, 'label_block' => true, 'description' => 'Empty shows the month name.']);
        $this->add_control('labels', ['label' => 'Rename categories', 'type' => Controls_Manager::TEXTAREA, 'description' => 'One per line or comma separated, like: Day-long=Day-long / Retreat']);
        $this->end_controls_section();

        $this->start_controls_section('style', ['label' => 'Colours & type', 'tab' => Controls_Manager::TAB_STYLE]);
        foreach (['accent' => 'Accent', 'text' => 'Text / buttons', 'head-bg' => 'Weekday bar background', 'head-text' => 'Weekday bar text', 'line' => 'Lines', 'card' => 'Calendar background', 'soft' => 'Soft background'] as $k => $label) {
            $this->add_control('c_' . $k, ['label' => $label, 'type' => Controls_Manager::COLOR, 'selectors' => ['{{WRAPPER}} .wpaical-cal' => '--wpaical-cal-' . $k . ': {{VALUE}};']]);
        }
        $this->add_control('radius', ['label' => 'Corner radius', 'type' => Controls_Manager::SLIDER, 'size_units' => ['px'], 'range' => ['px' => ['min' => 0, 'max' => 40]], 'selectors' => ['{{WRAPPER}} .wpaical-cal' => '--wpaical-cal-radius: {{SIZE}}{{UNIT}};']]);
        $this->add_control('cell_height', ['label' => 'Day cell height', 'type' => Controls_Manager::SLIDER, 'size_units' => ['px'], 'range' => ['px' => ['min' => 60, 'max' => 220]], 'selectors' => ['{{WRAPPER}} .wpaical-cal' => '--wpaical-cal-cell-height: {{SIZE}}{{UNIT}};']]);
        $this->add_control('font', ['label' => 'Body font', 'type' => Controls_Manager::FONT, 'selectors' => ['{{WRAPPER}} .wpaical-cal' => '--wpaical-cal-font: {{VALUE}};']]);
        $this->add_control('font_heading', ['label' => 'Heading font', 'type' => Controls_Manager::FONT, 'selectors' => ['{{WRAPPER}} .wpaical-cal' => '--wpaical-cal-font-heading: {{VALUE}};']]);
        $this->end_controls_section();
    }

    protected function render() {
        $s = $this->get_settings_for_display();
        $labels = preg_replace('/\r\n|\r|\n/', ',', (string) ($s['labels'] ?? ''));
        // phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside render()
        echo WPAICal_Calendar::render([
            'views' => wpaical_el_list($s['views']) ?: 'month', 'default_view' => $s['default_view'], 'program' => wpaical_el_list($s['program']),
            'categories' => wpaical_el_list($s['categories']), 'legend' => $s['legend'] ?: 'no', 'timezone_toggle' => $s['timezone_toggle'] ?: 'no',
            'new_tab' => $s['new_tab'] ?: 'no', 'week_start' => $s['week_start'], 'chips' => $s['chips'], 'eyebrow' => $s['eyebrow'],
            'title' => $s['title'], 'labels' => $labels,
        ]);
        // phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
