<?php

namespace Tests\Unit;

use App\Services\DataMaskingService;
use PHPUnit\Framework\TestCase;

class DataMaskingServiceTest extends TestCase
{
    private DataMaskingService $masking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->masking = new DataMaskingService;
    }

    public function test_masks_account_number_preserving_last_four_digits(): void
    {
        $this->assertSame('****5678', $this->masking->maskAccountNumber('01234567895678'));
        $this->assertSame('****3456', $this->masking->maskAccountNumber('123456'));
        $this->assertSame('****1234', $this->masking->maskAccountNumber('1234'));
    }

    public function test_masks_national_id_preserving_last_four_digits(): void
    {
        $this->assertSame('********9012', $this->masking->maskNationalId('079204009012'));
    }

    public function test_masks_vietnamese_phone_number(): void
    {
        $this->assertSame('090***6789', $this->masking->maskPhoneNumber('0901236789'));
        $this->assertSame('098***4321', $this->masking->maskPhoneNumber('0987654321'));
    }
}
