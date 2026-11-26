<?php

namespace Database\Seeders;

use App\Enums\BankLinkStatus;
use App\Enums\NotificationSeverity;
use App\Enums\NotificationType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\SandboxBankMode;
use App\Enums\StudentCardStatus;
use App\Enums\UserRole;
use App\Models\BankLink;
use App\Models\CanteenNotification;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderStatusLog;
use App\Models\PaymentTransaction;
use App\Models\Product;
use App\Models\ReconciliationBatch;
use App\Models\ReconciliationItem;
use App\Models\SandboxBankAccount;
use App\Models\SpendingLimit;
use App\Models\SpendingLimitLog;
use App\Models\Student;
use App\Models\StudentCard;
use App\Models\User;
use App\Services\DataMaskingService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Idempotent rich demo data for live walkthroughs across all 10 Smart Canteen modules.
 *
 * Historical orders and reconciliation batches are timestamped YESTERDAY (Asia/Ho_Chi_Minh)
 * so the live "today" Admin Dashboard starts cleanly at 0 before the live demo order is placed.
 */
class DemoShowcaseSeeder extends Seeder
{
    public function run(): void
    {
        $masking = app(DataMaskingService::class);
        $todayVn = Carbon::now('Asia/Ho_Chi_Minh');
        $yesterdayDate = $todayVn->copy()->subDay()->toDateString();
        $yesterdayMorning = $todayVn->copy()->subDay()->setTime(9, 15, 0)->utc();
        $yesterdayNoon = $todayVn->copy()->subDay()->setTime(11, 30, 0)->utc();
        $yesterdayAfternoon = $todayVn->copy()->subDay()->setTime(14, 10, 0)->utc();
        $twoDaysAgo = $todayVn->copy()->subDays(2)->setTime(8, 0, 0)->utc();

        $admin = User::query()->where('email', 'admin@smartcanteen.local')->first();
        $staff = User::query()->where('email', 'staff@smartcanteen.local')->first();
        $parent1 = User::query()->where('zalo_user_id', 'demo_customer1')->first();
        $student1User = User::query()->where('zalo_user_id', 'demo_student1')->first();
        $card1 = StudentCard::query()->where('card_code', 'F156BA')->first();

        if (! $parent1 || ! $card1) {
            return;
        }

        // 1. Pre-link Card 1 (F156BA - Titkul Student) to Vietcombank Sandbox (500,000 VND) + Daily Limit 100,000 VND
        $bankLink1 = BankLink::query()->firstOrCreate(
            [
                'student_card_id' => $card1->id,
                'provider_reference' => 'SBX-DEMO-VCB-F156BA',
            ],
            [
                'user_id' => $parent1->id,
                'bank_code' => 'VCB',
                'bank_name' => 'Vietcombank',
                'account_number_encrypted' => '990012345678',
                'account_number_masked' => $masking->maskAccountNumber('990012345678'),
                'account_holder_name' => 'NGUYEN VAN AN',
                'national_id_encrypted' => '079090001234',
                'national_id_masked' => $masking->maskNationalId('079090001234'),
                'phone_number' => '0901234567',
                'phone_masked' => $masking->maskPhoneNumber('0901234567'),
                'email' => 'phuhuynh.an@smartcanteen.local',
                'auto_debit_agreed' => true,
                'auto_debit_agreed_at' => $twoDaysAgo,
                'status' => BankLinkStatus::ACTIVE,
                'linked_at' => $twoDaysAgo,
            ],
        );

        SandboxBankAccount::query()->firstOrCreate(
            ['provider_reference' => 'SBX-DEMO-VCB-F156BA'],
            [
                'bank_code' => 'VCB',
                'account_number_masked' => $masking->maskAccountNumber('990012345678'),
                'balance' => 500000,
                'simulated_mode' => SandboxBankMode::NORMAL,
            ],
        );

        SpendingLimit::query()->firstOrCreate(
            ['student_card_id' => $card1->id],
            [
                'daily_limit' => 100000,
                'spent_today' => 0,
                'spent_date' => $todayVn->toDateString(),
            ],
        );

        if (! SpendingLimitLog::query()->where('student_card_id', $card1->id)->exists()) {
            SpendingLimitLog::query()->create([
                'student_card_id' => $card1->id,
                'changed_by' => $parent1->id,
                'previous_limit' => 0,
                'new_limit' => 50000,
                'reason' => 'Thiết lập hạn mức ban đầu cho bé đầu học kỳ',
                'created_at' => $twoDaysAgo,
            ]);

            SpendingLimitLog::query()->create([
                'student_card_id' => $card1->id,
                'changed_by' => $parent1->id,
                'previous_limit' => 50000,
                'new_limit' => 100000,
                'reason' => 'Tăng hạn mức ngày cho tuần ôn thi học kỳ',
                'created_at' => $yesterdayMorning,
            ]);
        }

        // 2. Second child for Parent 1 (UNLINKED card B294CD) -> ready for live Bank Link + SMS OTP demo
        $student2 = Student::query()->firstOrCreate(
            ['student_code' => 'HS-2026-0002'],
            [
                'user_id' => $parent1->id,
                'full_name' => 'Nguyễn Minh Anh',
                'school_name' => 'THCS Trần Phú',
                'class_name' => '6A1',
                'is_active' => true,
            ],
        );

        StudentCard::query()->firstOrCreate(
            ['card_code' => 'B294CD'],
            [
                'student_id' => $student2->id,
                'status' => StudentCardStatus::ACTIVE,
                'issued_at' => $twoDaysAgo,
            ],
        );

        // 3. Second parent (0909888777) with:
        //    - Card E741MN (ACTIVE, BIDV Sandbox balance = 15,000 VND -> demo INSUFFICIENT_BANK_BALANCE)
        //    - Card L003KK (LOCKED -> demo locked card state on Admin & POS)
        $parent2PhoneFree = ! User::query()->where('phone', '0909888777')->exists();
        $parent2 = User::query()->firstOrCreate(
            ['zalo_user_id' => 'demo_parent2'],
            array_filter([
                'name' => 'Trần Văn Bình (Phụ huynh)',
                'role' => UserRole::PARENT,
                'phone' => $parent2PhoneFree ? '0909888777' : null,
                'phone_verified_at' => $parent2PhoneFree ? $twoDaysAgo : null,
            ], fn ($v) => $v !== null),
        );

        $student3 = Student::query()->firstOrCreate(
            ['student_code' => 'HS-2026-0003'],
            [
                'user_id' => $parent2->id,
                'full_name' => 'Trần Gia Huy',
                'school_name' => 'THCS Trần Phú',
                'class_name' => '7B3',
                'is_active' => true,
            ],
        );

        $card3 = StudentCard::query()->firstOrCreate(
            ['card_code' => 'E741MN'],
            [
                'student_id' => $student3->id,
                'status' => StudentCardStatus::ACTIVE,
                'issued_at' => $twoDaysAgo,
            ],
        );

        BankLink::query()->firstOrCreate(
            [
                'student_card_id' => $card3->id,
                'provider_reference' => 'SBX-DEMO-BIDV-E741MN',
            ],
            [
                'user_id' => $parent2->id,
                'bank_code' => 'BIDV',
                'bank_name' => 'BIDV',
                'account_number_encrypted' => '990011112222',
                'account_number_masked' => $masking->maskAccountNumber('990011112222'),
                'account_holder_name' => 'TRAN VAN BINH',
                'national_id_encrypted' => '079088004567',
                'national_id_masked' => $masking->maskNationalId('079088004567'),
                'phone_number' => '0909888777',
                'phone_masked' => $masking->maskPhoneNumber('0909888777'),
                'email' => 'phuhuynh.binh@smartcanteen.local',
                'auto_debit_agreed' => true,
                'auto_debit_agreed_at' => $twoDaysAgo,
                'status' => BankLinkStatus::ACTIVE,
                'linked_at' => $twoDaysAgo,
            ],
        );

        SandboxBankAccount::query()->firstOrCreate(
            ['provider_reference' => 'SBX-DEMO-BIDV-E741MN'],
            [
                'bank_code' => 'BIDV',
                'account_number_masked' => $masking->maskAccountNumber('990011112222'),
                'balance' => 15000,
                'simulated_mode' => SandboxBankMode::NORMAL,
            ],
        );

        SpendingLimit::query()->firstOrCreate(
            ['student_card_id' => $card3->id],
            [
                'daily_limit' => 100000,
                'spent_today' => 0,
                'spent_date' => $todayVn->toDateString(),
            ],
        );

        $student4 = Student::query()->firstOrCreate(
            ['student_code' => 'HS-2026-0004'],
            [
                'user_id' => $parent2->id,
                'full_name' => 'Trần Bảo Ngọc',
                'school_name' => 'THCS Trần Phú',
                'class_name' => '9A1',
                'is_active' => true,
            ],
        );

        StudentCard::query()->firstOrCreate(
            ['card_code' => 'L003KK'],
            [
                'student_id' => $student4->id,
                'status' => StudentCardStatus::LOCKED,
                'issued_at' => $twoDaysAgo,
            ],
        );

        // 4. Historical orders from YESTERDAY (so today's statistics start at 0)
        $comSuon = Product::query()->where('name', 'Cơm sườn nướng mật ong')->first();
        $traDao = Product::query()->where('name', 'Trà đào cam sả')->first();
        $banhMi = Product::query()->where('name', 'Bánh mì thịt nguội đặc biệt')->first();

        $onlineOrder = null;
        $posOrder = null;
        $txnOnline = null;
        $txnPos = null;
        $txnTimeout = null;

        if ($comSuon && $traDao && $banhMi) {
            $onlineOrder = Order::query()->where('code', 'SC-DEMO-000001')->first();
            if (! $onlineOrder) {
                $onlineTotal = $comSuon->price + $traDao->price;
                $onlineOrder = new Order([
                    'user_id' => $parent1->id,
                    'student_card_id' => $card1->id,
                    'code' => 'SC-DEMO-000001',
                    'status' => OrderStatus::COMPLETED,
                    'payment_method' => PaymentMethod::STUDENT_CARD,
                    'payment_status' => PaymentStatus::PAID,
                    'store_name' => 'Căn tin THCS Trần Phú',
                    'total_amount' => $onlineTotal,
                    'discount_amount' => 0,
                    'remaining_limit_snapshot' => 30000,
                    'payment_reference' => 'TXN-DEMO-ONLINE-001',
                    'note' => 'Suất trưa ngày hôm qua (đã nhận)',
                    'idempotency_key' => 'demo-seed-online-order-001',
                    'payload_hash' => hash('sha256', 'demo-seed-online-order-001'),
                    'version' => 4,
                ]);
                $onlineOrder->created_at = $yesterdayNoon;
                $onlineOrder->updated_at = $yesterdayNoon;
                $onlineOrder->save();

                OrderItem::query()->create([
                    'order_id' => $onlineOrder->id,
                    'product_id' => $comSuon->id,
                    'quantity' => 1,
                    'unit_price' => $comSuon->price,
                    'subtotal' => $comSuon->price,
                ]);
                OrderItem::query()->create([
                    'order_id' => $onlineOrder->id,
                    'product_id' => $traDao->id,
                    'quantity' => 1,
                    'unit_price' => $traDao->price,
                    'subtotal' => $traDao->price,
                ]);

                if ($staff) {
                    foreach ([
                        [OrderStatus::PENDING, OrderStatus::PREPARING, $yesterdayNoon->copy()->addMinutes(2)],
                        [OrderStatus::PREPARING, OrderStatus::READY, $yesterdayNoon->copy()->addMinutes(8)],
                        [OrderStatus::READY, OrderStatus::COMPLETED, $yesterdayNoon->copy()->addMinutes(12)],
                    ] as [$from, $to, $ts]) {
                        $log = new OrderStatusLog([
                            'order_id' => $onlineOrder->id,
                            'from_status' => $from,
                            'to_status' => $to,
                            'changed_by' => $staff->id,
                        ]);
                        $log->created_at = $ts;
                        $log->updated_at = $ts;
                        $log->save();
                    }
                }
            }

            $posOrder = Order::query()->where('code', 'POS-DEMO-000001')->first();
            if (! $posOrder) {
                $posOrder = new Order([
                    'user_id' => $parent1->id,
                    'student_card_id' => $card1->id,
                    'code' => 'POS-DEMO-000001',
                    'status' => OrderStatus::COMPLETED,
                    'payment_method' => PaymentMethod::STUDENT_CARD_POS,
                    'payment_status' => PaymentStatus::PAID,
                    'store_name' => 'Quầy Căn tin số 1 — THCS Trần Phú',
                    'total_amount' => $banhMi->price,
                    'discount_amount' => 0,
                    'remaining_limit_snapshot' => 5000,
                    'payment_reference' => 'TXN-DEMO-POS-001',
                    'note' => 'Quẹt thẻ học sinh giờ ra chơi chiều',
                    'idempotency_key' => 'demo-seed-pos-order-001',
                    'payload_hash' => hash('sha256', 'demo-seed-pos-order-001'),
                    'version' => 1,
                ]);
                $posOrder->created_at = $yesterdayAfternoon;
                $posOrder->updated_at = $yesterdayAfternoon;
                $posOrder->save();

                OrderItem::query()->create([
                    'order_id' => $posOrder->id,
                    'product_id' => $banhMi->id,
                    'quantity' => 1,
                    'unit_price' => $banhMi->price,
                    'subtotal' => $banhMi->price,
                ]);
            }

            // 5. Immutable Payment Ledger entries (SUCCEEDED + 1 TIMEOUT for Reconciliation Demo)
            $txnOnline = PaymentTransaction::query()->firstOrCreate(
                ['gateway_txn_ref' => 'TXN-DEMO-ONLINE-001'],
                [
                    'order_id' => $onlineOrder->id,
                    'student_card_id' => $card1->id,
                    'bank_link_id' => $bankLink1->id,
                    'user_id' => $parent1->id,
                    'provider' => 'sandbox_epay',
                    'type' => 'CHARGE',
                    'amount' => $onlineOrder->total_amount,
                    'status' => 'SUCCEEDED',
                    'idempotency_key' => 'demo-seed-online-order-001',
                    'provider_reference' => $bankLink1->provider_reference,
                    'response_meta' => ['seeded_demo' => true, 'channel' => 'MINIAPP'],
                    'processed_at' => $yesterdayNoon,
                    'created_at' => $yesterdayNoon,
                    'updated_at' => $yesterdayNoon,
                ],
            );

            $txnPos = PaymentTransaction::query()->firstOrCreate(
                ['gateway_txn_ref' => 'TXN-DEMO-POS-001'],
                [
                    'order_id' => $posOrder->id,
                    'student_card_id' => $card1->id,
                    'bank_link_id' => $bankLink1->id,
                    'user_id' => $parent1->id,
                    'provider' => 'sandbox_epay',
                    'type' => 'CHARGE',
                    'amount' => $posOrder->total_amount,
                    'status' => 'SUCCEEDED',
                    'idempotency_key' => 'demo-seed-pos-order-001',
                    'provider_reference' => $bankLink1->provider_reference,
                    'response_meta' => ['seeded_demo' => true, 'channel' => 'POS'],
                    'processed_at' => $yesterdayAfternoon,
                    'created_at' => $yesterdayAfternoon,
                    'updated_at' => $yesterdayAfternoon,
                ],
            );

            $txnTimeout = PaymentTransaction::query()->firstOrCreate(
                ['gateway_txn_ref' => 'TXN-DEMO-TIMEOUT-001'],
                [
                    'order_id' => null,
                    'student_card_id' => $card1->id,
                    'bank_link_id' => $bankLink1->id,
                    'user_id' => $parent1->id,
                    'provider' => 'sandbox_epay',
                    'type' => 'CHARGE',
                    'amount' => 45000,
                    'status' => 'TIMEOUT',
                    'failure_code' => 'GATEWAY_TIMEOUT',
                    'failure_message' => 'Cổng ngân hàng phản hồi quá thời gian chờ — cần đối soát.',
                    'idempotency_key' => 'demo-seed-timeout-001',
                    'provider_reference' => $bankLink1->provider_reference,
                    'response_meta' => ['seeded_demo' => true],
                    'processed_at' => $yesterdayAfternoon->copy()->addMinutes(20),
                    'created_at' => $yesterdayAfternoon->copy()->addMinutes(20),
                    'updated_at' => $yesterdayAfternoon->copy()->addMinutes(20),
                ],
            );

            // 6. Yesterday's Reconciliation Batch (2 MATCHED + 1 STATUS_MISMATCH ready for Admin Resolve demo)
            $batch = ReconciliationBatch::query()->firstOrCreate(
                [
                    'reconciliation_date' => $yesterdayDate,
                    'provider' => 'sandbox_epay',
                ],
                [
                    'status' => 'HAS_DISCREPANCIES',
                    'total_local_count' => 3,
                    'total_provider_count' => 2,
                    'matched_count' => 2,
                    'discrepancy_count' => 1,
                    'total_local_amount' => $onlineOrder->total_amount + $posOrder->total_amount + 45000,
                    'total_provider_amount' => $onlineOrder->total_amount + $posOrder->total_amount,
                    'run_by' => $admin?->id,
                    'completed_at' => $yesterdayAfternoon->copy()->addHours(2),
                ],
            );

            if (! ReconciliationItem::query()->where('batch_id', $batch->id)->exists()) {
                ReconciliationItem::query()->create([
                    'batch_id' => $batch->id,
                    'payment_transaction_id' => $txnOnline->id,
                    'provider_reference' => $txnOnline->gateway_txn_ref,
                    'order_code' => $onlineOrder->code,
                    'local_amount' => $onlineOrder->total_amount,
                    'provider_amount' => $onlineOrder->total_amount,
                    'status' => 'MATCHED',
                    'note' => 'Khớp giao dịch thanh toán đơn Online.',
                ]);

                ReconciliationItem::query()->create([
                    'batch_id' => $batch->id,
                    'payment_transaction_id' => $txnPos->id,
                    'provider_reference' => $txnPos->gateway_txn_ref,
                    'order_code' => $posOrder->code,
                    'local_amount' => $posOrder->total_amount,
                    'provider_amount' => $posOrder->total_amount,
                    'status' => 'MATCHED',
                    'note' => 'Khớp giao dịch quẹt thẻ POS tại quầy.',
                ]);

                ReconciliationItem::query()->create([
                    'batch_id' => $batch->id,
                    'payment_transaction_id' => $txnTimeout->id,
                    'provider_reference' => $txnTimeout->gateway_txn_ref,
                    'order_code' => null,
                    'local_amount' => 45000,
                    'provider_amount' => 0,
                    'status' => 'STATUS_MISMATCH',
                    'note' => 'Giao dịch TIMEOUT cục bộ — ngân hàng chưa ghi nhận trừ tiền, cần Admin xác nhận đóng hồ sơ.',
                ]);
            }
        }

        // 7. In-App Notifications (2 unread + 1 read for Parent 1; 1 unread for Student 1)
        if (! CanteenNotification::query()->where('user_id', $parent1->id)->where('push_reference', 'DEMO-PUSH-001')->exists()) {
            CanteenNotification::query()->create([
                'user_id' => $parent1->id,
                'type' => NotificationType::BANK_LINK_CHANGED,
                'severity' => NotificationSeverity::SUCCESS,
                'title' => 'Liên kết ngân hàng thành công',
                'body' => 'Thẻ F156BA (Titkul Student) đã liên kết thành công với Vietcombank (****5678).',
                'action_url' => '/canteen',
                'meta' => ['card_code' => 'F156BA', 'bank_code' => 'VCB'],
                'push_channel' => 'log',
                'push_status' => 'SIMULATED',
                'push_reference' => 'DEMO-PUSH-001',
                'read_at' => $twoDaysAgo->copy()->addMinutes(5),
                'created_at' => $twoDaysAgo,
                'updated_at' => $twoDaysAgo,
            ]);

            CanteenNotification::query()->create([
                'user_id' => $parent1->id,
                'type' => NotificationType::SPENDING_LIMIT_UPDATED,
                'severity' => NotificationSeverity::INFO,
                'title' => 'Cập nhật hạn mức chi tiêu ngày',
                'body' => 'Hạn mức thẻ F156BA (Titkul Student) đã được tăng từ 50.000 ₫ lên 100.000 ₫/ngày.',
                'action_url' => '/canteen/limit-history',
                'meta' => ['card_code' => 'F156BA', 'previous_limit' => 50000, 'new_limit' => 100000],
                'push_channel' => 'log',
                'push_status' => 'SIMULATED',
                'push_reference' => 'DEMO-PUSH-002',
                'read_at' => null,
                'created_at' => $yesterdayMorning,
                'updated_at' => $yesterdayMorning,
            ]);

            CanteenNotification::query()->create([
                'user_id' => $parent1->id,
                'type' => NotificationType::PURCHASE_SUCCESS,
                'severity' => NotificationSeverity::SUCCESS,
                'title' => 'Giao dịch thẻ căn tin thành công',
                'body' => 'Thẻ F156BA vừa thanh toán 25.000 ₫ tại Quầy Căn tin số 1 (mã POS-DEMO-000001).',
                'action_url' => $posOrder ? '/orders/'.$posOrder->id : '/orders',
                'meta' => ['card_code' => 'F156BA', 'order_code' => 'POS-DEMO-000001', 'amount' => 25000],
                'push_channel' => 'log',
                'push_status' => 'SIMULATED',
                'push_reference' => 'DEMO-PUSH-003',
                'read_at' => null,
                'created_at' => $yesterdayAfternoon,
                'updated_at' => $yesterdayAfternoon,
            ]);
        }

        if ($student1User && ! CanteenNotification::query()->where('user_id', $student1User->id)->where('push_reference', 'DEMO-PUSH-STU-001')->exists()) {
            CanteenNotification::query()->create([
                'user_id' => $student1User->id,
                'type' => NotificationType::SPENDING_LIMIT_UPDATED,
                'severity' => NotificationSeverity::INFO,
                'title' => 'Phụ huynh đã cập nhật hạn mức thẻ',
                'body' => 'Hạn mức chi tiêu hằng ngày cho thẻ F156BA của bạn hiện là 100.000 ₫/ngày.',
                'action_url' => '/canteen',
                'meta' => ['card_code' => 'F156BA', 'new_limit' => 100000],
                'push_channel' => 'log',
                'push_status' => 'SIMULATED',
                'push_reference' => 'DEMO-PUSH-STU-001',
                'read_at' => null,
                'created_at' => $yesterdayMorning,
                'updated_at' => $yesterdayMorning,
            ]);
        }
    }
}
