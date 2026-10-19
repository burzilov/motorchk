<?php

namespace Hello;

use PluginContext;

/** Хранение приветствия в plugin-data/hello/greeting.json. */
final class GreetingStore
{
    private const FILE = 'greeting.json';

    public function __construct(private PluginContext $plugin)
    {
    }

    public function load(): ?string
    {
        $data = $this->plugin->readJson(self::FILE);

        return is_array($data) && is_string($data['greeting'] ?? null) ? $data['greeting'] : null;
    }

    /** @return array{greeting: ?string, updated_at: ?string} */
    public function loadWithMeta(): array
    {
        $data = $this->plugin->readJson(self::FILE, []);

        return [
            'greeting' => is_string($data['greeting'] ?? null) ? $data['greeting'] : null,
            'updated_at' => is_string($data['updated_at'] ?? null) ? $data['updated_at'] : null,
        ];
    }

    public function save(string $greeting): void
    {
        $this->plugin->writeJson(self::FILE, [
            'greeting' => $greeting,
            'updated_at' => date('c'),
        ]);
    }
}
