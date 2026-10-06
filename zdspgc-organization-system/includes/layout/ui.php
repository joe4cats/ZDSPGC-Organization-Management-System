<?php
/**
 * layout/ui.php — reusable interface pieces shared by every page.
 *
 * Status badges, stat cards, empty states, breadcrumbs, pagination, avatars and
 * form controls. Keeping them here is what makes the ~60 pages of the system
 * look and behave like one application instead of 60 different ones.
 */

declare(strict_types=1);

/** Maps any workflow status to one of the visual tones. */
function ui_tone(?string $status): string
{
    return match ($status) {
        'active', 'approved', 'present', 'completed', 'implemented', 'reported', 'published', 'closed' => 'green',
        'pending', 'pending_adviser', 'pending_admin', 'submitted', 'under_review', 'revision_required', 'upcoming', 'ongoing', 'late' => 'amber',
        'rejected', 'suspended', 'absent', 'error' => 'red',
        'draft', 'archived', 'inactive', 'expired', 'not_applied', 'cancelled', 'no_show'           => 'grey',
        'excused'                                                                                   => 'blue',
        default                                                                                     => 'grey',
    };
}

/** Human label for a status value ("pending_admin" → "Pending admin"). */
function ui_status(?string $status): string
{
    $status = trim((string) $status);
    return $status === '' ? '—' : ucwords(str_replace('_', ' ', $status));
}

/** Coloured status pill. */
function ui_badge(string $text, string $tone = 'grey'): string
{
    return '<span class="badge ' . Helpers::e($tone) . '">' . Helpers::e($text) . '</span>';
}

/** Status pill straight from a database status value. */
function ui_status_badge(?string $status): string
{
    return ui_badge(ui_status($status), ui_tone($status));
}

/** A statistic card for dashboards. */
function ui_stat(string $label, int|float|string $value, string $iconName = 'chart', string $tone = '', string $note = ''): string
{
    return '<div class="stat' . ($tone !== '' ? ' ' . Helpers::e($tone) : '') . '">'
        . '<div class="stat-ico">' . icon($iconName, 20) . '</div>'
        . '<div class="stat-body"><div class="stat-num">' . Helpers::e((string) $value) . '</div>'
        . '<div class="stat-lbl">' . Helpers::e($label) . '</div>'
        . ($note !== '' ? '<div class="stat-note">' . Helpers::e($note) . '</div>' : '')
        . '</div></div>';
}

/** Empty state with optional hint. */
function ui_empty(string $message, string $hint = '', string $iconName = 'inbox'): string
{
    return '<div class="empty-state"><div class="empty-ico">' . icon($iconName, 30) . '</div>'
        . '<p class="empty-msg">' . Helpers::e($message) . '</p>'
        . ($hint !== '' ? '<p class="empty-hint">' . Helpers::e($hint) . '</p>' : '')
        . '</div>';
}

/** Loading placeholder shown while a fetch() request is in flight. */
function ui_loading(string $label = 'Loading…'): string
{
    return '<div class="loading-state"><span class="spinner" aria-hidden="true"></span><span>' . Helpers::e($label) . '</span></div>';
}

/**
 * Shared month calendar grid — the one look every portal inherits.
 *
 * Builds the 6x7 day cells (including the leading/trailing days of the
 * previous/next month) and renders the .cal grid markup.
 *
 * @param string                                     $month YYYY-MM
 * @param array<string,array<int,array<string,mixed>>> $byDay Y-m-d => raw event rows
 * @param array<string,mixed>                        $opts  label|sub|right|more|map|limit
 *        label  heading text (defaults to "F Y" of $month)
 *        sub    small line under the heading (already plain text)
 *        right  trusted HTML for the right side of the header (buttons, legend)
 *        more   page path for the "+N more" link (e.g. "adviser/events.php")
 *        limit  max events shown per day (default 3)
 *        map    callable fn(array $event): array{href:string,time:string,title:string,tone:string,tip:string}
 * @return string
 */
function ui_calendar(string $month, array $byDay, array $opts = []): string
{
    $firstTs     = (int) strtotime($month . '-01');
    $daysInMonth = (int) date('t', $firstTs);
    $lead        = ((int) date('N', $firstTs)) - 1;
    $prevMonth   = date('Y-m', (int) strtotime($month . '-01 -1 month'));
    $nextMonth   = date('Y-m', (int) strtotime($month . '-01 +1 month'));
    $todayYmd    = date('Y-m-d');
    $label       = (string) ($opts['label'] ?? date('F Y', $firstTs));
    $sub         = (string) ($opts['sub'] ?? '');
    $right       = (string) ($opts['right'] ?? '');
    $more        = (string) ($opts['more'] ?? '');
    $limit       = max(1, (int) ($opts['limit'] ?? 3));
    $map         = $opts['map'] ?? null;

    $pad      = static fn (int $d): string => str_pad((string) $d, 2, '0', STR_PAD_LEFT);
    $prevDays = (int) date('t', (int) strtotime($prevMonth . '-01'));
    $trail    = (7 - (($lead + $daysInMonth) % 7)) % 7;

    $cells = [];
    for ($i = 0; $i < $lead; $i++) {
        $d      = $prevDays - $lead + 1 + $i;
        $cells[] = ['day' => $d, 'out' => true, 'ymd' => $prevMonth . '-' . $pad($d), 'events' => []];
    }
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $ymd     = $month . '-' . $pad($d);
        $cells[] = ['day' => $d, 'out' => false, 'ymd' => $ymd, 'events' => $byDay[$ymd] ?? []];
    }
    for ($d = 1; $d <= $trail; $d++) {
        $cells[] = ['day' => $d, 'out' => true, 'ymd' => $nextMonth . '-' . $pad($d), 'events' => []];
    }
    $rows = array_chunk($cells, 7);

    $out  = '<div class="cal-top"><div><h3>' . Helpers::e($label) . '</h3>';
    if ($sub !== '') {
        $out .= '<p class="cal-sub">' . Helpers::e($sub) . '</p>';
    }
    $out .= '</div>' . $right . '</div>';

    $out .= '<div class="cal-scroll"><div class="cal" role="grid" aria-label="' . Helpers::e($label) . ' calendar">';
    $out .= '<div class="cal-head" role="row">';
    foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dow) {
        $out .= '<span role="columnheader">' . $dow . '</span>';
    }
    $out .= '</div><div class="cal-body">';

    foreach ($rows as $row) {
        $out .= '<div class="cal-row" role="row">';
        foreach ($row as $col => $cell) {
            $isToday   = !$cell['out'] && $cell['ymd'] === $todayYmd;
            $isWeekend = $col >= 5;
            $dayEvents = $cell['events'];
            $classes   = 'cal-day'
                . ($cell['out'] ? ' is-out' : '')
                . ($isToday ? ' is-today' : '')
                . ($isWeekend ? ' is-weekend' : '');

            $out .= '<div class="' . $classes . '" role="gridcell"'
                . ($isToday ? ' aria-current="date"' : '')
                . ' aria-label="' . Helpers::e(Helpers::fmtDate($cell['ymd'])
                    . ($dayEvents !== [] ? ', ' . count($dayEvents) . ' event(s)' : '')) . '">';
            $out .= '<div class="cal-day-top"><span class="cal-num">' . (int) $cell['day'] . '</span>'
                . ($isToday ? '<span class="cal-today">Today</span>' : '')
                . '</div>';

            if ($dayEvents !== []) {
                $out .= '<div class="cal-events">';
                foreach (array_slice($dayEvents, 0, $limit) as $one) {
                    $item = is_callable($map)
                        ? (array) $map($one)
                        : [
                            'href'  => '',
                            'time'  => isset($one['start_time']) ? Helpers::fmtTime((string) $one['start_time']) : '',
                            'title' => (string) ($one['title'] ?? ''),
                            'tone'  => ui_tone(isset($one['status']) ? (string) $one['status'] : null),
                            'tip'   => (string) ($one['title'] ?? ''),
                        ];
                    $tag = ($item['href'] ?? '') !== '' ? 'a' : 'div';
                    $out .= '<' . $tag . ' class="cal-ev ' . Helpers::e((string) ($item['tone'] ?? 'grey')) . '"'
                        . ($tag === 'a' ? ' href="' . Helpers::e(Helpers::url((string) $item['href'])) . '"' : '')
                        . ' title="' . Helpers::e((string) ($item['tip'] ?? '')) . '">'
                        . '<span class="cal-ev-time">' . Helpers::e((string) ($item['time'] ?? '')) . '</span>'
                        . '<span class="cal-ev-title">' . Helpers::e((string) ($item['title'] ?? '')) . '</span>'
                        . '</' . $tag . '>';
                }
                if (count($dayEvents) > $limit && $more !== '') {
                    $sep = strpos($more, '?') === false ? '?' : '&';
                    $out .= '<a class="cal-more" href="' . Helpers::e(Helpers::url(
                        $more . $sep . 'from=' . $cell['ymd'] . '&to=' . $cell['ymd']
                    )) . '">+' . (count($dayEvents) - $limit) . ' more</a>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        }
        $out .= '</div>';
    }

    return $out . '</div></div></div>';
}

/** Breadcrumb trail. @param array<int,array{label:string,href?:string}> $items */
function ui_breadcrumbs(array $items): string
{
    if ($items === []) {
        return '';
    }
    $out  = '<nav class="crumbs" aria-label="Breadcrumb"><ol>';
    $last = count($items) - 1;
    foreach (array_values($items) as $i => $item) {
        $label = Helpers::e((string) ($item['label'] ?? ''));
        if ($i === $last || empty($item['href'])) {
            $out .= '<li aria-current="page">' . $label . '</li>';
        } else {
            $out .= '<li><a href="' . Helpers::e(Helpers::url((string) $item['href'])) . '">' . $label . '</a></li>';
        }
    }
    return $out . '</ol></nav>';
}

/**
 * Pagination control. $query keeps the current filters in the page links.
 */
function ui_pagination(int $page, int $pages, string $query = ''): string
{
    if ($pages <= 1) {
        return '';
    }
    $page = max(1, min($page, $pages));
    $link = static fn (int $p): string => '?' . ($query !== '' ? $query . '&' : '') . 'page=' . $p;
    $out  = '<nav class="pager" aria-label="Pagination"><span class="pager-info">Page ' . $page . ' of ' . $pages . '</span>'
          . '<span class="pager-links">'
          . ($page > 1
                ? '<a href="' . Helpers::e($link($page - 1)) . '">' . icon('chevron-left', 15) . ' Previous</a>'
                : '<span class="disabled">' . icon('chevron-left', 15) . ' Previous</span>')
          . ($page < $pages
                ? '<a href="' . Helpers::e($link($page + 1)) . '">Next ' . icon('chevron-right', 15) . '</a>'
                : '<span class="disabled">Next ' . icon('chevron-right', 15) . '</span>')
          . '</span></nav>';
    return $out;
}

/** Circular initials avatar with an optional profile picture. */
function ui_avatar(?string $name, ?string $imageUrl = null, int $size = 40): string
{
    $style = 'width:' . $size . 'px;height:' . $size . 'px;font-size:' . max(11, (int) round($size * 0.36)) . 'px';
    $inner = Helpers::e(Helpers::initials((string) $name));
    if ($imageUrl !== null && $imageUrl !== '') {
        $inner = '<img src="' . Helpers::e($imageUrl) . '" alt="" loading="lazy" onerror="this.remove()">';
    }
    return '<span class="avatar" style="' . $style . '" aria-hidden="true">' . $inner . '</span>';
}

/** Organization avatar: uploaded logo when available, acronym otherwise. */
function ui_org_badge(?array $organization, int $size = 44): string
{
    $logo  = Uploads::url((string) ($organization['logo'] ?? ''));
    $label = Helpers::e((string) ($organization['acronym'] ?? ''));
    if ($label === '') {
        $label = Helpers::e(mb_strtoupper(mb_substr((string) ($organization['name'] ?? '?'), 0, 2)));
    }
    if ($logo !== null) {
        return '<span class="org-badge" style="width:' . $size . 'px;height:' . $size . 'px">'
            . '<img src="' . Helpers::e($logo) . '" alt="' . $label . '" loading="lazy" onerror="this.remove()"></span>';
    }
    return '<span class="org-badge plain" style="width:' . $size . 'px;height:' . $size . 'px;font-size:'
        . max(10, (int) round($size * 0.34)) . 'px">' . $label . '</span>';
}

/** Acronym subtitle for an organization card — empty when it repeats the name. */
function ui_org_acronym(array $organization): string
{
    $name    = trim((string) ($organization['name'] ?? $organization['organization_name'] ?? ''));
    $acronym = trim((string) ($organization['acronym'] ?? ''));
    if ($acronym === '' || strcasecmp($acronym, $name) === 0) {
        return '';
    }
    return '<span class="acronym">' . Helpers::e($acronym) . '</span>';
}

/** Description paragraph for an organization card — empty when there is no description. */
function ui_org_desc(array $organization, int $limit = 150): string
{
    $desc = trim((string) ($organization['description'] ?? ''));
    if ($desc === '') {
        return '';
    }
    return '<p class="desc">' . Helpers::e(Helpers::excerpt($desc, $limit)) . '</p>';
}

/* -------------------------------------------------------------------------
 * Form controls — one consistent look for every form in the system
 * ---------------------------------------------------------------------- */

/**
 * Text-like input.
 *
 * @param array<string,string> $opts type|placeholder|hint|min|max|step|attrs
 */
function ui_input(string $name, string $label, string $value = '', array $opts = [], bool $required = false): string
{
    $type  = $opts['type'] ?? 'text';
    $extra = (string) ($opts['attrs'] ?? '');
    $input = '<input type="' . Helpers::e($type) . '" name="' . Helpers::e($name) . '" id="' . Helpers::e($name) . '"'
        . ' value="' . Helpers::e($value) . '"'
        . ($required ? ' required' : '')
        . (isset($opts['readonly']) && $opts['readonly'] !== '' ? ' readonly' : '')
        . (isset($opts['placeholder']) ? ' placeholder="' . Helpers::e($opts['placeholder']) . '"' : '')
        . (isset($opts['min']) ? ' min="' . Helpers::e($opts['min']) . '"' : '')
        . (isset($opts['max']) ? ' max="' . Helpers::e($opts['max']) . '"' : '')
        . (isset($opts['step']) ? ' step="' . Helpers::e($opts['step']) . '"' : '')
        . ' ' . $extra . '>';
    if ($type === 'password') {
        $input = '<span class="pw-wrap">' . $input
            . '<button type="button" class="pw-toggle" aria-label="Show / hide password"'
            . ' aria-pressed="false" title="Toggle password visibility">'
            . icon('eye', 18) . '</button></span>';
    }
    return '<label class="field"><span class="field-label">' . Helpers::e($label)
        . ($required ? ' <b class="req">*</b>' : '') . '</span>'
        . $input
        . (isset($opts['hint']) ? '<span class="field-hint">' . Helpers::e($opts['hint']) . '</span>' : '')
        . '</label>';
}

/**
 * Select box.
 *
 * @param array<string,string> $options value => label
 * @param array<string,string> $opts    placeholder|hint
 */
function ui_select(string $name, string $label, array $options, string $value = '', array $opts = [], bool $required = false): string
{
    $html = '<label class="field"><span class="field-label">' . Helpers::e($label)
        . ($required ? ' <b class="req">*</b>' : '') . '</span>'
        . '<select name="' . Helpers::e($name) . '" id="' . Helpers::e($name) . '"' . ($required ? ' required' : '') . '>';
    if (isset($opts['placeholder'])) {
        $html .= '<option value="">' . Helpers::e($opts['placeholder']) . '</option>';
    }
    foreach ($options as $optionValue => $optionLabel) {
        $selected = (string) $optionValue === (string) $value ? ' selected' : '';
        $html    .= '<option value="' . Helpers::e((string) $optionValue) . '"' . $selected . '>'
                  . Helpers::e((string) $optionLabel) . '</option>';
    }
    return $html . '</select>'
        . (isset($opts['hint']) ? '<span class="field-hint">' . Helpers::e($opts['hint']) . '</span>' : '')
        . '</label>';
}

/** Textarea. @param array<string,string> $opts rows|placeholder|hint */
function ui_textarea(string $name, string $label, string $value = '', array $opts = [], bool $required = false): string
{
    return '<label class="field"><span class="field-label">' . Helpers::e($label)
        . ($required ? ' <b class="req">*</b>' : '') . '</span>'
        . '<textarea name="' . Helpers::e($name) . '" id="' . Helpers::e($name) . '" rows="' . Helpers::e($opts['rows'] ?? '4') . '"'
        . ($required ? ' required' : '')
        . (isset($opts['placeholder']) ? ' placeholder="' . Helpers::e($opts['placeholder']) . '"' : '')
        . '>' . Helpers::e($value) . '</textarea>'
        . (isset($opts['hint']) ? '<span class="field-hint">' . Helpers::e($opts['hint']) . '</span>' : '')
        . '</label>';
}

/** Horizontal filter bar: wraps any number of pre-built fields in a form. */
function ui_filter_form(string $action, string $fieldsHtml, string $submitLabel = 'Apply filters'): string
{
    return '<form class="filter-bar" method="get" action="' . Helpers::e(Helpers::url($action)) . '">'
        . $fieldsHtml
        . '<div class="filter-actions">'
        . '<button class="btn sm" type="submit">' . icon('filter', 16) . '<span>' . Helpers::e($submitLabel) . '</span></button>'
        . '<a class="btn sm ghost" href="' . Helpers::e(Helpers::url($action)) . '">Reset</a>'
        . '</div></form>';
}

/** A compact GET filter field (used inside ui_filter_form). Extra attributes (e.g. data-filter) go in $attrs. */
function ui_filter_input(string $name, string $label, string $value = '', string $type = 'text', string $attrs = ''): string
{
    return '<label class="filter-field"><span>' . Helpers::e($label) . '</span>'
        . '<input type="' . Helpers::e($type) . '" name="' . Helpers::e($name) . '" value="' . Helpers::e($value) . '"'
        . ($attrs !== '' ? ' ' . $attrs : '')
        . (($type === 'date') ? '' : ' placeholder="' . Helpers::e($label) . '"') . '></label>';
}

/** A compact GET filter select. @param array<string,string> $options */
function ui_filter_select(string $name, string $label, array $options, string $value = '', string $placeholder = 'All'): string
{
    $html = '<label class="filter-field"><span>' . Helpers::e($label) . '</span>'
        . '<select name="' . Helpers::e($name) . '"><option value="">' . Helpers::e($placeholder) . '</option>';
    foreach ($options as $optionValue => $optionLabel) {
        $html .= '<option value="' . Helpers::e((string) $optionValue) . '"'
            . ((string) $optionValue === (string) $value ? ' selected' : '') . '>'
            . Helpers::e((string) $optionLabel) . '</option>';
    }
    return $html . '</select></label>';
}


