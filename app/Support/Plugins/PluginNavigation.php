<?php

namespace App\Support\Plugins;

/**
 * Sidebar entries contributed by plugins. A plugin registers entries from its
 * service provider; they are shared with the frontend as `pluginNavigation`
 * and appended to the children of an existing sidebar group (e.g. `payroll`,
 * `organization_settings_nav`). Entries whose group is not shown are hidden.
 */
final class PluginNavigation
{
    /** @var list<array{parent: string, key: string, label: string, href: string, permission: ?string}> */
    private array $items = [];

    /**
     * @param  string  $parent  Key of the sidebar group to extend
     * @param  string  $label  Translation key, resolved with __() per request
     * @param  string|null  $permission  Permission name the user needs (as used by usePermissions().can)
     */
    public function add(string $parent, string $key, string $label, string $href, ?string $permission = null): void
    {
        $this->items[] = compact('parent', 'key', 'label', 'href', 'permission');
    }

    /**
     * @return list<array{parent: string, key: string, text: string, href: string, permission: ?string}>
     */
    public function toArray(): array
    {
        return array_map(fn (array $item): array => [
            'parent' => $item['parent'],
            'key' => $item['key'],
            'text' => (string) __($item['label']),
            'href' => $item['href'],
            'permission' => $item['permission'],
        ], $this->items);
    }
}
