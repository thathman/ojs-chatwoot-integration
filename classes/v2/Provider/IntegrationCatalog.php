<?php

namespace APP\plugins\generic\chatwootIntegration\classes\v2\Provider;

use PluginRegistry;

/**
 * Settings Console item I (Integrations tab, owner directive
 * 2026-09-04): "real installed sibling providers only ... where
 * actually installed and contract-verified." Every entry below was
 * confirmed against the real dell filesystem (`plugins/generic/*`,
 * `plugins/paymethod/*`) and each plugin's own real class/category —
 * this is not a speculative or hoped-for list. `multipay`'s real
 * plugin name has no `plugin` suffix (`PluginRegistry::loadCategory('paymethod', ...)`
 * confirmed this live); every other entry follows the ordinary
 * `strtolower(ClassName)` convention.
 *
 * Deliberately narrow, matching `SupportProviderRegistry`'s own stated
 * discipline: list only real, verified sibling plugins, never grow
 * this speculatively. A plugin not in this list simply isn't shown —
 * this class is never the thing that decides whether a plugin "counts"
 * as an Airix integration, only whether one already known to be real
 * is currently installed/enabled on this site.
 */
final class IntegrationCatalog
{
    /** @var array<int,array{category:string,name:string,label:string}> */
    private const ENTRIES = [
        ['category' => 'generic', 'name' => 'submissionfeeplugin', 'label' => 'Submission Fee'],
        ['category' => 'generic', 'name' => 'requestwaiverplugin', 'label' => 'Request Waiver'],
        ['category' => 'paymethod', 'name' => 'paystackplugin', 'label' => 'Paystack'],
        ['category' => 'paymethod', 'name' => 'flutterwaveplugin', 'label' => 'Flutterwave'],
        ['category' => 'paymethod', 'name' => 'bachsplugin', 'label' => 'Bachs'],
        ['category' => 'paymethod', 'name' => 'multipay', 'label' => 'MultiPay'],
        ['category' => 'generic', 'name' => 'requiredsubmissionfilesplugin', 'label' => 'Required Submission Files'],
        ['category' => 'generic', 'name' => 'contributorusersyncplugin', 'label' => 'Contributor User Sync'],
        ['category' => 'generic', 'name' => 'magicloginplugin', 'label' => 'Magic Login'],
        ['category' => 'generic', 'name' => 'visibilitysuiteplugin', 'label' => 'Visibility Suite'],
    ];

    /** @return string[] Real category names this catalog ever queries — used to loadCategory() each exactly once before resolving. */
    public static function categories(): array
    {
        return array_values(array_unique(array_column(self::ENTRIES, 'category')));
    }

    /**
     * @return array<int,array{label:string,installed:bool,enabled:bool,versionString:?string}>
     */
    public static function resolve(int $contextId): array
    {
        foreach (self::categories() as $category) {
            PluginRegistry::loadCategory($category, true, $contextId);
        }

        $results = [];
        foreach (self::ENTRIES as $entry) {
            $plugin = PluginRegistry::getPlugin($entry['category'], $entry['name']);
            $installed = $plugin !== null;
            $enabled = $installed && method_exists($plugin, 'getEnabled') && $plugin->getEnabled($contextId);
            $versionString = null;
            if ($installed && method_exists($plugin, 'getCurrentVersion')) {
                try {
                    $version = $plugin->getCurrentVersion();
                    $versionString = $version && method_exists($version, 'getVersionString') ? $version->getVersionString() : null;
                } catch (\Throwable $e) {
                    $versionString = null;
                }
            }
            $results[] = [
                'label' => $entry['label'],
                'installed' => $installed,
                'enabled' => $enabled,
                'versionString' => $versionString,
            ];
        }
        return $results;
    }
}
