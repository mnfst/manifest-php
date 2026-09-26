<?php declare(strict_types=1);

namespace Mnfst\Tests\Unit;

use Mnfst\Config;
use Mnfst\UrlFilter;
use PHPUnit\Framework\TestCase;

/** Shared with the Node, Python and Hermes SDKs: the same fixture, the same answers. */
final class UrlFilterTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/../fixtures/url-filter.json'), true);
    }

    public function testParsesEverySharedEntryTheSameWay(): void
    {
        foreach (self::cases()['parse'] as $case) {
            $blank = trim($case['entry']) === '';
            self::assertSame($case['rule'], $blank ? null : UrlFilter::parse($case['entry']), $case['entry']);
            [, $invalid] = UrlFilter::rules(UrlFilter::entries([$case['entry']]));
            self::assertSame((bool) ($case['invalid'] ?? false), $invalid !== [], $case['entry']);
        }
    }

    public function testMatchesEverySharedUrlTheSameWay(): void
    {
        foreach (self::cases()['match'] as $case) {
            $config = Config::resolve('k', allowlist: $case['allow'] ?? null, denylist: $case['deny'] ?? null);
            self::assertSame(
                $case['excluded'],
                UrlFilter::excluded($config->allowlist, $config->denylist, $case['url']),
                (string) json_encode($case),
            );
        }
    }

    public function testOptionBeatsEnv(): void
    {
        $_SERVER['MNFST_DENYLIST'] = 'env.com';
        $_SERVER['MNFST_ALLOWLIST'] = 'a.com/v1';
        try {
            self::assertSame([['host' => 'env.com', 'path' => null]], Config::resolve('k')->denylist);
            self::assertSame([['host' => 'a.com', 'path' => '/v1']], Config::resolve('k')->allowlist);
            self::assertSame([['host' => 'opt.com', 'path' => null]], Config::resolve('k', denylist: ['opt.com'])->denylist);
            self::assertSame(Config::resolve('k')->denylist, Config::resolve('k', denylist: '')->denylist, 'a blank option is unset');
            self::assertSame(Config::resolve('k')->allowlist, Config::resolve('k', allowlist: [' '])->allowlist);
        } finally {
            unset($_SERVER['MNFST_DENYLIST'], $_SERVER['MNFST_ALLOWLIST']);
        }
    }

    public function testNoAllowlistIsNullAndDroppedEntriesAreReported(): void
    {
        self::assertNull(Config::resolve('k')->allowlist);
        $config = Config::resolve('k', allowlist: 'stripe.com, stripe.com/v1/*');
        self::assertSame(['stripe.com/v1/*'], $config->ignoredEntries);
    }
}
