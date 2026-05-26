<?php

namespace tests\unit\Espo\Modules\Xero;

use Espo\Modules\Xero\Tools\ConflictResolver;
use PHPUnit\Framework\TestCase;

class ConflictResolverTest extends TestCase
{
    private ConflictResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ConflictResolver();
    }

    public function testXeroWinsWhenNewer(): void
    {
        $result = $this->resolver->resolve('2024-06-10T12:00:00', '2024-06-09T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_XERO, $result);
    }

    public function testEspoWinsWhenNewer(): void
    {
        $result = $this->resolver->resolve('2024-06-08T12:00:00', '2024-06-10T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_ESPO, $result);
    }

    public function testEspoWinsWhenSameTimestamp(): void
    {
        $result = $this->resolver->resolve('2024-06-10T12:00:00', '2024-06-10T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_ESPO, $result);
    }

    public function testXeroWinsWhenEspoSyncMissing(): void
    {
        $result = $this->resolver->resolve('2024-06-10T12:00:00', null);

        $this->assertSame(ConflictResolver::WINNER_XERO, $result);
    }

    public function testEspoWinsWhenXeroTimeMissing(): void
    {
        $result = $this->resolver->resolve(null, '2024-06-10T12:00:00');

        $this->assertSame(ConflictResolver::WINNER_ESPO, $result);
    }

    public function testNoneWhenBothMissing(): void
    {
        $result = $this->resolver->resolve(null, null);

        $this->assertSame(ConflictResolver::WINNER_NONE, $result);
    }

    public function testIsXeroNewerTrue(): void
    {
        $this->assertTrue($this->resolver->isXeroNewer('2024-06-10T12:00:00', '2024-06-09T12:00:00'));
    }

    public function testIsXeroNewerFalse(): void
    {
        $this->assertFalse($this->resolver->isXeroNewer('2024-06-08T12:00:00', '2024-06-10T12:00:00'));
    }

    // Xero-specific: /Date(ms+offset)/ format parsing

    public function testXeroDotNetDateFormatParsedWhenNewer(): void
    {
        // /Date(1718020800000+0000)/ = 2024-06-10T12:00:00 UTC
        $xeroDate = '/Date(1718020800000+0000)/';
        $espoSyncedAt = '2024-06-09 12:00:00';

        $result = $this->resolver->resolve($xeroDate, $espoSyncedAt);

        $this->assertSame(ConflictResolver::WINNER_XERO, $result);
    }

    public function testXeroDotNetDateFormatParsedWhenOlder(): void
    {
        // /Date(1717934400000+0000)/ = 2024-06-09T12:00:00 UTC
        $xeroDate = '/Date(1717934400000+0000)/';
        $espoSyncedAt = '2024-06-10 12:00:00';

        $result = $this->resolver->resolve($xeroDate, $espoSyncedAt);

        $this->assertSame(ConflictResolver::WINNER_ESPO, $result);
    }

    public function testXeroDotNetDateWithNegativeOffset(): void
    {
        // /Date(1718020800000-0700)/ — offset suffix is ignored; ms value is authoritative
        $xeroDate = '/Date(1718020800000-0700)/';
        $espoSyncedAt = '2024-06-09 12:00:00';

        $result = $this->resolver->resolve($xeroDate, $espoSyncedAt);

        $this->assertSame(ConflictResolver::WINNER_XERO, $result);
    }
}
