<?php

declare(strict_types=1);

namespace App\Modules\Archive;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;

/**
 * The staff workspace in four destinations instead of nine flat links.
 *
 * Primary navigation shows the groups (a left sidebar on wide screens, a bottom
 * bar on phones); the pages inside the active group appear as section tabs.
 * Visibility follows the same abilities the individual pages already enforce, so
 * this only decides what to offer, never what is allowed.
 *
 * @phpstan-type Item array{key: string, label: string, route?: string, path?: string, match: list<string>, any?: list<string>}
 * @phpstan-type Group array{key: string, label: string, icon: string, items: list<Item>}
 */
final class StaffNavigation
{
    public function __construct(private readonly Gate $gate) {}

    /**
     * Groups with their visible pages; exactly one group (the most specific match)
     * is active, and inside it the page with the most specific match.
     *
     * @return list<array{key: string, icon: string, label: string, href: string, active: bool, items: list<array{key: string, href: string, label: string, active: bool}>}>
     */
    public function groups(Request $request): array
    {
        $groups = [];
        foreach ($this->definitions() as $definition) {
            $items = [];
            foreach ($definition['items'] as $item) {
                if (isset($item['any']) && ! $this->gate->any($item['any'])) {
                    continue;
                }
                $items[] = [
                    'key' => $item['key'],
                    'href' => isset($item['route']) ? route($item['route']) : url($item['path'] ?? '/'),
                    'label' => $item['label'],
                    'score' => $this->score($request, $item['match']),
                ];
            }
            if ($items === []) {
                continue;
            }
            $groups[] = [
                'key' => $definition['key'],
                'icon' => $definition['icon'],
                'label' => $definition['label'],
                'href' => $items[0]['href'],
                'items' => $items,
                'score' => max(array_column($items, 'score')),
            ];
        }

        $best = max([0, ...array_column($groups, 'score')]);
        $activeKey = null;
        foreach ($groups as $group) {
            if ($best > 0 && $group['score'] === $best) {
                $activeKey = $group['key'];
                break;
            }
        }

        return array_map(static function (array $group) use ($activeKey): array {
            $active = $group['key'] === $activeKey;

            return [
                'key' => $group['key'],
                'icon' => $group['icon'],
                'label' => $group['label'],
                'href' => $group['href'],
                'active' => $active,
                'items' => array_map(static fn (array $item): array => [
                    'key' => $item['key'],
                    'href' => $item['href'],
                    'label' => $item['label'],
                    'active' => $active && $item['score'] === $group['score'],
                ], $group['items']),
            ];
        }, $groups);
    }

    /**
     * @return list<Group>
     */
    private function definitions(): array
    {
        return [
            ['key' => 'photos', 'label' => __('shell.nav.groups.photos'), 'icon' => 'image', 'items' => [
                ['key' => 'photos', 'label' => __('shell.nav.items.photos'), 'route' => 'admin.assets.index', 'match' => ['admin.assets.*']],
                ['key' => 'uploads', 'label' => __('shell.nav.items.uploads'), 'route' => 'admin.uploads.index', 'match' => ['admin.uploads.*'], 'any' => ['assets.create']],
            ]],
            ['key' => 'catalogue', 'label' => __('shell.nav.groups.catalogue'), 'icon' => 'layers', 'items' => [
                ['key' => 'catalogue_overview', 'label' => __('shell.nav.items.catalogue_overview'), 'route' => 'catalogue.index', 'match' => ['catalogue.index', 'catalogue.bulk.*', 'catalogue.searches.*']],
                ['key' => 'collections', 'label' => __('shell.nav.items.collections'), 'route' => 'catalogue.collections.index', 'match' => ['catalogue.collections.*']],
                ['key' => 'people', 'label' => __('shell.nav.items.people'), 'route' => 'catalogue.people.index', 'match' => ['catalogue.people.*']],
                ['key' => 'locations', 'label' => __('shell.nav.items.locations'), 'route' => 'catalogue.locations.index', 'match' => ['catalogue.locations.*']],
                ['key' => 'tags', 'label' => __('shell.nav.items.tags'), 'route' => 'catalogue.tags.index', 'match' => ['catalogue.tags.*']],
                ['key' => 'sources', 'label' => __('shell.nav.items.sources'), 'route' => 'catalogue.sources.index', 'match' => ['catalogue.sources.*']],
                ['key' => 'contributors', 'label' => __('shell.nav.items.contributors'), 'route' => 'catalogue.contributors.index', 'match' => ['catalogue.contributors.*']],
                ['key' => 'worklists', 'label' => __('shell.nav.items.worklists'), 'route' => 'catalogue.worklists.index', 'match' => ['catalogue.worklists.*']],
            ]],
            ['key' => 'publish', 'label' => __('shell.nav.groups.publish'), 'icon' => 'globe', 'items' => [
                ['key' => 'publication', 'label' => __('shell.nav.items.publication'), 'route' => 'admin.publications.index', 'match' => ['admin.publications.*']],
                ['key' => 'suggestions', 'label' => __('shell.nav.items.suggestions'), 'route' => 'admin.suggestions.index', 'match' => ['admin.suggestions.*']],
            ]],
            ['key' => 'admin', 'label' => __('shell.nav.groups.admin'), 'icon' => 'sliders', 'items' => [
                ['key' => 'admin_overview', 'label' => __('shell.nav.items.admin_overview'), 'path' => 'admin', 'match' => ['path:admin'], 'any' => ['users.manage']],
                ['key' => 'operations', 'label' => __('shell.nav.items.operations'), 'route' => 'admin.operations.index', 'match' => ['admin.operations.*'], 'any' => ['users.manage', 'audit.view', 'catalogue.manage', 'assets.update', 'assets.view']],
                ['key' => 'exchange', 'label' => __('shell.nav.items.exchange'), 'route' => 'exchange.index', 'match' => ['exchange.*']],
                ['key' => 'notifications', 'label' => __('shell.nav.items.notifications'), 'route' => 'admin.operations.notifications', 'match' => ['admin.operations.notifications*'], 'any' => ['assets.view']],
                ['key' => 'users', 'label' => __('shell.nav.items.users'), 'route' => 'identity.users.index', 'match' => ['identity.users.*', 'identity.invitations.*'], 'any' => ['users.manage']],
            ]],
        ];
    }

    /**
     * Length of the most specific matching pattern; 0 when nothing matches.
     *
     * @param  list<string>  $patterns
     */
    private function score(Request $request, array $patterns): int
    {
        $best = 0;
        foreach ($patterns as $pattern) {
            $matches = str_starts_with($pattern, 'path:')
                ? $request->is(substr($pattern, 5))
                : $request->routeIs($pattern);
            if ($matches) {
                $best = max($best, strlen($pattern));
            }
        }

        return $best;
    }
}
