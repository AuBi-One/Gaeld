<?php

namespace App\Support\Plugins;

/**
 * Sidebar entries contributed by plugins. A plugin registers entries from its
 * service provider; they are shared with the frontend as `pluginNavigation`
 * and appended to the children of an existing sidebar group (e.g. `payroll`,
 * `organization_settings_nav`). With several parents, the first group shown
 * is used (e.g. `['expenses', 'payroll']`: fiduciary organisations have no
 * Expenses group). Entries whose groups are all hidden are not shown.
 */
final class PluginNavigation
{
    /** @var list<array{parents: list<string>, key: string, label: string, href: string, permission: ?string}> */
    private array $items = [];

    /**
     * @param  string|list<string>  $parent  Key(s) of the sidebar group to extend, in order of preference
     * @param  string  $label  Translation key, resolved with __() per request
     * @param  string|null  $permission  Permission name the user needs (as used by usePermissions().can)
     */
    public function add(string|array $parent, string $key, string $label, string $href, ?string $permission = null): void
    {
        $parents = is_string($parent) ? [$parent] : $parent;
        $this->items[] = compact('parents', 'key', 'label', 'href', 'permission');
    }

    /**
     * @return list<array{parent: string, parents: list<string>, key: string, text: string, href: string, permission: ?string}>
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
        ], $this->items);
    }
}
