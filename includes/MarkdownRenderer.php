<?php

use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use Symfony\Component\Yaml\Yaml;

class MarkdownRenderer
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $environment = new Environment();
        $environment->addExtension(new CommonMarkCoreExtension());
        $this->converter = new CommonMarkConverter([], $environment);
    }

    public function parseFile(string $filePath): array
    {
        $raw = file_get_contents($filePath);
        if ($raw === false) {
            throw new RuntimeException('Не удалось прочитать файл: ' . $filePath);
        }

        return $this->parse($raw);
    }

    public function parse(string $raw): array
    {
        $frontMatter = [];
        $body = $raw;

        if (preg_match('/^---\r?\n(.*?)\r?\n---\r?\n(.*)$/s', $raw, $matches)) {
            $frontMatter = Yaml::parse($matches[1]) ?? [];
            $body = $matches[2];
        }

        if (!is_array($frontMatter)) {
            $frontMatter = [];
        }

        $blocks = $this->parseBlocks(trim($body));

        return [
            'front_matter' => $frontMatter,
            'blocks' => $blocks,
        ];
    }

    public function renderBlocks(array $rawBlocks): array
    {
        $htmlBlocks = [];
        foreach ($rawBlocks as $name => $markdown) {
            $htmlBlocks[$name] = $this->converter->convert(trim($markdown))->getContent();
        }

        return $htmlBlocks;
    }

    private function parseBlocks(string $body): array
    {
        if ($body === '') {
            return ['content' => ''];
        }

        $lines = preg_split('/\r?\n/', $body) ?: [];
        $blocks = [];
        $currentName = 'content';
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^## block:\s*(\w+)\s*$/', $line, $matches)) {
                if ($buffer !== [] || $currentName === 'content') {
                    $blocks[$currentName] = trim(implode("\n", $buffer));
                }
                $currentName = $matches[1];
                $buffer = [];
                continue;
            }

            $buffer[] = $line;
        }

        $blocks[$currentName] = trim(implode("\n", $buffer));

        return $blocks;
    }

    public function serialize(array $frontMatter, array $rawBlocks): string
    {
        $yaml = Yaml::dump($frontMatter, 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $body = '';

        foreach ($rawBlocks as $name => $markdown) {
            if ($name === 'content' && count($rawBlocks) === 1) {
                $body .= trim($markdown) . "\n";
                continue;
            }

            $body .= "## block: {$name}\n\n" . trim($markdown) . "\n\n";
        }

        return "---\n" . trim($yaml) . "\n---\n\n" . trim($body) . "\n";
    }
}
