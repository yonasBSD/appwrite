<?php

namespace Appwrite\Utopia\Response;

abstract class Filter
{
    /**
     * @var ?array<mixed> $rawContent
     */
    protected ?array $rawContent = null;

    /**
     * Parse the content to another format.
     *
     * @param array $content
     * @param string $model
     *
     * @return array
     */
    abstract public function parse(array $content, string $model): array;

    public function setRawContent(array $rawContent): void
    {
        $this->rawContent = $rawContent;
    }

    /**
     * Handle list
     *
     * @param array $content
     * @param string $key
     * @param callable $callback
     *
     * @return array
     */
    protected function handleList(array $content, string $key, callable $callback): array
    {
        if (array_key_exists($key, $content) && \is_array($content[$key])) {
            foreach ($content[$key] as $i => $item) {
                $content[$key][$i] = $callback($item);
            }
        }

        return $content;
    }
}
