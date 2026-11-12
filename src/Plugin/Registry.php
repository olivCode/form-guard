<?php

declare(strict_types=1);

namespace FormGuard\Plugin;

/** Validated metadata is snapshotted once; plugins cannot change ordering mid-request. */
final class Registry
{
    /** @var list<PluginInterface> */
    private readonly array $ordered;
    /** @var list<array{id: string, phase: string, requires: list<string>, conflicts: list<string>}> */
    private readonly array $metadata;

    /** @param array<array-key, mixed> $plugins */
    public function __construct(array $plugins)
    {
        if (count($plugins) > 64 || !array_is_list($plugins)) {
            throw new \InvalidArgumentException('Invalid plugin list.');
        }
        $byId = [];
        $info = [];
        $phases = [];
        foreach ($plugins as $plugin) {
            if (!$plugin instanceof PluginInterface) {
                throw new \InvalidArgumentException('Invalid plugin instance.');
            }
            $id = $plugin->id();
            $phase = $plugin->phase();
            if (!self::validId($id) || isset($byId[$id])
                || ($phase === Phase::Request && (!$plugin instanceof RequestPluginInterface || $plugin instanceof FormPluginInterface))
                || ($phase === Phase::Form && (!$plugin instanceof FormPluginInterface || $plugin instanceof RequestPluginInterface))) {
                throw new \InvalidArgumentException('Invalid/duplicate plugin ID or phase contract.');
            }
            $reserved = ['request_limits' => RequestLimitPlugin::class, 'timing' => TimingPlugin::class, 'browser_behavior' => BehaviorPlugin::class];
            if (isset($reserved[$id]) && $plugin::class !== $reserved[$id]) {
                throw new \InvalidArgumentException('Reserved built-in plugin ID.');
            }
            $required = self::relationships($plugin->requires(), $id);
            $conflicts = self::relationships($plugin->conflicts(), $id);
            $byId[$id] = $plugin;
            $phases[$id] = $phase;
            $info[$id] = ['id' => $id, 'phase' => $phase === Phase::Request ? 'request' : 'form', 'requires' => $required, 'conflicts' => $conflicts];
        }
        foreach ($info as $id => $entry) {
            foreach ($entry['requires'] as $required) {
                if (!isset($byId[$required]) || $phases[$required]->value > $phases[$id]->value) {
                    throw new \InvalidArgumentException('Plugin ' . $id . ' requires enabled same/earlier-phase plugin ' . $required . '.');
                }
            }
            foreach ($entry['conflicts'] as $conflict) {
                if (isset($byId[$conflict])) {
                    throw new \InvalidArgumentException('Plugin ' . $id . ' conflicts with enabled plugin ' . $conflict . '.');
                }
            }
        }
        // Stable topological order, request phase first; insertion order breaks ties.
        $ordered = [];
        $metadata = [];
        $done = [];
        foreach (Phase::cases() as $phase) {
            while (true) {
                $remaining = false;
                $progress = false;
                foreach ($byId as $id => $plugin) {
                    if ($phases[$id] !== $phase || isset($done[$id])) {
                        continue;
                    }
                    $remaining = true;
                    if (array_diff($info[$id]['requires'], array_keys($done))) {
                        continue;
                    }
                    $ordered[] = $plugin;
                    $metadata[] = $info[$id];
                    $done[$id] = true;
                    $progress = true;
                }
                if (!$remaining) {
                    break;
                }
                if (!$progress) {
                    throw new \InvalidArgumentException('Plugin dependency cycle.');
                }
            }
        }
        $this->ordered = $ordered;
        $this->metadata = $metadata;
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return list<string>
     */
    private static function relationships(array $ids, string $self): array
    {
        if (!array_is_list($ids) || count($ids) > 64) {
            throw new \InvalidArgumentException('Invalid plugin relationships.');
        }
        $clean = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !self::validId($id) || $id === $self || in_array($id, $clean, true)) {
                throw new \InvalidArgumentException('Invalid plugin relationship.');
            }
            $clean[] = $id;
        }
        return $clean;
    }

    private static function validId(string $id): bool
    {
        return (bool) preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $id);
    }
    /** @return list<PluginInterface> */
    public function ordered(): array
    {
        return $this->ordered;
    }
    /** @return list<array{id: string, phase: string, requires: list<string>, conflicts: list<string>}> */
    public function describe(): array
    {
        return $this->metadata;
    }
}
