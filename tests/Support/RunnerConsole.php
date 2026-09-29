<?php

namespace Tests\Support;

use Pest\Browser\Playwright\Client;
use Pest\Browser\Playwright\Context;
use Pest\Browser\Playwright\Page;
use ReflectionProperty;

/**
 * The browser console as the RUNNER sees it (Playwright's `console` event
 * of the context, what `page.on('console')` gets), not a collector inside
 * the page: Chrome's own lines such as "Failed to load resource: the server
 * responded with a status of 401" never pass through `console.error` and
 * no in-page script can see them.
 *
 * pest-plugin-browser does not subscribe to the event and drops what it
 * does not ask for, so this subscribes on the context and runs its own
 * evaluations, keeping every console event that arrives meanwhile. Between
 * watch() and messages(), drive the page only through run() and until():
 * a Page method would swallow the events that arrive during it.
 */
final class RunnerConsole
{
    /** @var list<array{type: string, text: string}> */
    private array $messages = [];

    private function __construct(private readonly string $contextGuid, private readonly string $frameGuid) {}

    public static function watch(Page $page): self
    {
        $context = (new ReflectionProperty(Page::class, 'context'))->getValue($page);
        assert($context instanceof Context);
        $watcher = new self(
            (string) (new ReflectionProperty(Context::class, 'guid'))->getValue($context),
            (string) (new ReflectionProperty(Page::class, 'frameGuid'))->getValue($page),
        );
        $watcher->collect(Client::instance()->execute($watcher->contextGuid, 'updateSubscription', ['event' => 'console', 'enabled' => true]));

        return $watcher;
    }

    /**
     * Evaluates a function expression in the page and returns its value.
     */
    public function run(string $function): mixed
    {
        $value = null;

        foreach (Client::instance()->execute($this->frameGuid, 'evaluateExpression', ['expression' => $function, 'isFunction' => true, 'arg' => ['value' => ['v' => 'undefined'], 'handles' => []]]) as $message) {
            $this->keep($message);

            if (is_array($message) && array_key_exists('result', $message) && is_array($message['result']['value'] ?? null)) {
                $value = $message['result']['value'];
            }
        }

        return $value;
    }

    /**
     * Polls a function expression until it returns true, collecting meanwhile.
     */
    public function until(string $function, int $timeoutMs = 10_000): bool
    {
        $deadline = microtime(true) + $timeoutMs / 1000;

        do {
            if ($this->run($function) === ['b' => true]) {
                return true;
            }

            $this->run('() => new Promise((resolve) => setTimeout(resolve, 100))');
        } while (microtime(true) < $deadline);

        return false;
    }

    /**
     * @return list<array{type: string, text: string}>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @param  iterable<mixed>  $messages
     */
    private function collect(iterable $messages): void
    {
        foreach ($messages as $message) {
            $this->keep($message);
        }
    }

    private function keep(mixed $message): void
    {
        if (is_array($message) && ($message['method'] ?? null) === 'console' && ($message['guid'] ?? null) === $this->contextGuid) {
            $this->messages[] = ['type' => (string) ($message['params']['type'] ?? ''), 'text' => (string) ($message['params']['text'] ?? '')];
        }
    }
}
