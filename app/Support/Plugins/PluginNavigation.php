<?php

namespace App\Support\Plugins;

/**
 * Sidebar entries contributed by plugins. A plugin registers entries from its
 * service provider; they are shared with the frontend as `pluginNavigation`
 * and appended to the children of an existing sidebar group (e.g. `payroll`,
 * `organization_settings_nav`). With several parents, the first group shown
 * is used (e.g. `['expenses', 'payroll']`: fiduciary organisations have no
 * Expenses group). A parent may also be `after:<key>` (a top-level item right
 * after that top-level item, only when it is shown) or a section label (e.g.
 * `nav_activity`: a top-level item at the end of that section); top-level
 * items take an optional icon name known to the sidebar. Entries whose
 * parents are all hidden are not shown.
 */
final class PluginNavigation
{
    /** @var list<array{parents: list<string>, key: string, label: string, href: string, permission: ?string, icon: ?string}> */
    private array $items = [];

    /**
     * @param  string|list<string>  $parent  Key(s) of the sidebar group to extend, in order of preference
     * @param  string  $label  Translation key, resolved with __() per request
     * @param  string|null  $permission  Permission name the user needs (as used by usePermissions().can)
     * @param  string|null  $icon  Icon of a top-level entry (lucide name known to the sidebar, e.g. `FilePen`)
     */
    public function add(string|array $parent, string $key, string $label, string $href, ?string $permission = null, ?string $icon = null): void
    {
        $parents = is_string($parent) ? [$parent] : $parent;
        $this->items[] = compact('parents', 'key', 'label', 'href', 'permission', 'icon');
    }

    /**
     * @return list<array{parent: string, parents: list<string>, key: string, text: string, href: string, permission: ?string, icon: ?string}>
     */
    public function toArray(): array
    {
        return array_map(fn (array $item): array => [
            'parent' => $item['parents'][0],
            'parents' => $item['parents'],
            'key' => $item['key'],
            'text' => (string) __($item['label']),
            'href' => $item['href'],
            'permission' => $item['permission'],
            'icon' => $item['icon'],
        ], $this->items);
    }
}
